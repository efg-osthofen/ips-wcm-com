# IPS WCM COM

IP-Symcon-Modul zur Anbindung einer **Weishaupt**-Heizung über das Kommunikationsmodul **WCM-COM**.
Das Modul liest Kessel-, Puffer- und Heizkreiswerte zyklisch aus und stellt sie als IP-Symcon-Variablen bereit.
Ausgewählte Parameter (Betriebsart, Raumtemperaturen, Heizkurve, maximale Kesselleistung) lassen sich direkt aus IP-Symcon heraus ändern.

## Inhalt

- [Voraussetzungen](#voraussetzungen)
- [Installation](#installation)
- [Konfiguration](#konfiguration)
- [Variablen](#variablen)
- [Schreibbare Parameter](#schreibbare-parameter)
- [PHP-Befehlsreferenz](#php-befehlsreferenz)
- [Funktionsweise](#funktionsweise)
- [Tests](#tests)
- [Projektstruktur](#projektstruktur)
- [Lizenz](#lizenz)

## Voraussetzungen

- IP-Symcon ab Version 6.0
- Weishaupt-Wärmeerzeuger mit WCM-COM-Modul, im lokalen Netz per HTTP erreichbar
- Benutzername und Passwort für die Weboberfläche des WCM-COM
- PHP-Erweiterung `curl` (in IP-Symcon standardmäßig enthalten)

## Installation

1. In der IP-Symcon-Verwaltungskonsole **Kern Instanzen → Modules** öffnen.
2. Über **Hinzufügen** die Repository-URL eintragen:
   ```
   https://github.com/efg-osthofen/ips-wcm-com
   ```
3. Eine neue Instanz vom Typ **WCMConnector** anlegen (Suchbegriffe: „Weishaupt“, „WCM-COM“).

## Konfiguration

| Eigenschaft | Beschreibung | Standard |
|---|---|---|
| URL | Basis-Adresse des WCM-COM, z. B. `http://192.168.1.50`. Das Modul fragt `<URL>/parameter.json` ab. | – |
| Username | Benutzername der WCM-COM-Weboberfläche | – |
| Password | Passwort der WCM-COM-Weboberfläche (HTTP-Digest-Authentifizierung) | – |
| Update Intervall | Abfrageintervall in Sekunden | `0` |
| Nummer des ersten Heizkreises | Erster Heizkreis, für den Variablen angelegt werden | `2` |
| Nummer des letzten Heizkreises | Letzter Heizkreis, für den Variablen angelegt werden | `5` |

Die zyklische Abfrage läuft nur, solange die Variable **Parameter Update** eingeschaltet ist und das Intervall größer als 0 ist.

## Variablen

### Steuerung

| Ident | Name | Typ | Profil |
|---|---|---|---|
| `ParameterUpdate` | Parameter Update | Boolean | `~Switch` |

Ist `ParameterUpdate` eingeschaltet, werden die Werte sofort und danach im eingestellten Intervall gelesen.
Beim Ausschalten wird der Timer gestoppt und alle Werte werden auf `0` gesetzt.

### Pro Heizkreis *n* (von „erster“ bis „letzter Heizkreis“)

| Ident | Name | Typ | Profil | Schreibbar |
|---|---|---|---|---|
| `BetriebsartHeizkreis<n>` | HK *n* Betriebsart | Integer | `WCM.BetriebsartHK` | ✔ |
| `VorlauftemperaturHK<n>` | HK *n* Vorlauftemperatur | Float | `~Temperature` | |
| `SollTempHK<n>` | HK *n* Soll Temperatur | Float | `~Temperature` | |
| `WaermeanforderungHK<n>` | HK *n* Waermeanforderung | Float | `~Temperature` | |
| `NormalRaumtemperaturHK<n>` | HK *n* Normal Raumtemperatur | Float | `~Temperature.HM` | ✔ |
| `RaumfrosttemperaturHK<n>` | HK *n* Raumfrosttemperatur | Float | `~Temperature.HM` | ✔ |
| `SoWiUmschalttemperaturHK<n>` | HK *n* So/Wi Umschalttemperatur | Float | `~Temperature.HM` | ✔ |
| `SteilheitHK<n>` | HK *n* Steilheit | Float | – | ✔ |

### Kessel und Pufferspeicher

| Ident | Name | Typ | Profil | Schreibbar |
|---|---|---|---|---|
| `KesselFehlercode` | Kessel Fehlercode | Integer | – | |
| `KesselAussentemperatur` | Kessel Außentemperatur | Float | `~Temperature` | |
| `KesselGedaempfteAussentemperatur` | Kessel Gedämpfte Außentemperatur | Float | `~Temperature` | |
| `KesselLaststellung` | Kessel Laststellung | Integer | `~Intensity.100` | |
| `KesselMaxLeistungHeizung` | Kessel Max. Leistung Heizung | Integer | `WCM.MaxLeistung` | ✔ |
| `KesselMaxLeistungWW` | Kessel Max. Leistung WW | Integer | `WCM.MaxLeistung` | ✔ |
| `KesselWaermeanforderung` | Kessel Wärmeanforderung | Float | `~Temperature` | |
| `KesselVorlauftemperatur` | Kessel Vorlauftemperatur | Float | `~Temperature` | |
| `KesselVorlauftemperaturEstb` | Kessel Vorlauftemperatur eSTB | Float | `~Temperature` | |
| `KesselRuecklauftemperatur` | Kessel Rücklauftemperatur | Float | `~Temperature` | |
| `KesselWarmwassertemperatur` | Kessel Warmwassertemperatur | Float | `~Temperature` | |
| `KesselAbgastemperatur` | Kessel Abgastemperatur | Float | `~Temperature` | |
| `PufferB10Oben` | Puffer B10 Temperatur Oben | Float | `~Temperature` | |
| `PufferB11Unten` | Puffer B11 Temperatur Unten | Float | `~Temperature` | |

### Variablenprofile

Das Modul legt beim Erstellen der Instanz zwei eigene Profile an, falls diese noch nicht existieren:

**`WCM.BetriebsartHK`** (Integer)

| Wert | Bedeutung |
|---|---|
| 1 | Standby |
| 3 | Normal |
| 4 | Absenk |
| 5 | Sommer |
| 11 | Programm1 |
| 12 | Programm2 |
| 13 | Programm3 |
| 255 | WieLeitstelle |

**`WCM.MaxLeistung`** (Integer): 36–100 %, Schrittweite 1.

## Schreibbare Parameter

Schreibbare Variablen können direkt im WebFront oder per `RequestAction` gesetzt werden.
Das Modul prüft die Werte vor dem Senden und verwirft Werte außerhalb dieser Bereiche:

| Parameter | Gültiger Bereich |
|---|---|
| Betriebsart Heizkreis | Werte aus `WCM.BetriebsartHK` |
| Normal Raumtemperatur | 0 … 30 °C |
| Raumfrosttemperatur | −10 … 30 °C |
| So/Wi Umschalttemperatur | −10 … 30 °C |
| Steilheit | 2,5 … 40 |
| Max. Leistung Heizung / WW | 36 … 100 % |

## PHP-Befehlsreferenz

Das Modul verwendet das Präfix `WCM`.

```php
// Alle Werte vom WCM-COM lesen und in die Variablen schreiben.
// Mit $sendBuffer = false werden keine Daten abgefragt, alle Werte werden auf 0 gesetzt.
WCM_RetrieveWCMStatus(int $InstanzID, bool $sendBuffer);

// Maximale Kesselleistung für Heizung bzw. Warmwasser setzen (36–100 %).
WCM_UpdateWCMMaxLeistungHeizung(int $InstanzID, int $maxLeistung);
WCM_UpdateWCMMaxLeistungWW(int $InstanzID, int $maxLeistung);

// Schreibbare Variable über ihren Ident setzen, z. B. Heizkreis 2 auf "Absenk":
RequestAction(IPS_GetObjectIDByIdent("BetriebsartHeizkreis2", $InstanzID), 4);
```

## Funktionsweise

Das WCM-COM bietet unter `/parameter.json` eine HTTP-Schnittstelle, über die per `POST` Telegramme im Protokoll `coco` ausgetauscht werden.
Ein Telegramm besteht aus acht Bytes:

```
[MODULTYP, BUSKENNUNG, COMMAND, INFONR, INDEX, PROT, DATA (Low-Byte), HIGH_BYTE]
```

- **MODULTYP** adressiert die Baugruppe (z. B. `10` Kessel, `6` Heizkreis, `12` Heizkreis-Vorlauf, `3` Pufferspeicher).
- **BUSKENNUNG** ist bei Heizkreisen die Heizkreisnummer minus 1.
- **COMMAND** ist `1` für Lesen und `2` für Schreiben.
- **INFONR** bestimmt den Parameter (siehe [`constants.php`](libs/wcm-client/constants.php)).

Die Client-Bibliothek sammelt alle Anfragen in einem Puffer und sendet sie in Paketen von höchstens neun Telegrammen.
Jede Antwort wird gegen die angefragten Info-Nummern geprüft.
Schlägt ein Paket fehl (HTTP- oder cURL-Fehler, „server is busy“, unpassende Antwort), wird es nach einer zufälligen Pause von 1–2 Sekunden erneut versucht: beim Lesen bis zu 3-mal, beim Schreiben bis zu 10-mal.
Scheitert das Lesen endgültig, wird der Fehler im IP-Symcon-Log protokolliert und die Variablen werden auf `0` gesetzt.

Rohwerte werden aus Low- und High-Byte als vorzeichenbehaftete 16-Bit-Zahl zusammengesetzt und je nach Parameter durch 10 oder 100 geteilt.
Der Rohwert `0x8000` bedeutet „N/V“ (nicht vorhanden, z. B. Fühler nicht angeschlossen).
Meldet die Anlage für einen Parameter N/V oder einen Fehler, wird nur diese Variable auf `0` gesetzt und im Debug-Fenster der Instanz ein Hinweis ausgegeben.

Eine Semaphore (`WCM_Communication`) sorgt dafür, dass immer nur eine Anfrage gleichzeitig an das WCM-COM geht.
Ist sie belegt, wird ein zyklischer Lesevorgang übersprungen und beim nächsten Intervall nachgeholt.

## Tests

Die Tests laufen lokal mit PHPUnit und brauchen weder IP-Symcon noch eine echte Heizung.

```bash
composer install
composer test
```

| Testsuite | Inhalt |
|---|---|
| `unit` | Aufbau der Lese- und Schreibtelegramme, Wertebereiche, Low-/High-Byte, Dekodierung und Skalierung je Info-Nummer |
| `integration` | `sendBuffer()` über echtes HTTP gegen einen simulierten WCM-COM mit Digest-Authentifizierung: Aufteilung in Pakete, Fehler, Wiederholungen |
| `module` | `WCMConnector` in einer nachgebildeten IP-Symcon-Umgebung: Variablen, Profile, Timer, Lesen, Schreiben, Semaphore |

Der simulierte WCM-COM ([`tests/Support/fake-wcm-router.php`](tests/Support/fake-wcm-router.php)) läuft als PHP-Webserver.
Er beantwortet Lesetelegramme aus einem kleinen Gerätespeicher, übernimmt Schreibtelegramme und kann gezielt fehlerhafte Antworten liefern.

Tests mit der Gruppe `known-issue` beschreiben das erwartete Verhalten bei bekannten Problemen und schlagen fehl, bis diese behoben sind.
Ohne diese Tests:

```bash
composer test:stable
```

Die Gruppe `slow` enthält Tests, die wegen der eingebauten Wartezeiten bei Wiederholungen einige Sekunden dauern.

## Projektstruktur

```
.
├── library.json              # Bibliotheksbeschreibung für IP-Symcon
├── WCMConnector/
│   ├── module.json           # Moduldefinition (Typ, Präfix, Aliase)
│   ├── form.json             # Konfigurationsformular der Instanz
│   └── module.php            # Modul-Logik: Variablen, Timer, RequestAction
├── libs/wcm-client/
│   ├── wcm.php               # PHP-Client für die WCM-COM-Schnittstelle
│   └── constants.php         # Info-Nummern, Einheiten und Betriebsarten
└── tests/
    ├── Unit/                 # Telegramme kodieren und dekodieren
    ├── Integration/          # HTTP-Kommunikation mit simuliertem WCM-COM
    ├── Module/               # WCMConnector in nachgebildetem IP-Symcon
    └── Support/              # IP-Symcon-Stubs, simulierter WCM-COM
```

## Lizenz

MIT, siehe [LICENSE](LICENSE).
Die Client-Bibliothek in `libs/wcm-client` steht ebenfalls unter der MIT-Lizenz (© 2022 Moritz Heusinger), siehe [libs/wcm-client/LICENSE](libs/wcm-client/LICENSE).
