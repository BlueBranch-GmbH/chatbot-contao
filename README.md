# BlueBranch Chatbot

KI-Chatbot und KI-Suche für Contao. Die Erweiterung übergibt die Seiteninhalte beim
Suchindex-Lauf an eine Chatbot-API, die daraus eine Vektor-Wissensbasis aufbaut, und
beantwortet Besucherfragen aus genau diesem Bestand — als aufklappbares Chat-Widget und
als zusammenfassende Antwort über der Trefferliste der Contao-Suche.

Der API-Schlüssel bleibt dabei auf dem Server. Der Browser spricht ausschließlich mit
Contao; Contao spricht mit der API.

## Voraussetzungen

- PHP 8.0 oder neuer
- Contao 4.13 oder 5.x
- Ein Zugang zur Chatbot-API (`https://api.chatbot.bluebranch.de`) mit API-Schlüssel

## Installation

Über den Contao Manager das Paket `bluebranch/chatbot` hinzufügen, oder per Composer:

```bash
composer require bluebranch/chatbot
```

Anschließend das Contao-Backend einmal aufrufen, damit die Datenbank aktualisiert wird.

## Einrichtung

**1. API-Schlüssel hinterlegen.** Der Schlüssel wird pro Website gesetzt: Seitenstruktur →
Startpunkt der Website bearbeiten → Feld *Chatbot API-Key*. In einer Installation mit
mehreren Websites bekommt jede ihren eigenen Schlüssel und damit ihre eigene Wissensbasis.

**2. Inhalte trainieren.** Die Wissensbasis füllt sich über den Contao-Suchindex. Sobald der
Crawler läuft (System → Suchindex neu aufbauen), wird jede indizierte Seite an die API
übergeben. Was nicht im Suchindex steht, kennt der Chatbot nicht.

**3. Modul einbinden.** Unter *Themes → Frontend-Module* stehen zwei Module bereit:

| Modul | Zweck |
|---|---|
| *Chatbot Widget* | Aufklappbarer Chat-Button, standardmäßig unten rechts |
| *Chatbot Generate Search* | Zusammenfassende KI-Antwort über der Trefferliste der Suche |

## Einstellungen

Unter *System → Einstellungen* lassen sich Vorgaben für alle Chat-Widgets setzen: Name,
Akzentfarbe, Icon, Begrüßung, Vorschlagsfragen sowie das Ausblenden der Pill
„Inhalt zusammenfassen" und des Datenschutz-Hinweises. Jedes Modul kann diese Vorgaben
einzeln überschreiben.

**Automatische Bereinigung.** Seiten, die nicht mehr veröffentlicht, von der Suche
ausgeschlossen oder über ihr Start-/Stop-Datum abgelaufen sind, gehören nicht in den
KI-Index. Ein zeitgesteuertes Ablaufen passiert ohne Speichern des Datensatzes und wird
deshalb sonst nicht bemerkt. Der Bereinigungslauf hängt an Contaos eigenem Cron-System —
ein Server-Cronjob ist nicht nötig, es genügt, dass die Website regelmäßig aufgerufen wird.

**Debug-Dateien.** Standardmäßig aus. Eingeschaltet legt die Erweiterung zu jedem
Trainings- und Löschvorgang eine JSON-Datei unter `var/chatbot/` ab. Das ist zur
Fehlersuche gedacht und nicht für den Dauerbetrieb: Ein Crawler-Lauf erzeugt eine Datei je
Seite samt vollständigem Inhalt, und niemand räumt sie wieder weg.

## Bereiche vom Index ausnehmen

Zwei Inhaltselemente grenzen Bereiche ab, die nicht in den Suchindex sollen:

- *Indexer: Stop* — ab hier wird nicht mehr indiziert
- *Indexer: Continue* — ab hier wieder

Für die KI-Wissensbasis werden diese Markierungen bewusst **ignoriert**, damit auch
Nachrichtenlisten und ähnliche dynamische Bereiche beantwortbar bleiben. Sie wirken auf die
klassische Contao-Suche.

## Trainierte Inhalte einsehen

Das Backend-Modul *BlueBranch Chatbot → Trainierte Seiten* zeigt, was die Wissensbasis
tatsächlich enthält, und erlaubt es, einzelne Einträge oder den gesamten Bestand zu löschen.

## Wie die Anfragen laufen

Der Browser ruft ausschließlich Contao-Routen auf, die ihrerseits die API ansprechen:

| Route | Aufgabe |
|---|---|
| `/bluebranch/chatbot/api/v1/chat/stream` | Antwort im Chat-Modus (kurz, schnell) |
| `/bluebranch/chatbot/api/v1/generate/stream` | Antwort im Such-Modus (ausführlich) |
| `/bluebranch/chatbot/api/v1/generate/search` | Antwort ohne Streaming |

Alle drei verlangen einen Sitzungs-Token, den die Module beim Rendern in die Session legen.
Die Antworten kommen als Server-Sent Events zurück: zuerst ein `sources`-Ereignis mit den
verwendeten Seiten, danach die Antwort in Stücken, zum Schluss ein `end`-Ereignis.

Wird die Erweiterung hinter einem Reverse Proxy betrieben, muss dieser das Puffern für
diese Routen abschalten (`proxy_buffering off` bei nginx) — sonst kommt die Antwort erst
am Stück und der Streaming-Effekt entfällt.

## Lizenz

MIT — siehe [LICENSE.txt](LICENSE.txt).
