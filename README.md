# Zendure für IP-Symcon

Modul zum Auslesen und Steuern von **Zendure SolarFlow Hub 1200 / Hub 2000** in IP-Symcon – wahlweise über die **Zendure-Cloud** oder einen **lokalen MQTT-Broker**.

Autor: Armin Frohwerk

## Inhalt

| Modul | Typ | Präfix | Aufgabe |
|---|---|---|---|
| Zendure Cloud | Splitter | `ZENDC` | Holt mit dem Cloud-Key Geräteliste und MQTT-Zugangsdaten, richtet den MQTT-Client ein |
| Zendure Konfigurator | Konfigurator | `ZENDK` | Listet die Geräte des Kontos und legt Geräteinstanzen an |
| Zendure SolarFlow Hub | Gerät | `ZEND` | Werte, Akkus, Steuerung |

## Voraussetzungen

- IP-Symcon ab 7.0
- Zendure-App mit eingerichtetem Hub
- Cloud-Betrieb: Cloud-Key aus der Zendure-App (der Token, den Zendure für die Home-Assistant-Integration ausgibt)
- Lokaler Betrieb: MQTT Server in Symcon und ein Hub, der per Bluetooth auf diesen Broker umgestellt wurde

## Einrichtung – Cloud

1. Instanz **Zendure Cloud** anlegen, Cloud-Key einfügen, übernehmen.
2. Button **MQTT-Verbindung einrichten**: legt bei Bedarf einen *MQTT Client* mit *Client Socket* an und trägt Server, Benutzer, Passwort, Client-ID und die Abonnements ein.
   Falls etwas nicht automatisch gesetzt werden kann, zeigt **Zugangsdaten anzeigen** alle Werte zum manuellen Eintragen.
3. Instanz **Zendure Konfigurator** anlegen (hängt sich an *Zendure Cloud*) und den Hub erstellen.

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

Die Hub-Instanz bringt eine eigene Kachel für die Kachel-Visualisierung mit (ab Symcon 7): Energiefluss Solar → Hub ↔ Akku → Haus mit animierten Leitungen, Ladezustandsring, Lade-/Entladestatus und Restzeit.

**Wechselrichter einbinden (z. B. Hoymiles HMS):** Unter „Wechselrichter“ in der Hub-Instanz die AC-Leistungsvariable des Wechselrichters auswählen (aus dem Hoymiles-Modul, OpenDTU o. Ä.). Die Kachel zeigt dann zusätzlich:

- Hub → Wechselrichter (Ausgang des Hubs)
- direkt angeschlossene Module → Wechselrichter
- Wechselrichter → Haus (tatsächliche AC-Einspeisung)

Für die direkt angeschlossenen Module kann eine eigene Leistungsvariable gewählt werden (z. B. Summe der DC-Eingänge ohne Hub). Dann wird auch der Wirkungsgrad angezeigt. Ohne diese Variable wird der Wert aus AC-Leistung und Hub-Ausgang geschätzt. Variablen mit kW-Profil werden automatisch in W umgerechnet.

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
