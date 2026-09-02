# BlueBranch Chatbot – KI-Chatbot und KI-Suche für Contao (DE)

Beantworte Besucherfragen direkt auf deiner Contao-Website – mit einer KI, die ausschließlich
deine eigenen Seiteninhalte kennt.

Die Erweiterung übergibt die Seiteninhalte beim Suchindex-Lauf an die Chatbot-API, die daraus
eine Vektor-Wissensbasis aufbaut. Aus genau diesem Bestand werden Fragen beantwortet – als
aufklappbares Chat-Widget und als zusammenfassende Antwort über der Trefferliste der
Contao-Suche.

Der API-Schlüssel bleibt dabei auf dem Server: Der Browser spricht ausschließlich mit Contao,
Contao spricht mit der API.

## So funktioniert die Contao-Integration

Nach der Installation brauchst du nur einen API-Schlüssel – und kannst direkt loslegen:

1. Installation der Erweiterung `$ composer require bluebranch/chatbot` oder über den Contao Manager
2. Registriere Dich auf [chatbot.bluebranch.de](https://chatbot.bluebranch.de)
3. API-Key erstellen und am Startpunkt der Website hinterlegen
4. Suchindex neu aufbauen – damit werden die Inhalte trainiert
5. Frontend-Modul einbinden

Fertig!

## Voraussetzungen

- PHP 8.0 oder neuer
- Contao 4.13 oder 5.x
- Ein Chatbot-Zugang mit API-Schlüssel von [chatbot.bluebranch.de](https://chatbot.bluebranch.de)

## Installation

Über den Contao Manager das Paket `bluebranch/chatbot` hinzufügen, oder per Composer:

```bash
composer require bluebranch/chatbot
```

Anschließend das Contao-Backend einmal aufrufen, damit die Datenbank aktualisiert wird.

## Einrichtung

### 1. API-Schlüssel hinterlegen

Der Schlüssel wird pro Website gesetzt: *Seitenstruktur* → Startpunkt der Website bearbeiten →
Feld **Chatbot API-Key**. In einer Installation mit mehreren Websites bekommt jede ihren eigenen
Schlüssel und damit ihre eigene Wissensbasis.

Ein hinterlegter Schlüssel wird **nie ins Formular geschrieben**. Im Feld steht nur ein
Platzhalter – im Quelltext der Backend-Seite, im Browserverlauf und in jedem Zwischenspeicher
also ebenfalls. `hideInput` zeigt zusätzlich Punkte statt Zeichen.

| Eingabe | Wirkung |
|---|---|
| Platzhalter stehen lassen | Der Schlüssel bleibt |
| Feld leeren | Der Schlüssel wird gelöscht |
| Etwas anderes eintragen | Wird als neuer Schlüssel übernommen |

### 2. Inhalte trainieren

Die Wissensbasis füllt sich über den Contao-Suchindex. Sobald der Crawler läuft
(*System → Suchindex neu aufbauen*), wird jede indizierte Seite an die API übergeben.
**Was nicht im Suchindex steht, kennt der Chatbot nicht.**

> Läuft die Installation im `dev`-Environment, hängt Symfony an jede Antwort einen
> `X-Robots-Tag: noindex`-Header. Der Contao-Crawler überspringt daraufhin sämtliche Seiten, und
> es wird nichts trainiert. Für einen Trainingslauf `APP_ENV=prod` setzen.

### 3. Modul einbinden

Unter *Themes → Frontend-Module* stehen zwei Module bereit:

| Modul | Zweck |
|---|---|
| *Chatbot Widget* | Aufklappbarer Chat-Button, standardmäßig unten rechts |
| *Chatbot Generate Search* | Zusammenfassende KI-Antwort über der Trefferliste der Suche |

Beide werden wie gewohnt in ein Layout oder einen Artikel eingebunden.

## Globale Einstellungen

Unter *System → Einstellungen* lassen sich Vorgaben für alle Chat-Widgets setzen. Jedes Modul
kann sie einzeln überschreiben.

| Einstellung | Wirkung |
|---|---|
| Name des Chatbots | Wird im Chat-Header angezeigt |
| Akzentfarbe | Farbe des Widgets, als Hex-Wert mit Farbwähler |
| Icon (SVG) | Ersetzt das Standard-KI-Icon im Chat-Button |
| Standard-Begrüßung | Wird beim ersten Öffnen des Chats angezeigt |
| Standard-Vorschläge | Vorschlagsfragen als Pills über dem Eingabefeld |
| „Inhalt zusammenfassen"-Pill ausblenden | Blendet die Zusammenfassen-Funktion aus |
| Datenschutz-Hinweis ausblenden | Blendet den Hinweis „Private chats & Hosted in Germany" aus |

**Automatische Bereinigung.** Seiten, die nicht mehr veröffentlicht, von der Suche ausgeschlossen
oder über ihr Start-/Stop-Datum abgelaufen sind, gehören nicht in den KI-Index. Ein
zeitgesteuertes Ablaufen passiert ohne Speichern des Datensatzes und würde deshalb sonst nicht
bemerkt. Das Intervall ist wählbar: stündlich, alle 6 Stunden, täglich (Vorgabe) oder wöchentlich.
Der Lauf hängt an Contaos eigenem Cron-System – ein Server-Cronjob ist nicht nötig, es genügt,
dass die Website regelmäßig aufgerufen wird.

**Debug-Dateien.** Standardmäßig aus. Eingeschaltet legt die Erweiterung zu jedem Trainings- und
Löschvorgang eine JSON-Datei unter `var/chatbot/` ab. Das ist zur Fehlersuche gedacht und nicht
für den Dauerbetrieb: Ein Crawler-Lauf erzeugt eine Datei je Seite samt vollständigem Inhalt, und
niemand räumt sie wieder weg.

## Seiten von den KI-Antworten ausschließen

In den Seiteneinstellungen steht neben *Von der Suche ausschließen* das Feld
**Aus den KI-Antworten ausschließen**. Beide entscheiden dasselbe – ob eine Seite als Quelle
dient – nur für zwei verschiedene Suchen, und sie sind voneinander unabhängig:

| | Contao-Volltextsuche | KI-Antworten |
|---|---|---|
| *Von der Suche ausschließen* | nein | nein |
| *Aus den KI-Antworten ausschließen* | ja | nein |
| beide | nein | nein |

Eine Kontaktseite kann so in der Volltextsuche stehen und trotzdem aus den Chatbot-Antworten
heraus – oder umgekehrt.

**Die Einstellung vererbt sich auf alle Unterseiten.** Wer eine Rubrik abhakt, meint den ganzen
Zweig. Beim Speichern werden die betroffenen Seiten sofort aus der Wissensbasis entfernt, nicht
erst beim nächsten Bereinigungslauf.

## Bereiche vom Index ausnehmen

Zwei Inhaltselemente grenzen Bereiche ab, die nicht in den Suchindex sollen:

- *Indexer: Stop* – ab hier wird nicht mehr indiziert
- *Indexer: Continue* – ab hier wieder

Für die KI-Wissensbasis werden diese Markierungen bewusst **ignoriert**, damit auch
Nachrichtenlisten und ähnliche dynamische Bereiche beantwortbar bleiben. Sie wirken auf die
klassische Contao-Suche.

## Trainierte Inhalte einsehen

Das Backend-Modul *BlueBranch Chatbot → Trainierte Seiten* zeigt, was die Wissensbasis
tatsächlich enthält – je Website, mit Titel, URL, Anzahl der Chunks, Sprache und Trainingsdatum.
Einzelne Einträge oder der gesamte Bestand lassen sich dort löschen. Über das eingebaute
Test-Feld kannst du dem Chatbot direkt eine Frage stellen und die Antwort samt Quellen prüfen,
ohne die Website zu öffnen.

## Nutzungsstufen

Ein Zugang ist **Free**, **Pro** oder **Expert**. Der Unterschied liegt allein im
Anfragekontingent der Antwort-Routen; Inhalte trainieren, Inhalte auflisten und API-Keys anlegen
ist in allen Stufen unbegrenzt.

Die konkreten Zahlen stehen bewusst **nicht** in dieser Erweiterung. Sie kommen zur Laufzeit von
der API, zusammen mit einem fertigen Hinweistext. Ändert sich das Kontingent, ändert sich die
Anzeige mit – ohne dass eine neue Fassung der Erweiterung ausgeliefert werden muss. Die aktuelle
Stufe steht im Backend unter *BlueBranch Chatbot → Trainierte Seiten* oben auf der Seite.

Ist das Kontingent erschöpft, antwortet die API mit **HTTP 429**. Besucher sehen dann eine
neutrale Meldung, sie sollten es gleich noch einmal versuchen; der ausführliche Grund samt Stufe
landet im Log der Contao-Installation und nicht im Chatfenster.

## Wie die Anfragen laufen

Der Browser ruft ausschließlich Contao-Routen auf, die ihrerseits die API ansprechen:

| Route | Aufgabe |
|---|---|
| `/bluebranch/chatbot/api/v1/chat/stream` | Antwort im Chat-Modus (kurz, schnell) |
| `/bluebranch/chatbot/api/v1/generate/stream` | Antwort im Such-Modus (ausführlich) |
| `/bluebranch/chatbot/api/v1/generate/search` | Antwort ohne Streaming |

Alle drei verlangen einen Sitzungs-Token, den die Module beim Rendern in die Session legen. Die
Antworten kommen als Server-Sent Events zurück: zuerst ein `sources`-Ereignis mit den verwendeten
Seiten, danach die Antwort in Stücken, zum Schluss ein `end`-Ereignis.

Wird die Erweiterung hinter einem Reverse Proxy betrieben, muss dieser das Puffern für diese
Routen abschalten (`proxy_buffering off` bei nginx) – sonst kommt die Antwort erst am Stück und
der Streaming-Effekt entfällt.

## Aktualisieren

Nach dem Einspielen einer neuen Fassung sind **zwei** Schritte nötig, sonst ändert sich nichts:

1. *System-Wartung* → **Anwendungscache leeren**
2. *System-Wartung* → **Datenbank aktualisieren**

Per Konsole:

```bash
composer dump-autoload                                   # nur bei Installation über Composer
php vendor/bin/contao-console cache:clear --env=prod
php vendor/bin/contao-console contao:migrate
```

> **Ohne Schritt 1 sieht es aus, als sei nichts angekommen.** Contao kompiliert seinen
> Dienst-Container nach `var/cache/prod/` und liest ihn danach nur noch. Eine neu hochgeladene
> Klasse existiert für die Anwendung schlicht nicht: Ihre Callbacks werden nicht registriert,
> Felder erscheinen weiter wie zuvor – und es gibt keine Fehlermeldung, denn für Contao ist alles
> in Ordnung.
>
> Ob eine Klasse angekommen ist, zeigt
> `php vendor/bin/contao-console debug:container ApiKeyFieldListener --env=prod` – die Zeile
> *Tags* muss `contao.callback` nennen.

> **Die Befehle als Webserver-Benutzer ausführen**, meist `www-data`. Als `root` gestartet gehört
> das neu angelegte Cache-Verzeichnis hinterher root, der Webserver kann nicht mehr
> hineinschreiben, und die Website antwortet nur noch mit 500 – ohne Eintrag im Anwendungslog,
> weil der Fehler vor dem Framework passiert. Über den Contao Manager kann das nicht schiefgehen.

## Vorteile für Redakteur:innen und Entwickler

- Antworten aus dem eigenen Bestand: Die KI erfindet nichts, sie zitiert deine Seiten – mit Quellenangabe
- Kein separates Tool: Wissensbasis, Widget und Auswertung liegen im Contao-Backend
- Der API-Schlüssel verlässt den Server nie – der Browser sieht ihn zu keinem Zeitpunkt
- Redaktionelle Kontrolle: Einzelne Seiten und ganze Zweige lassen sich von den Antworten ausnehmen
- Kein Server-Cronjob nötig: Die Bereinigung läuft über Contaos eigenes Cron-System
- Das Widget weist Besucher auf private Chats und Hosting in Deutschland hin

# BlueBranch Chatbot – AI chat and AI search for Contao (EN)

Answer visitor questions directly on your Contao website – with an AI that knows nothing but your
own page content.

During the search index run, the extension hands your page content to the Chatbot API, which
builds a vector knowledge base from it. Questions are answered from exactly that content – as a
collapsible chat widget and as a summarising answer above the Contao search results.

The API key stays on the server: the browser only ever talks to Contao, and Contao talks to the
API.

## How the Contao Integration Works

After installation, all you need is an API key – and you are ready to go:

1. Install the extension using `$ composer require bluebranch/chatbot` or via the Contao Manager
2. Register at [chatbot.bluebranch.de](https://chatbot.bluebranch.de)
3. Generate your API key and store it on the website's root page
4. Rebuild the search index – this trains your content
5. Add a front end module

That's it!

## Requirements

- PHP 8.0 or newer
- Contao 4.13 or 5.x
- A chatbot account with an API key from [chatbot.bluebranch.de](https://chatbot.bluebranch.de)

## Setup

### 1. Store the API key

The key is set per website: *Site structure* → edit the website's root page → field
**Chatbot API-Key**. In an installation with several websites, each one gets its own key and
therefore its own knowledge base.

A stored key is **never written into the form**. The field only ever contains a placeholder – in
the backend page source, in the browser history and in any cache as well. `hideInput`
additionally shows dots instead of characters.

| Input | Effect |
|---|---|
| Leave the placeholder | The key is kept |
| Empty the field | The key is deleted |
| Enter something else | It is stored as the new key |

### 2. Train your content

The knowledge base is filled through the Contao search index. As soon as the crawler runs
(*System → Rebuild the search index*), every indexed page is passed to the API.
**Whatever is not in the search index is unknown to the chatbot.**

> If the installation runs in the `dev` environment, Symfony adds an `X-Robots-Tag: noindex`
> header to every response. The Contao crawler then skips all pages and nothing is trained. Set
> `APP_ENV=prod` for a training run.

### 3. Add a module

Two front end modules are available under *Themes → Front end modules*:

| Module | Purpose |
|---|---|
| *Chatbot Widget* | Collapsible chat button, bottom right by default |
| *Chatbot Generate Search* | Summarising AI answer above the search results |

Both are added to a layout or an article as usual.

## Global settings

Defaults for all chat widgets are set under *System → Settings*. Every module can override them
individually.

| Setting | Effect |
|---|---|
| Chatbot name | Shown in the chat header |
| Accent colour | Colour of the widget, as a hex value with colour picker |
| Icon (SVG) | Replaces the default AI icon in the chat button |
| Default greeting | Shown when the chat is opened for the first time |
| Default suggestions | Suggested questions as pills above the input field |
| Hide "summarise content" pill | Hides the summarise function |
| Hide privacy note | Hides the "Private chats & Hosted in Germany" note |

**Automatic clean-up.** Pages that are no longer published, excluded from the search or expired
via their start/stop date do not belong in the AI index. Expiry by date happens without the
record being saved and would otherwise go unnoticed. The interval is configurable: hourly, every
6 hours, daily (default) or weekly. The run hooks into Contao's own cron system – no server
cronjob is required, it is enough that the website is visited regularly.

**Debug files.** Off by default. When enabled, the extension writes a JSON file to `var/chatbot/`
for every training and deletion operation. This is meant for troubleshooting, not for permanent
operation: one crawler run produces a file per page including its full content, and nothing ever
cleans them up.

## Excluding pages from AI answers

Next to *Exclude from search* the page settings offer **Exclude from AI answers**. Both decide
the same thing – whether a page serves as a source – but for two different searches, and they are
independent of each other:

| | Contao full text search | AI answers |
|---|---|---|
| *Exclude from search* | no | no |
| *Exclude from AI answers* | yes | no |
| both | no | no |

A contact page can therefore appear in the full text search and still stay out of the chatbot
answers – or the other way round.

**The setting is inherited by all subpages.** Ticking a section means the whole branch. On saving,
the affected pages are removed from the knowledge base immediately, not only at the next clean-up
run.

## Excluding areas from the index

Two content elements delimit areas that should stay out of the search index:

- *Indexer: Stop* – nothing is indexed from here on
- *Indexer: Continue* – indexing resumes

These markers are deliberately **ignored** for the AI knowledge base, so that news lists and
similar dynamic areas remain answerable. They apply to the classic Contao search.

## Reviewing trained content

The back end module *BlueBranch Chatbot → Trained pages* shows what the knowledge base actually
contains – per website, with title, URL, number of chunks, language and training date. Individual
entries or the entire stock can be deleted there. The built-in test field lets you ask the chatbot
a question and check the answer including its sources without opening the website.

## Usage tiers

An account is **Free**, **Pro** or **Expert**. The difference lies solely in the request quota of
the answer routes; training content, listing content and creating API keys is unlimited in every
tier.

The actual numbers deliberately do **not** live in this extension. They come from the API at
runtime, together with a ready-made note. If the quota changes, the display changes with it – no
new release of the extension required. The current tier is shown under *BlueBranch Chatbot →
Trained pages* at the top of the page.

Once the quota is exhausted, the API answers with **HTTP 429**. Visitors then see a neutral
message asking them to try again shortly; the detailed reason including the tier goes to the log
of the Contao installation, not into the chat window.

## How the requests work

The browser only ever calls Contao routes, which in turn talk to the API:

| Route | Purpose |
|---|---|
| `/bluebranch/chatbot/api/v1/chat/stream` | Answer in chat mode (short, fast) |
| `/bluebranch/chatbot/api/v1/generate/stream` | Answer in search mode (detailed) |
| `/bluebranch/chatbot/api/v1/generate/search` | Answer without streaming |

All three require a session token that the modules put into the session while rendering. Answers
come back as server-sent events: first a `sources` event listing the pages used, then the answer
in chunks, and finally an `end` event.

When running behind a reverse proxy, buffering has to be switched off for these routes
(`proxy_buffering off` for nginx) – otherwise the answer arrives in one piece and the streaming
effect is lost.

## Updating

After installing a new release, **two** steps are required, otherwise nothing changes:

1. *System maintenance* → **Purge the application cache**
2. *System maintenance* → **Update database**

On the command line:

```bash
composer dump-autoload                                   # only when installed via Composer
php vendor/bin/contao-console cache:clear --env=prod
php vendor/bin/contao-console contao:migrate
```

> **Without step 1 it looks as if nothing arrived.** Contao compiles its service container into
> `var/cache/prod/` and only reads it from then on. A freshly uploaded class simply does not exist
> for the application: its callbacks are not registered, fields still look the way they did – and
> there is no error message, because as far as Contao is concerned everything is fine.
>
> `php vendor/bin/contao-console debug:container ApiKeyFieldListener --env=prod` shows whether a
> class arrived – the *Tags* line has to mention `contao.callback`.

> **Run the commands as the web server user**, usually `www-data`. Started as `root`, the newly
> created cache directory ends up owned by root, the web server can no longer write to it, and the
> website answers with 500 only – without an entry in the application log, because the error
> happens before the framework. This cannot go wrong via the Contao Manager.

## Benefits for editors and developers

- Answers from your own content: the AI invents nothing, it quotes your pages – with sources
- No separate tool: knowledge base, widget and review all live in the Contao back end
- The API key never leaves the server – the browser never sees it
- Editorial control: individual pages and whole branches can be excluded from answers
- No server cronjob required: clean-up runs through Contao's own cron system
- The widget tells visitors about private chats and hosting in Germany

## Vielen Dank

Unser Team dankt für die Unterstützung und das Benutzen vom BlueBranch Chatbot.

Das Team von [www.bluebranch.de](https://www.bluebranch.de/)

<3

## Lizenz

MIT – siehe [LICENSE.txt](LICENSE.txt).

## Changes

### 1.0.0 - 2026-09-02

- Erste Veröffentlichung
- Chat-Widget als aufklappbarer Button, konfigurierbar in Name, Akzentfarbe, Icon, Begrüßung und Vorschlagsfragen
- Zusammenfassende KI-Antwort über der Trefferliste der Contao-Suche
- Training der Wissensbasis über den Contao-Suchindex, je Website getrennt
- Backend-Modul "Trainierte Seiten" mit Übersicht, Einzel- und Komplettlöschung sowie Test-Feld
- API-Schlüssel je Startpunkt, der nie ins Formular und nie an den Browser gelangt
- Seiten und ganze Zweige über "Aus den KI-Antworten ausschließen" von den Antworten ausnehmen
- Automatische Bereinigung des KI-Index über Contaos Cron-System, Intervall wählbar
- Anzeige der Nutzungsstufe und saubere Behandlung erschöpfter Kontingente (HTTP 429)
- Optionale Debug-Dateien unter `var/chatbot/` zur Fehlersuche
- Streaming der Antworten als Server-Sent Events
- Fix: Backend-Seite "Trainierte Inhalte" lief unter Contao 5 in einen Fatal Error, weil
  `AbstractController::$container` seit Symfony 6 typisiert ist und vor `setContainer()` nicht
  gelesen werden darf; zudem zeigte der Import von `AsController` auf einen Namespace, den es
  weder in Contao 4.13 noch in 5.x gibt
