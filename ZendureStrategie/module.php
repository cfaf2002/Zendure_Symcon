<?php

declare(strict_types=1);

/**
 * Zendure Speicherstrategie
 *
 * Wettergeführte Steuerung des SolarFlow Hubs: Die PV-Prognose (Open-Meteo, geneigte Modulfläche) für den
 * nächsten Sonnentag bestimmt die Entladegrenze. Wenig Sonne in Sicht → Reserve im Akku halten, viel Sonne →
 * Akku nachts weit nutzen. Bei Kälte bleibt eine Frostreserve, nachts wird die Ausgangsleistung auf Wunsch so
 * verteilt, dass die nutzbare Energie bis zum Sonnenaufgang reicht.
 *
 * Copyright (c) 2026 Armin Frohwerk · MIT-Lizenz
 * SPDX-License-Identifier: MIT
 */
class ZendureSpeicherstrategie extends IPSModuleStrict
{
    private const HUB_GUID = '{EEA9BD1E-4878-4A2D-9165-F23C8A4BA200}';
    private const LOCATION_GUID = '{45E97A63-F870-408A-B259-2933F7EABF74}';
    private const FORECAST_URL = 'https://api.open-meteo.com/v1/forecast';

    private const MAX_FORECAST_AGE = 6 * 3600;  // ältere Prognose gilt als nicht vorhanden
    private const RESEND_AFTER = 600;           // gleichen Sollwert frühestens nach 10 min erneut senden
    private const OUTPUT_HYSTERESIS = 20;       // W – kleinere Änderungen der Ausgangsleistung nicht senden
    private const RATIO_LOW = 0.25;             // Nachladung ≤ 25 % der Kapazität = Sparbetrieb
    private const RATIO_HIGH = 1.0;             // Nachladung ≥ 100 % = Akku wird sicher wieder voll

    // Betriebsarten (Variable „Betriebsart“)
    private const MODE_OFF = 0;
    private const MODE_SUNNY = 1;
    private const MODE_BALANCED = 2;
    private const MODE_SAVE = 3;
    private const MODE_FROST = 4;
    private const MODE_PAUSED = 5;
    private const MODE_NO_FORECAST = 6;
    private const MODE_NO_HUB = 7;

    public function Create(): void
    {
        parent::Create();

        // Hub und Akku
        $this->RegisterPropertyInteger('HubInstance', 0);
        $this->RegisterPropertyFloat('PackCapacity', 1.92);       // kWh je Akku (AB2000 = 1,92)

        // Module am Hub (zwei Flächen, z. B. PV 2 und PV 4)
        $this->RegisterPropertyInteger('Peak1', 0);               // Wp
        $this->RegisterPropertyInteger('Tilt1', 30);              // ° (0 = waagerecht)
        $this->RegisterPropertyInteger('Azimuth1', 0);            // ° (0 = Süd, −90 = Ost, 90 = West)
        $this->RegisterPropertyInteger('Peak2', 0);
        $this->RegisterPropertyInteger('Tilt2', 30);
        $this->RegisterPropertyInteger('Azimuth2', 0);
        $this->RegisterPropertyInteger('PerformanceRatio', 85);   // % (Verluste durch Wärme, Kabel, Hub, Laden)
        $this->RegisterPropertyBoolean('UseSymconLocation', true);
        $this->RegisterPropertyString('Location', '{"latitude":0,"longitude":0}');

        // Strategie
        $this->RegisterPropertyInteger('BaseLoad', 150);          // W Grundlast des Hauses
        $this->RegisterPropertyInteger('MinSocSunny', 10);        // % bei guter Prognose
        $this->RegisterPropertyInteger('MinSocDull', 30);         // % bei schlechter Prognose
        $this->RegisterPropertyBoolean('UseFrost', true);
        $this->RegisterPropertyFloat('FrostTemperature', 3.0);    // °C Akkutemperatur
        $this->RegisterPropertyInteger('FrostMinSoc', 40);        // %

        // Ausgangsleistung
        $this->RegisterPropertyBoolean('ControlOutput', false);
        $this->RegisterPropertyInteger('OutputMethod', 0);        // 0 = Ausgangsleistung (Limit), 1 = Entladeleistung vorgeben
        $this->RegisterPropertyInteger('MinOutput', 100);         // W

        // Freigabe (z. B. zentraler Urlaubs-/Automatikschalter)
        $this->RegisterPropertyInteger('ReleaseVariable', 0);
        $this->RegisterPropertyInteger('ReleaseMode', 0);         // 0 = läuft bei An, 1 = läuft bei Aus

        $this->RegisterPropertyInteger('EvaluateInterval', 5);    // min

        // Prognose-Check: täglicher Vergleich Prognose ↔ Ertrag des Hubs
        $this->RegisterPropertyInteger('CheckMode', 1);           // 0 = aus, 1 = Hinweis, 2 = automatisch korrigieren
        $this->RegisterPropertyInteger('NotifyInstance', 0);      // Kachel-Visualisierung für Push-Hinweise (optional)

        // Kachel
        $this->RegisterPropertyInteger('TileTheme', 0);           // 0 = Symcon-Design, 1 = Dunkel, 2 = Hell
        $this->RegisterPropertyString('TileBackground', 'aurora'); // aurora | none

        $this->RegisterAttributeString('WatchedVariables', '[]');
        $this->RegisterAttributeBoolean('Initialized', false);
        $this->RegisterAttributeString('CheckHistory', '[]');     // [{d, f (kWh bei 100 % Wirkungsgrad), a (kWh Ertrag)}]
        $this->RegisterAttributeString('CheckPending', '{}');     // {done, date, f} – Prognose für morgen, wie die Strategie sie nutzt
        $this->RegisterAttributeFloat('Correction', 1.0);         // gelernter Korrekturfaktor (nur bei „automatisch korrigieren“)
        $this->RegisterAttributeString('CheckNotified', '{}');    // {pr, time} – zuletzt gemeldete Empfehlung

        $this->RegisterTimer('Evaluate', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], \'Evaluate\', 0);');
        $this->RegisterTimer('Forecast', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], \'Forecast\', 0);');

        $this->RegisterMessage(0, IPS_KERNELSTARTED);

        // Eigene Kachel in der Kachel-Visualisierung (HTML-SDK)
        $this->SetVisualizationType(1);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->RegisterVariableBoolean('Active', 'Automatik', ['PRESENTATION' => VARIABLE_PRESENTATION_SWITCH, 'ICON' => 'wand-magic-sparkles'], 0);
        $this->EnableAction('Active');
        // MaintainVariable statt RegisterVariable…, damit auch bestehende Variablen die aktuelle Darstellung bekommen
        $this->MaintainVariable('Mode', 'Betriebsart', VARIABLETYPE_INTEGER, $this->Presentation('mode'), 1, true);
        $this->RegisterVariableString('Reason', 'Begründung', $this->Presentation('text'), 2);
        $this->RegisterVariableFloat('ForecastToday', 'PV-Prognose Hub heute', $this->Presentation('kwh'), 10);
        $this->RegisterVariableFloat('ForecastTomorrow', 'PV-Prognose Hub morgen', $this->Presentation('kwh'), 11);
        $this->RegisterVariableInteger('Refill', 'Nachladung nächster Sonnentag', $this->Presentation('refill'), 12);
        $this->RegisterVariableInteger('TargetMinSoc', 'Ziel-Entladegrenze', $this->Presentation('soc'), 20);
        $this->RegisterVariableInteger('TargetOutput', 'Ziel-Ausgangsleistung', $this->Presentation('watt'), 21);
        $this->RegisterVariableInteger('ForecastTime', 'Prognose vom', $this->Presentation('datetime'), 30);
        $check = $this->ReadPropertyInteger('CheckMode') > 0;
        $this->MaintainVariable('CheckDeviation', 'Prognose-Abweichung (14 Tage)', VARIABLETYPE_INTEGER, $this->Presentation('deviation'), 40, $check);
        $this->MaintainVariable('CheckHint', 'Prognose-Check', VARIABLETYPE_STRING, $this->Presentation('check'), 41, $check);
        if ($check && $this->GetValue('CheckHint') === '') {
            $this->CheckEvaluate(json_decode($this->ReadAttributeString('CheckHistory'), true) ?: []);
        }

        // Neue Instanz: Automatik einschalten (danach entscheidet der Nutzer)
        if (!$this->ReadAttributeBoolean('Initialized')) {
            $this->SetValue('Active', true);
            $this->WriteAttributeBoolean('Initialized', true);
        }

        // Referenzen und Beobachtung der Freigabe-Variable
        foreach ($this->GetReferenceList() as $ref) {
            $this->UnregisterReference($ref);
        }
        foreach (json_decode($this->ReadAttributeString('WatchedVariables'), true) ?: [] as $vid) {
            $this->UnregisterMessage((int) $vid, VM_UPDATE);
        }
        $watched = [];
        $hub = $this->ReadPropertyInteger('HubInstance');
        if ($hub > 0 && @IPS_InstanceExists($hub)) {
            $this->RegisterReference($hub);
        }
        $release = $this->ReadPropertyInteger('ReleaseVariable');
        if ($release > 0 && @IPS_VariableExists($release)) {
            $this->RegisterReference($release);
            $this->RegisterMessage($release, VM_UPDATE);
            $watched[] = $release;
        }
        $this->WriteAttributeString('WatchedVariables', (string) json_encode($watched));

        $this->SetBuffer('TileHash', '');
        $this->SetBuffer('Sent', '{}');

        if ($this->HubID() === 0) {
            $this->SetTimerInterval('Evaluate', 0);
            $this->SetTimerInterval('Forecast', 0);
            $this->SetStatus(104);
            $this->SetSummary('kein Hub gewählt');
            return;
        }
        if ($this->ReadPropertyInteger('Peak1') + $this->ReadPropertyInteger('Peak2') <= 0) {
            $this->SetTimerInterval('Evaluate', 0);
            $this->SetTimerInterval('Forecast', 0);
            $this->SetStatus(201);
            $this->SetSummary('Modulleistung fehlt');
            return;
        }

        $this->SetStatus(102);
        $this->SetSummary(IPS_GetName($this->HubID()));
        $this->SetTimerInterval('Evaluate', max(1, min(60, $this->ReadPropertyInteger('EvaluateInterval'))) * 60 * 1000);
        if (IPS_GetKernelRunlevel() === KR_READY) {
            // Einstellungen geändert → Prognose neu rechnen, aber nicht „Übernehmen“ blockieren
            $this->SetBuffer('Forecast', '');
            $this->SetTimerInterval('Forecast', 1000);
        } else {
            $this->SetTimerInterval('Forecast', 3600 * 1000);
        }
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->ApplyChanges();
            return;
        }
        if ($Message === VM_UPDATE && $SenderID === $this->ReadPropertyInteger('ReleaseVariable')) {
            // nur echte Änderungen der Freigabe auswerten
            if (($Data[1] ?? true) === true) {
                $this->Evaluate();
            }
        }
    }

    // ---------------------------------------------------------------------
    // Öffentliche Funktionen
    // ---------------------------------------------------------------------

    /** Holt die PV-Prognose neu und wertet sofort aus. */
    public function Update(): bool
    {
        $ok = $this->FetchForecast();
        $this->Evaluate();
        return $ok;
    }

    /** Wertet mit der vorhandenen Prognose aus und schreibt bei Bedarf Entladegrenze und Ausgangsleistung zum Hub. */
    public function Evaluate(): bool
    {
        $this->CheckRun(time());
        return (bool) $this->EvaluateRun(time());
    }

    /** Prognose-Check zurücksetzen (z. B. nach Umbau der Module). */
    public function ResetCheck(): void
    {
        $this->WriteAttributeString('CheckHistory', '[]');
        $this->WriteAttributeString('CheckPending', '{}');
        $this->WriteAttributeString('CheckNotified', '{}');
        $this->WriteAttributeFloat('Correction', 1.0);
        $this->CheckEvaluate([]);
        $this->SetBuffer('Forecast', '');
        $this->SetTimerInterval('Forecast', 1000);
    }

    /** Stündliche PV-Prognose des Hubs als JSON (Zeitpunkt => kW), dazu Sonnenauf- und -untergänge. */
    public function GetForecast(): string
    {
        $data = $this->GetBuffer('Forecast');
        return $data !== '' ? $data : '{}';
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
            case 'Active':
                $this->SetValue('Active', (bool) $Value);
                $this->SetBuffer('Sent', '{}');
                $this->Evaluate();
                return;
            case 'Evaluate':
                $this->Evaluate();
                return;
            case 'Forecast':
                $this->SetTimerInterval('Forecast', 3600 * 1000);
                $this->Update();
                return;
            case 'ResetCheck':
                $this->ResetCheck();
                echo 'Prognose-Check zurückgesetzt.';
                return;
            case 'Refresh':
                $this->Update();
                echo $this->GetValue('Reason');
                return;
        }
        throw new Exception('Unbekannte Aktion: ' . $Ident);
    }

    // ---------------------------------------------------------------------
    // Strategie
    // ---------------------------------------------------------------------

    /**
     * Kern der Auswertung. Liefert true, wenn Sollwerte berechnet wurden (auch wenn nichts gesendet werden musste).
     */
    private function EvaluateRun(int $now): bool
    {
        $hub = $this->HubID();
        if ($hub === 0) {
            $this->Report(self::MODE_NO_HUB, 'Kein Zendure-Hub gewählt.');
            return false;
        }
        if (!$this->GetValue('Active')) {
            $this->Report(self::MODE_OFF, 'Automatik ist ausgeschaltet – Einstellungen am Hub bleiben, wie sie sind.');
            return false;
        }
        if (!$this->Released()) {
            $this->Report(self::MODE_PAUSED, 'Pausiert über die Freigabe-Variable – Einstellungen am Hub bleiben, wie sie sind.');
            return false;
        }

        $soc = $this->HubValue('electricLevel');
        if ($soc === null) {
            $this->Report(self::MODE_NO_HUB, 'Der Hub hat noch keinen Ladezustand gemeldet.');
            return false;
        }

        $forecast = json_decode($this->GetBuffer('Forecast'), true);
        if (!is_array($forecast) || ($now - (int) ($forecast['time'] ?? 0)) > self::MAX_FORECAST_AGE || count($forecast['sunrise'] ?? []) < 2) {
            $this->Report(self::MODE_NO_FORECAST, 'Keine aktuelle PV-Prognose – Einstellungen am Hub bleiben, wie sie sind.');
            return false;
        }

        $plan = $this->Plan($forecast, $now, (float) $soc, $this->HubValue('BatteryTemperature'), $this->Capacity());

        $this->SetValueIfChanged('Refill', (int) round($plan['ratio'] * 100));
        $this->SetValueIfChanged('TargetMinSoc', $plan['minSoc']);
        $this->SetValueIfChanged('TargetOutput', $plan['output'] ?? 0);

        $this->Send('minSoc', $plan['minSoc'], 0);
        if ($plan['output'] !== null) {
            $ident = $this->ReadPropertyInteger('OutputMethod') === 1 ? 'DischargePower' : 'outputLimit';
            $this->Send($ident, $plan['output'], self::OUTPUT_HYSTERESIS);
        }

        $this->Report($plan['mode'], $plan['reason']);
        return true;
    }

    /**
     * Berechnet Entladegrenze, Ausgangsleistung und Begründung – ohne Seiteneffekte (für Tests direkt aufrufbar).
     *
     * @return array{mode:int,minSoc:int,output:?int,ratio:float,refill:float,reason:string}
     */
    private function Plan(array $forecast, int $now, float $soc, ?float $temperature, float $capacity): array
    {
        $rise = array_map('intval', $forecast['sunrise']);
        $set = array_map('intval', $forecast['sunset']);

        // Nächster Sonnentag: heute, solange die Sonne noch nicht aufgegangen ist, sonst morgen
        $day = $now < $rise[0] ? 0 : 1;
        $refill = $this->Chargeable($forecast, $day);
        $ratio = $capacity > 0 ? $refill / $capacity : 0.0;

        $sunny = $this->Clamp($this->ReadPropertyInteger('MinSocSunny'), 0, 50);
        $dull = $this->Clamp($this->ReadPropertyInteger('MinSocDull'), 0, 50);
        $t = max(0.0, min(1.0, ($ratio - self::RATIO_LOW) / (self::RATIO_HIGH - self::RATIO_LOW)));
        $minSoc = (int) (round(($dull + ($sunny - $dull) * $t) / 5) * 5);

        if ($ratio >= self::RATIO_HIGH) {
            $mode = self::MODE_SUNNY;
        } elseif ($ratio <= self::RATIO_LOW) {
            $mode = self::MODE_SAVE;
        } else {
            $mode = self::MODE_BALANCED;
        }

        $dayName = $day === 0 ? 'Heute' : 'Morgen';
        $reason = sprintf('%s kann der Hub voraussichtlich %s kWh in den Akku laden (%d %% von %s kWh).',
            $dayName, $this->Num($refill, 1), (int) round($ratio * 100), $this->Num($capacity, 2));

        $frost = $this->ReadPropertyBoolean('UseFrost') && $temperature !== null
            && $temperature < $this->ReadPropertyFloat('FrostTemperature');
        if ($frost) {
            $frostSoc = $this->Clamp($this->ReadPropertyInteger('FrostMinSoc'), 0, 50);
            if ($frostSoc > $minSoc) {
                $minSoc = (int) (round($frostSoc / 5) * 5);
                $mode = self::MODE_FROST;
            }
            $reason .= sprintf(' Akku hat %s °C – Frostreserve aktiv.', $this->Num($temperature, 1));
        }
        $minSoc = $this->Clamp($minSoc, 0, 50);
        $reason .= sprintf(' Entladegrenze %d %%.', $minSoc);

        // Ausgangsleistung
        $output = null;
        if ($this->ReadPropertyBoolean('ControlOutput')) {
            $base = max(0, $this->ReadPropertyInteger('BaseLoad'));
            $min = max(0, min($base, $this->ReadPropertyInteger('MinOutput')));
            $max = $this->HubMaxOutput();
            $night = $now >= $set[0] || $now < $rise[0];
            if ($night) {
                $nextRise = $now < $rise[0] ? $rise[0] : $rise[1];
                // eine Stunde nach Sonnenaufgang reicht die Sonne meist noch nicht für die Grundlast
                $hours = max(1.0, ($nextRise + 3600 - $now) / 3600);
                $usable = max(0.0, ($soc - $minSoc) / 100 * $capacity);
                $watt = $usable * 1000 / $hours;
                $output = (int) (round(max($min, min($base, $watt)) / 10) * 10);
                $reason .= sprintf(' Nachts %d W, damit %s kWh bis %s Uhr reichen.',
                    $output, $this->Num($usable, 2), date('H:i', $nextRise));
            } else {
                $output = (int) (round($base / 10) * 10);
                $reason .= sprintf(' Tagsüber %d W (Grundlast).', $output);
            }
            $output = min($output, $max);
        }

        return ['mode' => $mode, 'minSoc' => $minSoc, 'output' => $output, 'ratio' => $ratio, 'refill' => $refill, 'reason' => $reason];
    }

    /**
     * Energie, die am Tag $day voraussichtlich in den Akku geht. Regelt das Modul die Ausgangsleistung
     * (tagsüber Grundlast), geht die Grundlast zuerst ans Haus und nur der Überschuss zählt; sonst entscheidet
     * der Hub selbst und die ganze PV-Prognose gilt als Ladeenergie.
     */
    private function Chargeable(array $forecast, int $day): float
    {
        $rise = (int) ($forecast['sunrise'][$day] ?? 0);
        $set = (int) ($forecast['sunset'][$day] ?? 0);
        if ($rise <= 0 || $set <= $rise) {
            return 0.0;
        }
        $base = $this->ReadPropertyBoolean('ControlOutput') ? max(0, $this->ReadPropertyInteger('BaseLoad')) / 1000 : 0.0;
        $sum = 0.0;
        foreach ($forecast['hours'] ?? [] as $ts => $kw) {
            $ts = (int) $ts;
            // Stundenwert gilt für die vorangehende Stunde
            if ($ts > $rise && $ts <= $set + 3600) {
                $sum += max(0.0, (float) $kw - $base);
            }
        }
        return round($sum, 2);
    }

    /** Summe der PV-Prognose eines Tages (für die Variablen „heute“/„morgen“). */
    private function DayTotal(array $forecast, int $day): float
    {
        $rise = (int) ($forecast['sunrise'][$day] ?? 0);
        $set = (int) ($forecast['sunset'][$day] ?? 0);
        $sum = 0.0;
        foreach ($forecast['hours'] ?? [] as $ts => $kw) {
            if ((int) $ts > $rise && (int) $ts <= $set + 3600) {
                $sum += (float) $kw;
            }
        }
        return round($sum, 2);
    }

    // ---------------------------------------------------------------------
    // Prognose-Check
    // ---------------------------------------------------------------------

    /**
     * Eine Stunde nach Sonnenuntergang: Prognose des Tages (so, wie die Strategie sie am Vorabend genutzt hat)
     * mit dem Ertrag des Hubs vergleichen und in den Verlauf schreiben. Läuft auch bei ausgeschalteter Automatik.
     */
    private function CheckRun(int $now): void
    {
        if ($this->ReadPropertyInteger('CheckMode') === 0) {
            return;
        }
        $forecast = json_decode($this->GetBuffer('Forecast'), true);
        if (!is_array($forecast) || !isset($forecast['sunset'][0], $forecast['sunset'][1]) || $now < (int) $forecast['sunset'][0] + 3600) {
            return;
        }
        $today = date('Y-m-d', $now);
        $pending = json_decode($this->ReadAttributeString('CheckPending'), true) ?: [];
        if (($pending['done'] ?? '') === $today) {
            return;
        }
        $factor = max(0.01, (float) ($forecast['factor'] ?? 1.0));

        // Prognose auf 100 % Wirkungsgrad zurückrechnen – so bleibt der Verlauf gültig, wenn sich der Wirkungsgrad ändert
        $potential = ($pending['date'] ?? '') === $today ? (float) $pending['f'] : $this->DayTotal($forecast, 0) / $factor;
        $actual = $this->HubValue('SolarEnergyToday');

        $history = json_decode($this->ReadAttributeString('CheckHistory'), true) ?: [];
        // Kein Ertrag trotz Sonne (Schnee auf den Modulen, Hub offline …) verfälscht den Vergleich – Tag auslassen
        $expected = $potential * $factor;
        if ($actual !== null && !($expected >= 0.3 && $actual < $expected * 0.1)) {
            $history[] = ['d' => $today, 'f' => round($potential, 3), 'a' => round($actual, 2)];
            $history = array_slice($history, -14);
            $this->WriteAttributeString('CheckHistory', (string) json_encode($history));
        }
        $this->WriteAttributeString('CheckPending', (string) json_encode([
            'done' => $today,
            'date' => date('Y-m-d', (int) $forecast['sunset'][1]),
            'f'    => round($this->DayTotal($forecast, 1) / $factor, 3),
        ]));
        $this->CheckEvaluate($history);
    }

    /** Wertet den Verlauf aus: Abweichung, Hinweis und ggf. neuer Korrekturfaktor. */
    private function CheckEvaluate(array $history): void
    {
        $mode = $this->ReadPropertyInteger('CheckMode');
        if ($mode === 0) {
            return;
        }
        $result = $this->CheckResult($history, $this->ReadPropertyInteger('PerformanceRatio'));
        $this->SetValueIfChanged('CheckDeviation', $result['deviation']);

        if ($result['days'] < 5) {
            $this->SetValueIfChanged('CheckHint', sprintf('Prognose-Check sammelt noch Daten (%d von 5 Tagen mit Sonne).', $result['days']));
            return;
        }
        $base = sprintf('Letzte %d Tage: Ertrag %s kWh, Prognose %s kWh (%+d %%).',
            $result['days'], $this->Num($result['actual'], 1), $this->Num($result['forecast'], 1), $result['deviation']);

        if ($mode === 2) {
            $correction = max(0.5, min(1.5, round($result['ratio'] * $this->Correction(), 2)));
            if (abs($correction - $this->Correction()) >= 0.01) {
                $this->WriteAttributeFloat('Correction', $correction);
            }
            $this->SetValueIfChanged('CheckHint', $base . sprintf(' Automatisch korrigiert: Prognose × %s.', $this->Num($correction, 2)));
            return;
        }

        if (abs($result['deviation']) <= 10) {
            $this->SetValueIfChanged('CheckHint', $base . ' Die Prognose passt.');
            return;
        }
        $pr = $this->ReadPropertyInteger('PerformanceRatio');
        $hint = $base . sprintf(' Empfehlung: Systemwirkungsgrad von %d %% auf %d %% ändern.', $pr, $result['suggested']);
        $this->SetValueIfChanged('CheckHint', $hint);
        $this->Notify($result['suggested'], $hint);
    }

    /**
     * Rechnet aus dem Verlauf (nur Tage mit nennenswerter Prognose) Abweichung und passenden Wirkungsgrad.
     *
     * @return array{days:int,actual:float,forecast:float,ratio:float,deviation:int,suggested:int}
     */
    private function CheckResult(array $history, int $pr): array
    {
        $pr = max(10, min(100, $pr));
        $factor = $pr / 100 * $this->Correction();
        $sumA = 0.0;
        $sumP = 0.0;
        $days = 0;
        foreach ($history as $day) {
            $f = (float) ($day['f'] ?? 0) * $factor;
            if ($f < 0.3) {
                continue;   // trübe Tage sagen wenig über den Wirkungsgrad
            }
            $sumA += (float) ($day['a'] ?? 0);
            $sumP += (float) ($day['f'] ?? 0);
            $days++;
        }
        $forecast = $sumP * $factor;
        $ratio = $forecast > 0 ? $sumA / $forecast : 1.0;
        return [
            'days'      => $days,
            'actual'    => round($sumA, 2),
            'forecast'  => round($forecast, 2),
            'ratio'     => $ratio,
            'deviation' => $days > 0 ? (int) round(($sumA / max(0.01, $forecast) - 1) * 100) : 0,
            'suggested' => $sumP > 0 ? $this->Clamp((int) round($sumA / $sumP * 100), 40, 100) : $pr,
        ];
    }

    /** Hinweis ins Meldungsfenster und optional als Push über die Kachel-Visualisierung – höchstens alle 3 Tage. */
    private function Notify(int $suggested, string $text): void
    {
        // Erst melden, dann nur wieder, wenn sich die Empfehlung um mindestens 3 Punkte ändert (frühestens nach 3 Tagen)
        $last = json_decode($this->ReadAttributeString('CheckNotified'), true) ?: [];
        if (isset($last['pr'])) {
            $changed = abs((int) $last['pr'] - $suggested) >= 3;
            $waited = (time() - (int) ($last['time'] ?? 0)) >= 3 * 86400;
            if (!$changed || !$waited) {
                return;
            }
        }
        $this->WriteAttributeString('CheckNotified', (string) json_encode(['pr' => $suggested, 'time' => time()]));
        $this->LogMessage('Zendure Speicherstrategie: ' . $text, KL_NOTIFY);
        $visu = $this->ReadPropertyInteger('NotifyInstance');
        if ($visu > 0 && @IPS_InstanceExists($visu) && function_exists('VISU_PostNotification')) {
            try {
                VISU_PostNotification($visu, 'Speicherstrategie', $text, 'Information', $this->InstanceID);
            } catch (Throwable $e) {
                $this->SendDebug('Hinweis', $e->getMessage(), 0);
            }
        }
    }

    private function Correction(): float
    {
        return $this->ReadPropertyInteger('CheckMode') === 2 ? max(0.5, min(1.5, $this->ReadAttributeFloat('Correction'))) : 1.0;
    }

    // ---------------------------------------------------------------------
    // Prognose
    // ---------------------------------------------------------------------

    private function FetchForecast(): bool
    {
        $coords = $this->Coordinates();
        if ($coords === null) {
            $this->SendDebug('Prognose', 'kein Standort (Standort-Instanz von Symcon oder eigener Standort)', 0);
            return false;
        }
        $ratio = max(10, min(100, $this->ReadPropertyInteger('PerformanceRatio'))) / 100 * $this->Correction();
        $hours = [];
        $rise = [];
        $set = [];
        foreach ([1, 2] as $n) {
            $peak = max(0, $this->ReadPropertyInteger('Peak' . $n)) / 1000;  // kWp
            if ($peak <= 0) {
                continue;
            }
            $error = '';
            $json = $this->HttpGetJson($this->ForecastUrl($coords[0], $coords[1], $n), $error);
            if ($json === null) {
                $this->SendDebug('Prognose', 'Abruf fehlgeschlagen: ' . $error, 0);
                $this->LogMessage('Zendure Speicherstrategie: PV-Prognose nicht abrufbar (' . $error . ')', KL_WARNING);
                return false;
            }
            $time = $json['hourly']['time'] ?? [];
            $gti = $json['hourly']['global_tilted_irradiance'] ?? [];
            foreach ($time as $i => $ts) {
                // W/m² (Mittel der vorangehenden Stunde) → kW der Fläche bei STC × Systemwirkungsgrad
                $kw = max(0.0, (float) ($gti[$i] ?? 0)) / 1000 * $peak * $ratio;
                $hours[(int) $ts] = round(($hours[(int) $ts] ?? 0.0) + $kw, 3);
            }
            $rise = array_map('intval', $json['daily']['sunrise'] ?? []);
            $set = array_map('intval', $json['daily']['sunset'] ?? []);
        }
        if ($hours === [] || count($rise) < 2) {
            return false;
        }
        ksort($hours);
        $data = ['time' => time(), 'sunrise' => $rise, 'sunset' => $set, 'hours' => $hours, 'factor' => $ratio];
        $this->SetBuffer('Forecast', (string) json_encode($data));

        $this->SetValueIfChanged('ForecastToday', $this->DayTotal($data, 0));
        $this->SetValueIfChanged('ForecastTomorrow', $this->DayTotal($data, 1));
        $this->SetValueIfChanged('ForecastTime', $data['time']);
        return true;
    }

    private function ForecastUrl(float $lat, float $lon, int $n): string
    {
        return self::FORECAST_URL . '?' . http_build_query([
            'latitude'      => $lat,
            'longitude'     => $lon,
            'hourly'        => 'global_tilted_irradiance',
            'daily'         => 'sunrise,sunset',
            'tilt'          => $this->Clamp($this->ReadPropertyInteger('Tilt' . $n), 0, 90),
            'azimuth'       => $this->Clamp($this->ReadPropertyInteger('Azimuth' . $n), -180, 180),
            'timezone'      => 'auto',
            'timeformat'    => 'unixtime',
            'forecast_days' => 3,
        ]);
    }

    /** Ruft eine URL per HTTPS ab (Zertifikat wird geprüft, Zeitlimit) und liefert das JSON. */
    private function HttpGetJson(string $url, string &$error): ?array
    {
        $error = '';
        $this->SendDebug('Abruf', $url, 0);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_FOLLOWLOCATION  => true,
            CURLOPT_MAXREDIRS       => 3,
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER  => true,
            CURLOPT_SSL_VERIFYHOST  => 2,
            CURLOPT_MAXFILESIZE     => 2 * 1024 * 1024,
            CURLOPT_ENCODING        => '',
            CURLOPT_CONNECTTIMEOUT  => 10,
            CURLOPT_TIMEOUT         => 20,
            CURLOPT_HTTPHEADER      => ['Accept: application/json'],
            CURLOPT_USERAGENT       => 'IP-Symcon Zendure-Modul (github.com/cfaf2002/Zendure_Symcon)',
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($body === false) {
            $error = 'cURL ' . curl_errno($ch) . ': ' . curl_error($ch);
            return null;
        }
        $json = json_decode((string) $body, true);
        if ($code !== 200) {
            $error = 'HTTP ' . $code . (is_array($json) && isset($json['reason']) ? ' – ' . (string) $json['reason'] : '');
            return null;
        }
        if (!is_array($json) || !isset($json['hourly'], $json['daily'])) {
            $error = 'unerwartete Antwort';
            return null;
        }
        return $json;
    }

    /** @return array{0:float,1:float}|null */
    private function Coordinates(): ?array
    {
        if ($this->ReadPropertyBoolean('UseSymconLocation')) {
            try {
                $ids = IPS_GetInstanceListByModuleID(self::LOCATION_GUID);
                if (count($ids) > 0) {
                    $config = json_decode(IPS_GetConfiguration($ids[0]), true);
                    if (is_array($config) && isset($config['Location'])) {
                        $loc = is_array($config['Location']) ? $config['Location'] : json_decode((string) $config['Location'], true);
                        if (isset($loc['latitude'], $loc['longitude'])) {
                            return $this->ValidLocation((float) $loc['latitude'], (float) $loc['longitude']);
                        }
                    }
                }
            } catch (Throwable $e) {
                $this->SendDebug('Standort', $e->getMessage(), 0);
            }
            return null;
        }
        $loc = json_decode($this->ReadPropertyString('Location'), true);
        if (!is_array($loc) || !isset($loc['latitude'], $loc['longitude'])) {
            return null;
        }
        return $this->ValidLocation((float) $loc['latitude'], (float) $loc['longitude']);
    }

    /** @return array{0:float,1:float}|null */
    private function ValidLocation(float $lat, float $lon): ?array
    {
        if (($lat == 0.0 && $lon == 0.0) || abs($lat) > 90 || abs($lon) > 180) {
            return null;
        }
        return [round($lat, 4), round($lon, 4)];
    }

    // ---------------------------------------------------------------------
    // Hub
    // ---------------------------------------------------------------------

    private function HubID(): int
    {
        $id = $this->ReadPropertyInteger('HubInstance');
        if ($id <= 0 || !@IPS_InstanceExists($id)) {
            return 0;
        }
        return IPS_GetInstance($id)['ModuleInfo']['ModuleID'] === self::HUB_GUID ? $id : 0;
    }

    private function HubVariable(string $ident): int
    {
        $hub = $this->HubID();
        if ($hub === 0) {
            return 0;
        }
        $id = @IPS_GetObjectIDByIdent($ident, $hub);
        return ($id !== false && $id > 0) ? (int) $id : 0;
    }

    private function HubValue(string $ident): ?float
    {
        $vid = $this->HubVariable($ident);
        return $vid > 0 ? (float) GetValue($vid) : null;
    }

    private function HubMaxOutput(): int
    {
        $hub = $this->HubID();
        $max = $hub > 0 ? (int) @IPS_GetProperty($hub, 'MaxOutputPower') : 0;
        return $max > 0 ? $max : 1200;
    }

    private function Capacity(): float
    {
        $packs = (int) ($this->HubValue('packNum') ?? 0);
        return max(1, $packs) * max(0.1, $this->ReadPropertyFloat('PackCapacity'));
    }

    /**
     * Schreibt einen Sollwert über die Aktion der Hub-Variable – nur wenn er abweicht und nicht gerade erst
     * gesendet wurde (der Hub bestätigt erst mit der nächsten Meldung).
     */
    private function Send(string $ident, int $value, int $hysteresis): void
    {
        $vid = $this->HubVariable($ident);
        if ($vid === 0) {
            $this->SendDebug('Hub', 'Variable „' . $ident . '“ fehlt am Hub – wurde vom Gerät noch nicht gemeldet', 0);
            return;
        }
        $current = (int) GetValue($vid);
        if ($current === $value || abs($current - $value) < $hysteresis) {
            return;
        }
        $sent = json_decode($this->GetBuffer('Sent'), true) ?: [];
        $last = $sent[$ident] ?? null;
        if (is_array($last) && (int) $last[0] === $value && (time() - (int) $last[1]) < self::RESEND_AFTER) {
            return;
        }
        try {
            RequestAction($vid, $value);
            $this->SendDebug('Hub', $ident . ' = ' . $value . ' (vorher ' . $current . ')', 0);
            $sent[$ident] = [$value, time()];
            $this->SetBuffer('Sent', (string) json_encode($sent));
        } catch (Throwable $e) {
            $this->SendDebug('Hub', $ident . ': ' . $e->getMessage(), 0);
        }
    }

    private function Released(): bool
    {
        $vid = $this->ReadPropertyInteger('ReleaseVariable');
        if ($vid <= 0 || !@IPS_VariableExists($vid)) {
            return true;
        }
        $on = (bool) GetValue($vid);
        return $this->ReadPropertyInteger('ReleaseMode') === 1 ? !$on : $on;
    }

    // ---------------------------------------------------------------------
    // Anzeige
    // ---------------------------------------------------------------------

    private function Report(int $mode, string $reason): void
    {
        $this->SetValueIfChanged('Mode', $mode);
        $this->SetValueIfChanged('Reason', $reason);
        if ($mode === self::MODE_OFF || $mode === self::MODE_PAUSED || $mode === self::MODE_NO_FORECAST || $mode === self::MODE_NO_HUB) {
            $this->SetValueIfChanged('TargetOutput', 0);
        }
        $this->UpdateTile();
    }

    public function GetVisualizationTile(): string
    {
        $html = (string) file_get_contents(__DIR__ . '/tile.html');
        $state = (string) json_encode($this->TileState(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        return str_replace('__INITIAL_STATE__', $state, $html);
    }

    private function UpdateTile(): void
    {
        $data = (string) json_encode($this->TileState());
        $hash = md5($data);
        if ($this->GetBuffer('TileHash') === $hash) {
            return;
        }
        $this->SetBuffer('TileHash', $hash);
        $this->UpdateVisualizationValue($data);
    }

    private function TileState(): array
    {
        $forecast = json_decode($this->GetBuffer('Forecast'), true);
        $bars = [];
        if (is_array($forecast)) {
            $now = time();
            foreach ($forecast['hours'] ?? [] as $ts => $kw) {
                $ts = (int) $ts;
                if ($ts > $now - 3600 && $ts <= $now + 36 * 3600) {
                    $bars[] = [$ts, round((float) $kw, 2)];
                }
            }
        }
        $soc = $this->HubValue('electricLevel');
        $temp = $this->HubValue('BatteryTemperature');
        $hubMin = $this->HubValue('minSoc');
        return [
            'theme'    => $this->ReadPropertyInteger('TileTheme'),
            'background' => $this->ReadPropertyString('TileBackground') === 'none' ? 'none' : 'aurora',
            'active'   => (bool) $this->GetValue('Active'),
            'mode'     => (int) $this->GetValue('Mode'),
            'reason'   => (string) $this->GetValue('Reason'),
            'hint'     => $this->ReadPropertyInteger('CheckMode') === 1 ? $this->CheckWarning() : '',
            'today'    => (float) $this->GetValue('ForecastToday'),
            'tomorrow' => (float) $this->GetValue('ForecastTomorrow'),
            'refill'   => (int) $this->GetValue('Refill'),
            'target'   => (int) $this->GetValue('TargetMinSoc'),
            'output'   => $this->ReadPropertyBoolean('ControlOutput') ? (int) $this->GetValue('TargetOutput') : null,
            'soc'      => $soc,
            'hubMin'   => $hubMin,
            'temp'     => $temp,
            'capacity' => round($this->Capacity(), 2),
            'base'     => $this->ReadPropertyInteger('BaseLoad'),
            'bars'     => $bars,
            'rise'     => is_array($forecast) ? array_map('intval', $forecast['sunrise'] ?? []) : [],
            'set'      => is_array($forecast) ? array_map('intval', $forecast['sunset'] ?? []) : [],
        ];
    }

    /** Empfehlung des Prognose-Checks für die Kachel (leer, wenn die Prognose passt). */
    private function CheckWarning(): string
    {
        $id = @IPS_GetObjectIDByIdent('CheckHint', $this->InstanceID);
        if ($id === false || $id === 0) {
            return '';
        }
        $text = (string) GetValue($id);
        return str_contains($text, 'Empfehlung:') ? $text : '';
    }

    // ---------------------------------------------------------------------
    // Hilfen
    // ---------------------------------------------------------------------

    private function SetValueIfChanged(string $ident, mixed $value): void
    {
        if ($this->GetValue($ident) !== $value) {
            $this->SetValue($ident, $value);
        }
    }

    private function Clamp(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }

    private function Num(float $value, int $digits): string
    {
        return number_format($value, $digits, ',', '.');
    }

    private function Presentation(string $key): array
    {
        $value = static function (string $suffix, string $icon, int $digits = 0): array {
            return ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'SUFFIX' => $suffix, 'DIGITS' => $digits, 'ICON' => $icon];
        };
        switch ($key) {
            case 'kwh':    return $value(' kWh', 'solar-panel', 2);
            case 'soc':    return $value(' %', 'battery-quarter');
            case 'refill': return $value(' %', 'battery-bolt');
            case 'watt':   return $value(' W', 'gauge');
            case 'deviation': return $value(' %', 'scale-balanced');
            case 'check':  return ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'MULTILINE' => true, 'ICON' => 'clipboard-check'];
            case 'text':   return ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'MULTILINE' => true, 'ICON' => 'circle-info'];
            case 'datetime':
                return defined('VARIABLE_PRESENTATION_DATE_TIME')
                    ? ['PRESENTATION' => VARIABLE_PRESENTATION_DATE_TIME, 'ICON' => 'clock']
                    : ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => 'clock'];
            case 'mode':
                $options = [
                    [self::MODE_OFF, 'Aus', 'power-off', 0x8796A5],
                    [self::MODE_SUNNY, 'Sonnig – Akku voll nutzen', 'sun', 0x34B36B],
                    [self::MODE_BALANCED, 'Ausgeglichen', 'cloud-sun', 0x4B8EF0],
                    [self::MODE_SAVE, 'Sparbetrieb – Reserve halten', 'cloud', 0xE2A63B],
                    [self::MODE_FROST, 'Frostreserve', 'snowflake', 0x4B8EF0],
                    [self::MODE_PAUSED, 'Pausiert', 'pause', 0x8796A5],
                    [self::MODE_NO_FORECAST, 'Keine Prognose', 'triangle-exclamation', 0xE5484D],
                    [self::MODE_NO_HUB, 'Hub fehlt', 'triangle-exclamation', 0xE5484D],
                ];
                $list = [];
                foreach ($options as [$v, $caption, $icon, $color]) {
                    $list[] = ['Value' => $v, 'Caption' => $caption, 'IconActive' => true, 'IconValue' => $icon, 'Color' => $color];
                }
                return ['PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION, 'ICON' => 'wand-magic-sparkles', 'OPTIONS' => (string) json_encode($list)];
        }
        return ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION];
    }
}
