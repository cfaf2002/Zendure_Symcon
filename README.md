# Zendure für IP-Symcon

[![Version](https://img.shields.io/badge/Version-2.1-blue)](library.json)
[![IP-Symcon](https://img.shields.io/badge/IP--Symcon-ab%208.1-0A7BBB)](https://www.symcon.de)
[![Symcon 9.0](https://img.shields.io/badge/optimiert%20f%C3%BCr-Symcon%209.0-0A7BBB)](https://www.symcon.de/de/service/dokumentation/installation/migrationen/v81-v90-q1-2026/)
[![PHP](https://img.shields.io/badge/PHP-8.3-777BB4?logo=php&logoColor=white)](https://www.php.net)
[![Zendure](https://img.shields.io/badge/Zendure-SolarFlow%20Hub%201200%20%7C%202000-2FBF71)](https://zendure.de)
[![Lizenz: MIT](https://img.shields.io/badge/Lizenz-MIT-green)](LICENSE)
[![Letzter Commit](https://img.shields.io/github/last-commit/cfaf2002/Zendure_Symcon)](https://github.com/cfaf2002/Zendure_Symcon/commits)

Modul zum Auslesen und Steuern von **Zendure SolarFlow Hub 1200 / Hub 2000** in IP-Symcon – wahlweise über die **Zendure-Cloud** oder einen **lokalen MQTT-Broker**.

Autor: Armin Frohwerk

> **Hinweis:** Das Modul ist kein offizielles Produkt von Zendure und steht in keiner Verbindung zu Zendure. Es nutzt dieselbe Schnittstelle wie die Zendure-Integration für Home Assistant. Ändert Zendure die Schnittstelle, kann das Modul ohne Vorwarnung aufhören zu funktionieren.

## Inhalt

| Modul | Typ | Präfix | Aufgabe |
|---|---|---|---|
| Zendure Cloud | Splitter | `ZENDC` | Holt mit dem Cloud-Key Geräteliste und MQTT-Zugangsdaten, richtet den MQTT-Client ein |
| Zendure Configurator | Konfigurator | `ZENDK` | Listet die Geräte des Kontos und legt Geräteinstanzen an |
| Zendure SolarFlow Hub | Gerät | `ZEND` | Werte, Akkus, Steuerung |

## Voraussetzungen

- IP-Symcon ab 8.1 (empfohlen 9.0 – dann öffnet ein Antippen in der Kachel die passende Variable)
- Zendure-App mit eingerichtetem Hub
- Cloud-Betrieb: Cloud-Key aus der Zendure-App (der Token, den Zendure für die Home-Assistant-Integration ausgibt)
- Lokaler Betrieb: MQTT Server in Symcon und ein Hub, der per Bluetooth auf diesen Broker umgestellt wurde

## Einrichtung – Cloud

1. Instanz **Zendure Cloud** anlegen, Cloud-Key einfügen, übernehmen.
2. Button **MQTT-Verbindung einrichten**: legt bei Bedarf einen *MQTT Client* mit *Client Socket* an und trägt Server, Benutzer, Passwort, Client-ID und die Abonnements ein.
   Falls etwas nicht automatisch gesetzt werden kann, zeigt **Zugangsdaten anzeigen** alle Werte zum manuellen Eintragen.
3. Instanz **Zendure Configurator** anlegen (hängt sich an *Zendure Cloud*) und den Hub erstellen.

## Einrichtung – lokal (ohne Cloud)

1. In Symcon einen **MQTT Server** mit Server Socket auf Port 1883 anlegen.
   Läuft Symcon im Docker-Container, muss Port 1883 nach außen freigegeben sein.
2. Den Hub per Bluetooth auf den lokalen Broker umstellen, z. B. mit dem
   [solarflow-bt-manager](https://github.com/reinhard-brandstaedter/solarflow-bt-manager):
   `python3 solarflow-bt-manager.py -d -w <WLAN-SSID> -b <Symcon-IP>:1883`
   Rückgängig: `-c` statt `-d`. Die Zendure-App zeigt im lokalen Betrieb keine Live-Daten mehr.
3. Instanz **Zendure SolarFlow Hub** anlegen und als Gateway den **MQTT Server** wählen.
   Modell auswählen (Product Key wird dann automatisch gesetzt: Hub 1200 `73bkTV`, Hub 2000 `A8yh63`) und den **Device Key** eintragen
   (steht im Konfigurator der Cloud-Variante oder in der Ausgabe des BT-Managers).

Beides lässt sich kombinieren: Geräte über den Konfigurator anlegen und danach per „Gateway ändern“ auf den lokalen MQTT Server umhängen.

## Variablen

Variablen werden angelegt, sobald der Hub den jeweiligen Wert meldet, u. a.:

- Ladezustand, PV-Leistung (gesamt und je Eingang), Ausgang zum Haus
- Lade-/Entladeleistung des Akkus und Akkuleistung (+ Laden / − Entladen)
- Akkustatus, Restlaufzeit, Anzahl Akkus, Bypass, WLAN
- je Akku: Ladezustand, Temperatur, Spannung, Strom, Leistung
- schaltbar: Ausgangsleistung (Limit), Ladegrenze, Entladegrenze, Bypass-Modus, Bypass automatisch zurücksetzen, Signalton, Entladeleistung vorgeben

## Kachel

Die Hub-Instanz bringt eine eigene Kachel für die Kachel-Visualisierung mit (HTML-SDK):

- Energiefluss von links nach rechts: Solar und Akku → Hub → Haus, mit fließenden Lichtpunkten (schneller bei mehr Leistung) und Ladezustandsring am Akku
- Info-Spalte mit Status (lädt / entlädt / Ruhezustand / offline), Ladezustand, Restzeit bzw. „Voll in“, Ertrag heute und Akkutemperatur
- passt sich der Kachelgröße an: breite Kacheln mit Info-Spalte rechts, hohe Kacheln mit Infos darunter, kleine Kacheln kompakt
- Platz für Titel und Vergrößern-Symbol der Visualisierung bleibt frei

**Hintergrund** (Bereich „Kachel“ in der Hub-Instanz):

| Einstellung | Wirkung |
|---|---|
| Aurora (Standard) | moderner, dunkler Hintergrund mit weich wandernden Farbwolken und Punkteraster. Die Sonne wandert über einen feinen Tagesbogen am echten Sonnenstand (Sonnenauf- und -untergang aus der Standort-Instanz von Symcon, sonst 7–19 Uhr) und strahlt umso kräftiger, je mehr Solarleistung anliegt. Am unteren Rand liegen Solarmodule in 3D-Perspektive; die Sonne spiegelt sich darin, der Reflex wandert mit dem Sonnenstand und glänzt umso stärker, je mehr Leistung anliegt. Nachts zieht eine Mondsichel ihren Bogen, dazu funkeln Sterne und die Module liegen im Mondlicht. Die Farbwolken leuchten je nach Energiefluss: gelb bei Solarleistung, türkis beim Laden/Entladen, blau bei der Einspeisung ins Haus. |
| Szene | eingebaute Illustration mit Haus, Solarmodulen und Speicher – tagsüber hell mit Sonne, nachts dunkel mit Mond und Sternen |
| Eigenes Bild | ein Medienobjekt (Bild) über die ganze Kachel, abgeblendet; Sichtbarkeit einstellbar (empfohlen 20–40 %) |
| Keiner | nur der Hintergrund der Visualisierung |

Große Bilder werden automatisch verkleinert, damit die Kachel nicht zu groß wird.

**Ertrag heute:** Der Hub meldet keinen Tagesertrag. Das Modul summiert deshalb die Solarleistung des Hubs selbst auf (Variable „Solarertrag Hub heute“, Rücksetzung um Mitternacht). Ist unter „Hoymiles-Wechselrichter“ der Tagesertrag der Anlage ausgewählt (z. B. „Ertrag heute“ aus dem Modul „Hoymiles Cloud“), zeigt die Kachel diesen.

**Akkutemperatur:** höchste gemeldete Temperatur aller Akkus (Variable „Akkutemperatur“).

**Hoymiles-Wechselrichter einbinden:** Unter „Hoymiles-Wechselrichter“ in der Hub-Instanz die Variable „Leistung“ aus dem Modul „Hoymiles Cloud“ als AC-Leistung auswählen. Die Kachel zeigt dann zusätzlich:

- Hub → Wechselrichter (Ausgang des Hubs)
- direkt angeschlossene Module → Wechselrichter
- Wechselrichter → Haus (tatsächliche AC-Einspeisung)

Für die direkt angeschlossenen Module können bis zu zwei Eingänge gewählt werden (z. B. „PV 1 Leistung“ und „PV 3 Leistung“, wenn der Hub an PV 2 und PV 4 hängt). Sie werden addiert, und die Kachel zeigt dann auch den Wirkungsgrad. Ohne diese Variablen wird der Wert aus AC-Leistung und Hub-Ausgang geschätzt. Werte in kW bzw. Wh werden automatisch umgerechnet.

## Symcon 8/9

- Alle Module nutzen `IPSModuleStrict` mit typisierten Funktionen.
- Die Variablen verwenden **Darstellungen** statt eigener Variablenprofile: Werte mit Einheit und Icon, Schieberegler für Ausgangsleistung, Ladegrenze, Entladegrenze und Entladeleistung, Aufzählung für den Bypass-Modus, Schalter für Signalton und Bypass-Rücksetzung. Die Profile früherer Versionen (`ZEND.*`) werden beim Update automatisch auf Darstellungen umgestellt und gelöscht, sobald sie nicht mehr gebraucht werden.
- **Kachelschema:** Mit Hintergrund „Keiner“ übernimmt die Kachel die Farben des gewählten Visualisierungs-Themes (`--content-color`, `--card-color`, `--accent-color`) und passt damit zu hellem und dunklem Design. Die Hintergründe Aurora, Szene und eigenes Bild bleiben bewusst dunkel.
- **openObject (ab 9.0):** Ein Antippen von Solar, Akku, Haus oder einer Info-Karte öffnet die zugehörige Variable; der Wechselrichter öffnet die Hoymiles-Instanz. Unter 9.0 ist die Kachel wie bisher nur Anzeige.

## PHP-Befehle

```php
ZEND_RequestUpdate(int $InstanzID);
ZEND_SetOutputLimit(int $InstanzID, int $Watt);       // outputLimit schreiben
ZEND_SetDischargePower(int $InstanzID, int $Watt);    // über Geräteautomatik, 0 = stoppen
ZEND_SetMaxSoc(int $InstanzID, int $Prozent);         // 70–100
ZEND_SetMinSoc(int $InstanzID, int $Prozent);         // 0–50
ZEND_SetPassMode(int $InstanzID, int $Modus);         // 0 auto, 1 aus, 2 an
ZEND_SetAutoRecover(int $InstanzID, bool $An);
ZEND_SetBuzzer(int $InstanzID, bool $An);
ZEND_SetInputLimit(int $InstanzID, int $Watt);
ZEND_WriteProperty(int $InstanzID, string $Name, int $Wert);

ZENDC_RefreshDevices(int $InstanzID);
ZENDC_SetupMqttConnection(int $InstanzID);
ZENDC_GetConnectionInfo(int $InstanzID);
```

## Hinweise

- Die Cloud-Anbindung nutzt dieselbe Schnittstelle wie die offizielle Zendure-Integration für Home Assistant. Zendure kann diese jederzeit ändern.
- Ob der Hub `outputLimit` direkt übernimmt, hängt vom eingestellten Modus in der App ab. Greift das Limit nicht, `ZEND_SetDischargePower` verwenden.
- Häufiges Schreiben (z. B. sekündliche Nulleinspeisungs-Regelung) möglichst vermeiden.

## Lizenz

[MIT](LICENSE) – © 2026 Armin Frohwerk.

Cloud-Anmeldung (Signatur des Abrufs) und MQTT-Protokoll orientieren sich an der Zendure-Integration für Home Assistant ([Zendure/Zendure-HA](https://github.com/Zendure/Zendure-HA), MIT-Lizenz, © 2024 peteS-UK). Zendure und SolarFlow sind Marken ihrer jeweiligen Inhaber.
