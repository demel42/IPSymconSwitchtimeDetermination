[![IPS-Version](https://img.shields.io/badge/Symcon_Version-6.0+-red.svg)](https://www.symcon.de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/)
![Code](https://img.shields.io/badge/Code-PHP-blue.svg)
[![License](https://img.shields.io/badge/License-CC%20BY--NC--SA%204.0-green.svg)](https://creativecommons.org/licenses/by-nc-sa/4.0/)

## Dokumentation

**Inhaltsverzeichnis**

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Installation](#3-installation)
4. [Funktionsreferenz](#4-funktionsreferenz)
5. [Konfiguration](#5-konfiguration)
6. [Anhang](#6-anhang)
7. [Versions-Historie](#7-versions-historie)

## 1. Funktionsumfang

Berechnung von täglichen Schaltzeiten.
Die Verwendung von festen Zeit in Ereignissen gibt es unter Umständen ein paar Nachteile:<br>
- bei Verwendung von Astrozeiten (z.B. Sonnenauf- und Untergange) kann es sein, das z.B. im Sommer die morgendliche Aktion sehr früh erfolgt
(weil Sonnenaufgang schon um kurz nach 5 Uhr ist), also vo dem Aufstehen.<br>
- keine Berücksichtigung von Wochenende und Feiertagen

Daher bietet das Modul die Möglichkeit, das etwas einzuhegen:<br>
- beliebig viele Schaltzeitpunkte pro Tag<br>
- Angabe der zulässigen Grenzen der Schaltzeiten pro Schaltzeitpunkt<br>
- zu jedem Schaltzeitpunkt wird eine Referenzvariable angegeben (z.B. die Variablen aus der _Location_-Instanz)<br>
- optionale Angabe eines Zeitversatzes zu jedem Bereich<br>
- Unterstützung von Wochentagen durch Nutzung eines Wochenplans<br>
- Ermittlung des neuen Zeitpunkts direkt bei Änderung der zugrunde liegenden Referenz-Variablen oder zu einer bestimmten Uhrzeit<br>
- optionale Ermittlung eines zusätzlichen zufälligen Zeitversatzes<br>

## 2. Voraussetzungen

- IP-Symcon ab Version 6.0

## 3. Installation

### a. Installation des Moduls

Im [Module Store](https://www.symcon.de/service/dokumentation/komponenten/verwaltungskonsole/module-store/) ist das Modul unter dem Suchbegriff
*Schaltzeit ermitteln* zu finden.<br>
Alternativ kann das Modul über [Module Control](https://www.symcon.de/service/dokumentation/modulreferenz/module-control/) unter Angabe der URL
`https://github.com/demel42/SwitchtimeDetermination.git` installiert werden.

### b. Einrichtung in IPS

- Definition mindestens eines Schaltzeiten-Bereiches unter Angaben einer Bezeichnung (was auch die Bezeichnung der hierzu erzeugten Variablen ist).<br>
- Angabe eines Wochenplans; diese Wochenplan kann über die Aktion _Wochenplan-Ereignis erzeugen_ angelegt werden (Funktion steht nur zur Verfügung, wenn die ID des Ereignisses leer ist).
  Der Wochenplan kann natürlich auch mit meheren Instanzen geteil werden, einfach den passenden Plan eintragen.
  ![Wochenplan](docs/img/wochenplan.png?raw=true "Wochenplan")

## 4. Funktionsreferenz

Es gibt keine Funktionen des Moduls.

## 5. Konfiguration

### Schaltzeiten ermitteln (SwitchtimeDetermination)

#### Properties

| Eigenschaft               | Typ      | Standardwert | Beschreibung |
| :------------------------ | :------  | :----------- | :----------- |
| Instanz deaktivieren      | boolean  | false        | Instanz temporär deaktivieren |
|                           |          |              | |
| Bereich-Definition        | array    |              | Defintion der Schaltzeit-Bereiche |
| ... Name                  | string   |              | Bezeichnung des Schaltzeit-Bereichs |
| ... Referenz-Variable     | integer  | 0            | Variable vom Typ ~UnixTimestamp |
| ... Zeitversatz           | integer  | 0            | Zeitversatz in Sekunden |
|                           |          |              | |
| Wochenplan-Ereignis       | integer  | 0            | Wochenplan [^1] |
|                           |          |              | |
| zufälliger Zeitversatz    | integer  | 0            | Maximaler zusätzlicher zufälliger Zeitversatz in Sekunden, der zu der ermittelten Zeit hinzugefügt wird. |
|                           |          |              | |
| Erkennung von Feiertagen  | integer  | 0            | Skript zur Erkennung von Feiertagen (behandeln wie Sonntage) [^2] |
|                           |          |              | |
|                           | string   |              | Schaltzeiten unverzüglich nach Änderungen neu ermitteln |
|                           | string   |              | Uhrzeit für die zyklische Ermittlung der Schaltzeiten |
|                           |          |              | |

[^1]: der Wochenplan muss den Bereich _Ruhephase_ mit der ID 0 enthalten sowie für jeden Bereich eine Aktion mit der ID ab 1 (entsprechend der Position in der Tabelle).<br>
Im Plan wird dann für jede Aktion der Bereich definiert, innerhalb dess eine gültige Schlatzeit liegen darf; werden die Grenzen verletzt wird der jew. Grenzwert (als die Anfangs- bzw Endezeit) verwendet. Die anderen Zeiten sind komplett durch die _Ruhephase_ gefüllt.
[^2]: Beispiel-Script siehe [docs/retrieve_holidays.php](docs/retrieve_holidays.php).

#### Aktionen

| Bezeichnung                  | Beschreibung |
| :--------------------------- | :----------- |
| Wochenplan-Ereignis erzeugen | Wochenplan passend zu den definierten Schaltzeit-Bereichen unterhalb der Instanz anlegen |
| Bedingungen prüfen           | Bedingungen prüfen und ggfs Variablen der Schlatzeiten anppassen |

### Variablenprofile

Es werden folgende Variablenprofile angelegt:
* Boolean<br>
* Integer<br>
* Float<br>
* String<br>

## 6. Anhang

### GUIDs
- Modul: `{0C1859DD-4610-4990-D3D9-6A6F058F4102}`
- Instanzen:
  - SwitchtimeDetermination: `{A7DF41FE-B7D0-391E-5D9C-10F3659F06AC}`
- Nachrichten:

### Quellen

## 7. Versions-Historie

- 1.0 @ 03.10.2022 15:11
  - Initiale Version
