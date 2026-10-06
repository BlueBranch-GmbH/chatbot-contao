# BlueBranch Chatbot – KI-Chatbot und KI-Suche für Contao (DE)

Beantworte Besucherfragen direkt auf deiner Contao-Website – mit einer KI, die ausschließlich
deine eigenen Seiteninhalte kennt.

Die Erweiterung übergibt die Seiteninhalte beim Suchindex-Lauf an die Chatbot-API, die daraus
eine Vektor-Wissensbasis aufbaut. Aus genau diesem Bestand werden Fragen beantwortet – als
aufklappbares Chat-Widget und als zusammenfassende Antwort über der Trefferliste der
Contao-Suche.

Der API-Schlüssel bleibt dabei auf dem Server: Der Browser spricht ausschließlich mit Contao,
Contao spricht mit der API.


> Stand der Entwicklung und offene ToDos: [docs/stand-und-todos.md](docs/stand-und-todos.md)

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
| *Chatbot Frage* | Eingabefeld mit getippten Fragen, Antwort direkt darunter |

Alle drei werden wie gewohnt in ein Layout oder einen Artikel eingebunden.

### Getippte Fragen

*Chatbot Frage* und *Chatbot Generate Search* haben beide das Feld **Getippte Fragen** — eine
Liste beliebig vieler Texte. Sie werden im Frontend nacheinander Zeichen für Zeichen als
Platzhalter ins Eingabefeld geschrieben, gelöscht und durch den nächsten ersetzt. Bleibt die
Liste leer, steht dort der statische Platzhalter.

Die Animation ruht, sobald jemand das Feld anklickt oder etwas hineinschreibt, und im
Hintergrund-Tab läuft sie gar nicht erst. Wer in seinem System *reduzierte Bewegung* eingestellt
hat, sieht statt der Animation die erste Frage unbewegt stehen.

*Chatbot Frage* ist für Startseiten gedacht: Eine eingegebene Frage geht ohne Seitenwechsel an
die KI, die Antwort erscheint samt Quellen direkt darunter — wie beim Such-Modul, nur ohne die
Trefferliste der Contao-Suche.

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

In den Seiteneinstellungen steht neben *Von der Suche ausschließen* (ab Contao 5.6: *Suchindexierung*) das Feld
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

## Fragen, Antworten und Feedback

**Speichern:** Unter *System → Einstellungen → Fragen und Antworten* lässt sich einschalten, dass
jede Frage samt Antwort in der Tabelle `tl_chatbot_log` landet – für spätere Auswertungen. Es
wird **keine** IP-Adresse, kein User-Agent und keine Sitzungskennung gespeichert; E-Mail-Adressen,
Telefonnummern und IBANs in Fragen und Kommentaren werden maskiert. Die Aufbewahrung ist
einstellbar (Standard 180 Tage, 0 = unbegrenzt).

**Feedback:** Unter *System → Einstellungen → Fragen, Antworten und Feedback* steht die Vorgabe
*Feedback zu Antworten abfragen*; in jedem Modul (Widget, Frage, Suche) lässt sie sich mit
*Feedback abfragen* auf „An“ oder „Aus“ setzen.
Unter jeder Antwort erscheinen dann 👍/👎; beim Daumen nach unten kann der Besucher kurz angeben,
was nicht gepasst hat (max. 1.000 Zeichen, ohne HTML). Eine bewertete Antwort wird auch dann
samt Frage gespeichert, wenn das Speichern aller Fragen aus ist.

**Ansehen und exportieren:** *BlueBranch Chatbot → Fragen & Feedback* listet die Einträge mit
Filtern (Bewertung, Quelle, Website, Suche), Löschen und **CSV-Export** (UTF-8, Semikolon,
Excel-tauglich; Formeln werden entschärft).

## Chat exportieren und Verlauf

Besucher können ihren Chat über das Download-Symbol im Kopf des Widgets als **Text (.txt)** oder
**WebVTT (.vtt)** herunterladen – die Datei entsteht im Browser. Der Verlauf im `localStorage`
enthält jetzt auch die Antworten samt Quellen und Bewertung; eine Antwort, die beim Seitenwechsel
noch lief, steht danach mit dem Hinweis „Antwort unterbrochen“ da.

## Zusatzinhalte

*BlueBranch Chatbot → Zusatzinhalte* nimmt Inhalte ohne eigene Seite auf: **Texte** für
Meta-Infos (Öffnungszeiten, Ansprechpartner, Hinweise) und **Dateien** (TXT, MD, CSV, PDF, DOCX,
ODT, HTML; max. 20 MB). Der Text wird auf dem eigenen Server ausgelesen (PDF über
`smalot/pdfparser`), an die API geht **nur Text** – nie die Datei – und keine URL. Solche Inhalte
erscheinen deshalb nicht als Link unter den Antworten. Speichern überträgt, Deaktivieren und
Löschen entfernt; eine im Dateimanager ersetzte Datei wird automatisch neu übertragen.

## Zeitgesteuerte Inhalte

Läuft eine Seite, ein Artikel oder ein Inhaltselement über *Anzeigen ab/bis* an oder ab, prüft
ein Cronjob das alle 15 Minuten: Nicht mehr sichtbare Seiten verlassen sofort den KI-Index, bei
geänderten Seiten wird die Seite einmal abgerufen und neu trainiert. Vorher geschah das erst beim
nächsten Besuch der Seite bzw. beim täglichen Bereinigungslauf. Der Abruf braucht, dass der
Server seine eigene Domain erreicht; sonst bleibt es beim nächsten Besuch.

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

Alle drei verlangen einen Sitzungs-Token. Die Module holen ihn erst bei der ersten Frage über
`POST /bluebranch/chatbot/api/v1/token` – Seiten mit Chatbot kommen so ohne Session aus und
bleiben im HTTP-Cache. Frage und Token gehen per POST im Rumpf bzw. Header, nicht in der URL. Die
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

## Datenschutz und Hosting

- **Hosting in Deutschland.** Server und Datenverarbeitung liegen ausschließlich in Deutschland.
- **Eigenes KI-Modell.** Die KI läuft auf eigener Infrastruktur. Deine Inhalte gehen nicht an
  OpenAI, Anthropic oder andere Drittanbieter.
- **Kein Training mit deinen Daten.** Weder Seiteninhalte noch Chatverläufe werden zum Trainieren
  von Modellen verwendet.
- **Auftragsverarbeitungsvertrag.** Ein AVV nach Art. 28 DSGVO ist möglich – auf Anfrage per
  E-Mail an lb@bluebranch.de.
- **Der API-Schlüssel bleibt auf dem Server.** Der Browser spricht ausschließlich mit Contao und
  bekommt den Schlüssel zu keinem Zeitpunkt zu sehen.

## Vorteile für Redakteur:innen und Entwickler

- Antworten aus dem eigenen Bestand: Die KI erfindet nichts, sie zitiert deine Seiten – mit Quellenangabe
- Kein separates Tool: Wissensbasis, Widget und Auswertung liegen im Contao-Backend
- Der API-Schlüssel verlässt den Server nie – der Browser sieht ihn zu keinem Zeitpunkt
- Redaktionelle Kontrolle: Einzelne Seiten und ganze Zweige lassen sich von den Antworten ausnehmen
- Kein Server-Cronjob nötig: Die Bereinigung läuft über Contaos eigenes Cron-System
- DSGVO-konform: Verarbeitung ausschließlich in Deutschland, eigenes KI-Modell, AVV möglich

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
| *Chatbot Question* | Input field with typed questions, answer right below it |

All three are added to a layout or an article as usual.

### Typed questions

*Chatbot Question* and *Chatbot Generate Search* both offer the **Typed questions** field — a list
of as many texts as you like. In the front end they are written into the input field as a
placeholder, one character at a time, then deleted and replaced by the next one. If the list stays
empty, the static placeholder remains.

The animation pauses as soon as somebody clicks into the field or types something, and it does not
run at all in a background tab. Anyone who has *reduced motion* enabled on their system sees the
first question standing still instead of the animation.

*Chatbot Question* is meant for home pages: a question is sent to the AI without a page reload and
the answer appears right below it, sources included — like the search module, only without the
Contao search results.

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

Next to *Exclude from search* (Contao 5.6 and later: *Search indexing*) the page settings offer **Exclude from AI answers**. Both decide
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

## Questions, answers and feedback

- **Storing** (*System → Settings → Questions and answers*): every question and answer is saved to
  `tl_chatbot_log` – no IP address, user agent or session ID; e-mail addresses, phone numbers and
  IBANs are masked. Retention is configurable (default 180 days).
- **Feedback** (checkbox *Ask for feedback* in each module): 👍/👎 below every answer, a short
  comment on 👎. A rated answer is stored even when storing is switched off.
- **Back end** *BlueBranch Chatbot → Questions & feedback*: filters, deletion and CSV export.
- **Chat export**: visitors download their chat as `.txt` or `.vtt`. Answers now survive page
  changes in the browser history.
- **Additional content** (*BlueBranch Chatbot → Additional content*): texts and files (TXT, MD,
  CSV, PDF, DOCX, ODT, HTML) are converted to text locally; only text is sent to the API, never a
  file and never a URL.
- **Scheduled content**: a cron job (every 15 minutes) removes expired pages and refreshes pages
  whose articles or content elements started or stopped.

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

All three require a session token. The modules only fetch it with the first question via
`POST /bluebranch/chatbot/api/v1/token`, so pages with a chatbot need no session and stay in the
HTTP cache. Question and token are sent via POST in the body and header, not in the URL. Answers
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

## Data protection and hosting

- **Hosted in Germany.** Servers and data processing are located in Germany only.
- **Our own AI model.** The AI runs on our own infrastructure. Your content is not passed on to
  OpenAI, Anthropic or any other third party.
- **No training on your data.** Neither page content nor chat histories are used to train models.
- **Data processing agreement.** A DPA under Art. 28 GDPR is available on request by e-mail to
  lb@bluebranch.de.
- **The API key stays on the server.** The browser only ever talks to Contao and never gets to see
  the key.

## Benefits for editors and developers

- Answers from your own content: the AI invents nothing, it quotes your pages – with sources
- No separate tool: knowledge base, widget and review all live in the Contao back end
- The API key never leaves the server – the browser never sees it
- Editorial control: individual pages and whole branches can be excluded from answers
- No server cronjob required: clean-up runs through Contao's own cron system
- GDPR-friendly: processing in Germany only, our own AI model, DPA available

## Vielen Dank

Unser Team dankt für die Unterstützung und das Benutzen vom BlueBranch Chatbot.

Das Team von [www.bluebranch.de](https://www.bluebranch.de/)

<3

## Lizenz

MIT – siehe [LICENSE.txt](LICENSE.txt).

## Changes

### 1.3.0 - 2026-10-06

- Fragen und Antworten optional in `tl_chatbot_log` speichern (ohne Nutzerdaten, mit Maskierung
  von E-Mail, Telefon und IBAN, einstellbare Aufbewahrung)
- Feedback 👍/👎: Vorgabe unter *Einstellungen*, je Modul „Standard / An / Aus“, bei 👎 mit kurzem Kommentar; neue Route
  `POST /bluebranch/chatbot/api/v1/feedback`, abgesichert über den Sitzungs-Token und eine
  zufällige Kennung je Antwort
- Backend-Modul *Fragen & Feedback* mit Filtern, Löschen und CSV-Export
- Chat-Export als `.txt` oder `.vtt` im Widget; der Verlauf speichert dafür Beginn und Ende jeder
  Antwort, die VTT-Datei trägt die echte Uhrzeit je Cue, Markdown wird zu reinem Text
- Antworten (mit Quellen und Bewertung) bleiben über Seitenwechsel im Verlauf, auch wenn sie
  mitten im Stream verlassen wurden
- Backend-Modul *Zusatzinhalte*: Texte und Dateien (TXT, MD, CSV, PDF, DOCX, ODT, HTML), lokal in
  Text umgewandelt; neue Abhängigkeit `smalot/pdfparser`
- Quellen ohne URL werden unter Antworten nicht mehr angezeigt
- Cronjob für zeitgesteuerte Seiten, Artikel und Inhaltselemente (alle 15 Minuten)
- Zusatzinhalte bleiben mit dem Index im Gleichstand: geänderte Datei (auch per FTP ersetzt) →
  neu übertragen, Datei gelöscht → aus dem Index, Eintrag wiederhergestellt oder alte Version
  zurückgespielt → neu übertragen; der Wechsel der Art im Formular überträgt nichts mehr
- Backend-Seiten *Trainierte Seiten* und *Fragen & Feedback* im Dark-Mode lesbar (Contaos
  Farbvariablen statt fester Farben)
- API-Adresse für Test- und Staging-Umgebungen über `CHATBOT_API_URL` einstellbar (`http://` nur für
  lokale Ziele)
- **Sicherheit** (Review vom 06.10.2026):
  - Geschützte Seiten (Mitgliederbereiche, auch geerbt) kommen nicht mehr in den KI-Index und werden
    entfernt – bisher landeten sie dort, sobald „Geschützte Seiten indexieren“ aktiv war
  - Seite und Modul einer Anfrage sind per HMAC an die gerenderte Seite gebunden; eine fremde
    `pageId` wählt nicht mehr den API-Key einer anderen Website. **Nach dem Update den Seiten-Cache
    leeren**, sonst fehlt die Signatur in zwischengespeicherten Seiten
  - Ratenlimit je Client und global für Token, Antworten und Feedback; Längengrenzen für Frage
    (4.000 Zeichen) und Verlauf (8.000); Streams höchstens 180 s
  - Backend-Stream-Routen nur für Administratoren; Token nicht mehr als URL-Parameter
  - CSV-Export: Ausbruch aus Zellen über `\"` verhindert (leeres Escape-Zeichen)
  - Bot-Name und Icon-Pfad im Widget-HTML escaped
  - Nicht-Stream-Route gibt nur Antwort und Quellen zurück und reicht nur bekannte Felder an die API
  - Antworten werden im Protokoll ebenfalls maskiert; `summarize` nur für echte Zusammenfassungen
  - Verlauf im Browser nach 24 h ohne neue Nachricht verworfen
  - Fehlerhafte Zusatzdateien werden nicht alle 15 Minuten erneut geparst; PDF-Speicherlimit
  - Cron ruft nur Seiten mit eingetragener Domain ab (kein Host-Header-SSRF)
  - „Alle neu übertragen“ nur mit Anfrage-Token; Debug-Dateien ohne API-Key
- Nach dem Update: `composer update` (für `smalot/pdfparser`), dann `contao:migrate` – neue
  Tabellen `tl_chatbot_log`, `tl_chatbot_content` und Feld `tl_module.chatbot_feedback`

### 1.2.4 - 2026-09-29

- Contao 5.6 und neuer: Die Erweiterung fragte noch das dort entfernte Feld `noSearch` ab. Der
  Bereinigungs-Cronjob scheiterte dadurch bei jedem Lauf, unveröffentlichte Seiten blieben im
  KI-Index, und die Checkbox *Aus den KI-Antworten ausschließen* fehlte im Backend. Jetzt wird je
  nach Version `noSearch` oder `searchIndexer` verwendet
- Antworten der KI werden vor der Anzeige bereinigt: nur harmlose HTML-Elemente, keine
  Skript-Attribute, keine `javascript:`-Links
- Frage, Chatverlauf und Token gehen per POST statt als URL-Parameter und landen damit nicht mehr
  in Access-Logs, Browserverlauf und Referer
- Suchanfragen werden nicht mehr mit Frage, IP-Adresse und User-Agent ins Log geschrieben
- Zeitlimits für alle Anfragen an die Chatbot-API; eine hängende Gegenstelle blockiert keine
  PHP-Prozesse mehr
- Die Übersicht der trainierten Inhalte erzeugt keinen neuen Token mehr bei jedem Aufruf – ein
  gleichzeitig offener Chatbot im selben Browser blieb sonst ohne Antwort. Titel und URLs werden
  dort als Text statt als HTML eingesetzt
- Der Hinweis auf einen fehlenden API-Schlüssel erscheint nur noch für eingeloggte Redakteure
- Die Such- und Frage-Module zeigen die Begründung der API an statt einer allgemeinen Meldung
- Skripte dürfen mehrfach auf einer Seite eingebunden sein (Frage- und Such-Modul zusammen)
- Nicht mehr genutzten Code entfernt; getestet mit Contao 4.13 (PHP 8.1) und 5.7 (PHP 8.3)

### 1.2.3 - 2026-09-29

- Sicherheitsfix: Der Suchbegriff wurde im Such-Modul ungefiltert in ein Skript geschrieben
  und ließ sich über einen präparierten Link für Cross-Site-Scripting nutzen
- Seiten werden nur noch an den KI-Index übertragen, wenn sich ihr Inhalt geändert hat
  (spätestens nach sieben Tagen erneut), statt bei jedem Seitenaufruf; ausgeschlossene Seiten
  lösen nicht mehr bei jedem Aufruf eine Löschung aus. Neue Spalten in `tl_page` – nach dem
  Update die Datenbank aktualisieren
- Die Chatbot-Module legen keine Session mehr beim Rendern an. Der Token für die Antworten wird
  erst bei der ersten Frage geholt, Seiten mit Chatbot bleiben damit im HTTP-Cache
- Skripte und Styles werden mit Versionsparameter eingebunden, damit Browser nach einem Update
  nicht die alten Dateien weiterverwenden

### 1.2.2 - 2026-09-17

- Paketbeschreibung gekürzt und mit dem Eintrag im Contao Extension Repository gleichgezogen:
  kurze Einleitung, danach die Einrichtung in Stichpunkten
- Keywords um `dsgvo` und `gdpr` ergänzt

### 1.2.1 - 2026-09-16

- Hinweisfeld in den globalen Einstellungen: Es verweist auf Zugang und API-Schlüssel auf
  chatbot.bluebranch.de sowie auf die Anleitung für Contao und stellt klar, dass der Schlüssel
  selbst am Startpunkt der Website hinterlegt wird

### 1.2.0 - 2026-09-16

- Laufende Antworten lassen sich abbrechen: Stopp-Knopf im Chat-Widget, im Modul *Chatbot Frage*,
  im Such-Modul und im Testfeld des Backend-Moduls
- Solange eine Antwort läuft, werden weitere Anfragen unterbunden
- `homepage` und der neue `docs`-Link in der composer.json zeigen auf chatbot.bluebranch.de
  statt auf das Repository

### 1.1.1 - 2026-09-02

- Fix: Die Antwort-Routen liefen in einen 500er, weil das Contao-Framework nicht initialisiert
  war, bevor `PageModel` angefasst wurde. Betraf alle Stream-Routen, auch die des Such-Moduls

### 1.1.0 - 2026-09-02

- Neues Frontend-Modul *Chatbot Frage*: Eingabefeld für Startseiten, das die Frage ohne
  Seitenwechsel an die KI schickt und die Antwort samt Quellen darunter anzeigt
- Neues Feld *Getippte Fragen* an *Chatbot Frage* und *Chatbot Generate Search*: hinterlegte
  Fragen werden Zeichen für Zeichen als Platzhalter ins Eingabefeld getippt
- Die Animation ruht bei Fokus, Eingabe, im Hintergrund-Tab und bei reduzierter Bewegung

### 1.0.2 - 2026-09-02

- Abschnitt zu Datenschutz und Hosting aufgenommen: Verarbeitung in Deutschland, eigenes
  KI-Modell, kein Training mit Kundendaten, AVV nach Art. 28 DSGVO auf Anfrage

### 1.0.1 - 2026-09-02

- Die Adresse der Chatbot-API aus den Voraussetzungen genommen; sie verweisen jetzt auf die
  Seite, auf der man sich registriert und den Schlüssel erstellt
- Beschreibung des Pakets mit dem Eintrag im Contao Extension Repository gleichgezogen

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
