# Stand und offene ToDos

Stand: 29.10.2026. Veröffentlicht ist **1.4.1** (Tag `1.4.1`, auf Packagist); darüber liegt lokal
unveröffentlichte Arbeit (Spracheingabe, neues Design, siehe unten) – **noch nicht gepusht, nicht
getaggt, keine neue Versionsnummer**.
Anforderungen, Entscheidungen und Testprotokoll des Ausbaus: [ausbau-2026-10.md](ausbau-2026-10.md).
Die Pfade dort beziehen sich auf das Testprojekt `contao57` (`extensions/bluebranch/chatbot`).

Zusammenspiel der Repositories:

| Repository | Stand | Dokument |
|---|---|---|
| `BlueBranch-GmbH/chatbot-contao` (dieses) | 1.4.1 | dieses Dokument |
| `BlueBranch-GmbH/chatbot-wordpress` | 1.2.1 | `docs/stand-und-todos.md` |
| `spardorf-chatbot-api` | 0.0.62 (live: Stripe-Abos, Whisper) | `docs/11-stand-und-todos.md`, `docs/12-stripe-und-abos.md` |
| `spardorf-chatbot-frontend` | 0.1.29 | `docs/stand-und-todos.md` |

## Unveröffentlicht (nach 1.4.1)

Änderungen im Arbeitsverzeichnis, lokal committet, **nicht gepusht und nicht getaggt**. Der
README-Changelog führt sie unter „Unveröffentlicht“; bei der Freigabe die Überschrift durch die neue
Versionsnummer ersetzen (nach Funktionsumfang 1.5.0).

| Bereich | Stand | Getestet |
|---|---|---|
| **Spracheingabe im Chat-Widget** (Mikrofon-Knopf → `POST /bluebranch/chatbot/api/v1/transcribe` → `POST /chatbot-ai/transcribe`) | fertig | Live-API (Expert, 60 s): Transkription eines `say`-Satzes, Route mit Token, Signatur, Modul an/aus, fehlender Datei; Browser (Chrome) mit künstlichem Mikrofon-Signal |
| Schalter: Vorgabe in *Einstellungen* (aus), je Modul *Standard/An/Aus* (`tl_module.chatbot_voice`) | fertig | Knopf fehlt im HTML und Route antwortet 403, wenn aus |
| Fähigkeit aus `GET /user/me/tier` (`transcriptionEnabled`, `maxAudioSeconds`), 10 Min. im `cache.app` (`VoiceInput`) | fertig | Live-API |
| Wellen-Leiste während der Aufnahme (Web Audio `AnalyserNode`), Abbrechen per × / Escape, „Wird verstanden …“ | fertig | Browser, künstliches Signal |
| Stille-Sperre: Spitzenpegel unter 0,04 → kein Upload, kein Kontingent | fertig | Browser, stilles Signal: 0 Uploads |
| `project_paused` (403) → neutraler Besuchertext (Stream- und Nicht-Stream-Route) | fertig | nicht gegen ein echtes pausiertes Projekt |
| Neues Standard-Design des Widgets (Kopf mit Avatar und Verlauf, getönter Verlauf, Pillen-Eingabe, aus der Akzentfarbe abgeleitete Töne) | fertig | Browser (Desktop, Standardfarbe) |
| README: Tarife (Free/Pro/Expert, Stripe im Frontend, Enterprise entfällt), Spracheingabe, Datenschutz | fertig | – |
| **Contao 5.7, 5.3 und 4.13** (PHP 8.3 / 8.2 / 8.1) | Spracheingabe läuft in allen dreien | 30.10.2026, Chrome (Playwright, `--use-file-for-fake-audio-capture` mit einer per `say` gesprochenen Frage): Aufnahme, Wellen, Upload (200), Frage wortgenau erkannt, Antwort; Desktop 1280 × 800 und mobil 390 × 844, keine JS-Fehler (404 nur das fehlende Favicon der Testseiten) |

Nach dem Update: `composer update`, `contao:migrate` (neues Feld `tl_module.chatbot_voice`),
**Seiten-Cache leeren**. Angepasste Templates `mod_chatbot_widget.html5` brauchen für das Mikrofon den
Knopf `.chatbot-widget__voice` im Formular und `voice` in der Konfiguration – am einfachsten aus dem
Template der Erweiterung übernehmen.

**Offen zur Spracheingabe und zum Design:**

- [ ] **Echtes Mikrofon testen.** Getestet ist der ganze Weg mit einer synthetisch gesprochenen Frage
      (Stimme „Anna“) als Mikrofon; offen sind Erkennungsqualität, Pegelschwelle (0,04) und die
      Stille-Sperre mit echter Sprache und verschiedenen Geräten
      (Safari/iOS nimmt als `audio/mp4` auf, Firefox als `audio/ogg`).
- [ ] **Frage-Modul und Suche** haben noch kein Mikrofon. Die Aufnahme (`ChatbotVoice`) und die Route
      sind schon wiederverwendbar; es fehlen Palette, Template und Anbindung in `ask.js`/`search.js`.
- [ ] **Design:** mobile Vollbild-Ansicht, andere Positionen (links, oben), dunkle Seiten und
      Frage-/Suchmodul nicht angesehen. Wer das Widget mit eigenem CSS stark umgebaut hat, prüft es
      nach dem Update.
- [ ] **Datenschutzerklärung der Website** um die Spracheingabe ergänzen (Hinweis steht im README).
- [ ] **Aufnahme bei Whisper-Halluzination:** Die Stille-Sperre fängt Stille ab, nicht leises
      Rauschen oder Fremdgeräusche (ein Testsignal wurde als „Thank you.“ erkannt). Eine kurze
      Bestätigung („Haben Sie gesagt …?“) wäre eine Option.
- [ ] **Sprachausgabe** (Antworten vorlesen, Freihand-Dialog) ist bewusst zurückgestellt; die API hat
      dafür nichts, im Browser ginge `speechSynthesis`.
- [ ] **`contao:migrate`** bricht in der Testinstanz bei `tl_message_queue` (`ENGINE =`) ab – kommt
      von Contao, nicht von der Erweiterung; die Spalte `chatbot_voice` wird trotzdem angelegt.
- [ ] Testdaten in der Instanz `contao57`: Modul „Chatbot Widget (Sprache)“ (ID 74), eingebunden im
      Artikel „AI Frage“ der Startseite.

## Was 1.4.1 enthält

| Bereich | Stand | Getestet |
|---|---|---|
| Frage-Modul: Stopp-Knopf tauscht nur `.chatbot-ask-submit-label`, Icons bleiben | fertig | Browser (Contao 4.13.50, angepasstes Template) |

Anlass: Ein angepasstes `mod_chatbot_ask.html5` von vor 1.3.0 band `chatbot-common.js` nicht ein
→ „ChatbotStreamToken is not defined“. Behoben im Projekt-Template; Hinweis im Changelog.

## Was 1.4.0 enthält

| Bereich | Stand | Getestet |
|---|---|---|
| *Trainierte Seiten*: Name der Stufe statt „Premium“, Verbrauch im laufenden Monat | fertig | Twig-Lint; **nicht im Browser** |
| 429 mit `reason: monthly_quota` → Besucher lesen „später erneut versuchen“ | fertig | PHP-Lint |

Braucht für die Verbrauchszeile API 0.0.56; mit älterer API entfällt sie. Keine Migration.

## Was 1.3.0 enthält

| Bereich | Stand | Getestet |
|---|---|---|
| Fragen/Antworten speichern (`tl_chatbot_log`, maskiert, Aufbewahrung) | fertig | Browser + Mock-API |
| Feedback 👍/👎 mit Kommentar; Vorgabe in den Einstellungen, je Modul Standard/An/Aus | fertig | Browser |
| Backend „Fragen & Feedback“ mit Filtern, Löschen, CSV-Export | fertig | curl (Login), CSV in Excel-Logik |
| Chat-Export `.txt`/`.vtt` mit Beginn/Ende jeder Antwort, Uhrzeit je Cue | fertig | Node (Ausgabe geprüft), nicht im Player |
| Verlauf mit Antworten, Quellen, Bewertung, unterbrochener Antwort; Ablauf nach 24 h | fertig | Browser |
| Zusatzinhalte (Text, TXT/MD/CSV/PDF/DOCX/ODT/HTML), lokal zu Text, ohne URL | fertig | Mock-API, alle Änderungs-/Löschfälle |
| Cronjob zeitgesteuerte Seiten/Artikel/Inhaltselemente (15 Min.) | fertig | abgelaufenes Element → neu trainiert |
| Dark-Mode der Backend-Seiten | fertig | Browser |
| Sicherheitsreview 06.10.2026 (siehe ausbau-2026-10.md) | behoben | echte API: Signatur 403, Länge 413, Ratenlimit 429 |

### Nach jedem Update auf 1.3.0

1. `composer update` (neue Abhängigkeit `smalot/pdfparser`)
2. `contao:migrate` – neue Tabellen `tl_chatbot_log`, `tl_chatbot_content`, Feld `tl_module.chatbot_feedback`
3. **Seiten-Cache leeren** – zwischengespeicherte Seiten tragen sonst keine Signatur, der Chat meldet „Bitte laden Sie die Seite neu“
4. Hinter einem Reverse Proxy: Proxy unter `trusted_proxies` eintragen, sonst teilen sich alle Besucher ein Ratenlimit

## Offene ToDos

### Sicherheit und Datenschutz

- [ ] **Contao → API per GET.** Frage und Chatverlauf gehen als Query-Parameter an
      `api.chatbot.bluebranch.de` und stehen damit in deren Zugriffslogs (und im
      `http_client`-Log von Contao). Braucht eine POST-Variante der Stream-Routen in der API
      (siehe API `docs/11-stand-und-todos.md`); danach `ChatbotAPI::streamGenerate()` umstellen
      und die Kürzung des Seitentexts beim Zusammenfassen (2.500 Zeichen) überdenken.
- [ ] **Autostart über `?keywords=`.** Das Suchmodul fragt beim Seitenaufruf sofort die KI.
      Präparierte Links zeigen eine gesteuerte Antwort auf der echten Domain (Content Spoofing,
      Kontingent). Vorschlag: Option „Antwort erst nach Klick“.
- [ ] **Protokoll ohne Mandantentrennung.** Wer das Backend-Modul „Fragen & Feedback“ sehen darf,
      sieht die Fragen aller Startseiten. Bei Installationen mit mehreren Kunden nach den
      Seitenmounts des Benutzers filtern.
- [ ] **Titel von Zusatzinhalten im Stream.** Quellen ohne URL blendet die Oberfläche aus, im
      `sources`-Ereignis (Netzwerk-Tab) stehen ihre Titel aber. Entweder serverseitig filtern
      (SSE-Frames umschreiben) oder API-seitig weglassen.
- [ ] **Gleichzeitige Streams** je Client sind nicht begrenzt, nur die Anzahl je Minute.
- [ ] **API-Key im Klartext** in `tl_page.chatbot_api_key` (und in jedem DB-Dump). Optional
      aus einer Umgebungsvariable lesen oder verschlüsselt speichern.

### Funktion und Robustheit

- [ ] **Reader-Seiten (News, Events) überschreiben sich im Index**: externe ID `page_<id>` ist je
      Seite, nicht je URL. Braucht URL-basierte IDs und Löschen per Präfix in der API.
- [ ] **Abgelaufener Sitzungs-Token wird nicht erneuert** (lange offener Tab → 403 bis zum
      Neuladen). Bei 403 Token neu holen und einmal wiederholen (WordPress macht das bereits).
- [ ] **Bereinigungs-Cronjob schreibt `localconfig.php`** bei jedem Lauf (`Config::persist`);
      scheitert auf schreibgeschützten Deployments. Zeitpunkt in `cache.app` halten wie
      `ScheduledContentCron`.
- [ ] **`indexer_continue`-Sortierung** kann mit dem nächsten Element kollidieren.
- [ ] **Begrüßung erschien einmal nach der ersten Antwort** (nicht weiter untersucht).
- [ ] **Geschützte Seiten:** Die Sperre ist im Code geprüft, aber nicht live mit
      „Geschützte Seiten indexieren“ getestet.
- [ ] **Contao 4.13** wurde für 1.3.0 nicht live getestet – im Testprojekt `contao413` fehlt
      `extensions/` (Symlink ins Leere). Code ist auf PHP 8.0 und die 4.13-APIs ausgelegt.
- [ ] **VTT im Player prüfen** (VLC/Browser-`<track>`), bisher nur der Text geprüft.

### Performance

- [ ] „Trainierte Seiten“ lädt bis zu 5.000 Abschnitte in einem Request und paginiert im Browser – serverseitig paginieren.
- [ ] `PageEligibility::parentIds()` fragt je Ebene einzeln.
- [ ] Skripte stehen im Modul-HTML und laden je Modul erneut – über `$GLOBALS['TL_BODY']` mit festem Schlüssel.
- [ ] Backend bindet Skripte mit `?v=<jetzt>` ein – wie im Frontend über `Asset::url()` versionieren.
- [ ] Bereinigungs-Cronjob fragt alle Seiten ab, auch nie trainierte.

### Codequalität

- [ ] **Hartcodierte deutsche Texte** in Templates, `search.js` und den Backend-Twig-Seiten (auch
      neu: „Fragen & Feedback“, Hinweise im Zusatzinhalte-Listener) – über `TL_LANG`.
- [ ] **Keine Tests, keine CI.** Gute Kandidaten: `ChatLog::mask()`, `StreamRecorder`,
      `TextExtractor`, `RequestSignature`, `TrainingState`, `PageEligibility`,
      `ChatbotMarkdown`/`ChatbotExport` (JS).
- [ ] Legacy-Umwege (`generate()` mit `new ModuleModel()`, optionale Konstruktor-Argumente,
      Backend-Seiten doppelt als `BE_MOD`-Callback und Route) bei der nächsten Major-Version entfernen.
- [ ] Routenname mit Tippfehler `bluebranch_chatbot_generate_seach` – nächste Major-Version.
- [ ] **Contao 4.13** bekommt keine Updates mehr; mittelfristig auf `^5.3` gehen.
