# Stand und offene ToDos

Stand: 2026-10-29, Version **1.6.0** (Tag `1.6.0`: Backend-Assistent „Erste Schritte“ und „Chat auf allen Seiten anzeigen“).
1.5.1 brachte nur Packagist-Texte und README mit Screenshots, 1.5.0 die Spracheingabe und das neue Standard-Design für Widget,
Frage-Modul und Suche. Davor: 1.4.1 vom 12.10.2026.
Der neue Contao-Manager-Eintrag ist als Pull Request #839 an `contao/package-metadata` offen (noch nicht gemergt).
Anforderungen, Entscheidungen und Testprotokoll des Ausbaus: [ausbau-2026-10.md](ausbau-2026-10.md).
Die Pfade dort beziehen sich auf das Testprojekt `contao57` (`extensions/bluebranch/chatbot`).

Zusammenspiel der Repositories:

| Repository | Stand | Dokument |
|---|---|---|
| `BlueBranch-GmbH/chatbot-contao` (dieses) | 1.4.1 | dieses Dokument |
| `BlueBranch-GmbH/chatbot-wordpress` | 1.2.1 | `docs/stand-und-todos.md` |
| `spardorf-chatbot-api` | 0.0.62 (live: Stripe-Abos, Whisper) | `docs/11-stand-und-todos.md`, `docs/12-stripe-und-abos.md` |
| `spardorf-chatbot-frontend` | 0.1.29 | `docs/stand-und-todos.md` |

## Unveröffentlicht (nach 1.6.0)

Lokal committet, **nicht gepusht und nicht getaggt**. README-Changelog: „Unveröffentlicht (nach 1.6.0)“.

| Bereich | Stand | Getestet |
|---|---|---|
| **Mikrofon im Frage-Modul und in der Suche** (Variante mit eigenem Suchfeld, `_full`): Knopf im Feld, Wellen statt Feld bei der Aufnahme, erkannter Text startet Frage bzw. Suche; `ChatbotVoiceField` (gemeinsame Logik), `VoiceInput::forModule()`, Feld `chatbot_voice` in beiden Paletten | fertig | Chrome mit gesprochener Datei: Frage-Modul in 5.7, 5.3, 4.13; Suche in 5.7 und 5.3; Abbrechen (× und Escape), Stille, verweigertes Mikrofon; mobil in 5.7; Widget nach der Änderung erneut in allen drei |
| Suche ohne eigenes Feld (nur KI-Antwort über der Contao-Suche) | bewusst ohne Mikrofon | Feld gehört dem Contao-Suchmodul |
| **Rückwärtskompatibilität geprüft:** Projekt-Templates aus 1.3.0, 1.4.1 und 1.5.1 laufen mit dem aktuellen Code unverändert (Widget, Frage, Suche, Quellen, Feedback); Templates aus 1.2.4 und älter brechen (fehlende Signatur, seit 1.3.0 Pflicht). Seiten rendern auch ohne die neuen Datenbankfelder. Neu: `TemplateCheck` in *Erste Schritte* und Fehler-Eintrag im Log bei fehlender Signatur | fertig | Browser: Contao 5.7 (alle vier Template-Stände), Prüfung in 5.3 und 4.13; kein PHP-8.1-only-Syntax im Code (Konfiguration erlaubt PHP 8.0) |
| **Backend-Masken überarbeitet** (Module: Gruppen *Aussehen/Texte/Funktionen*, Hinweis in der Such-Maske, Name *Chatbot KI-Suche*; Inhaltselemente vereinfacht und umbenannt; Zusatzinhalte: Liste ohne Buchstaben-Gruppen, eine Gruppe weniger; Einstellungen in drei Gruppen; kürzere Hilfetexte DE/EN) | fertig | Browser (Backend, eingeloggt als Test-Admin auf `localhost`): alle Masken in 5.7, Module/Einstellungen/Zusatzinhalte/Erste Schritte in 5.3 und 4.13 ohne Fehler |

## Was 1.6.0 enthält

| Bereich | Stand | Getestet |
|---|---|---|
| **Backend-Seite „Erste Schritte“** (`StartController`, `start.html.twig`): Key eintragen und prüfen, Trainingsstand, Chat anzeigen, Testfrage, Voreinstellungen; erster Punkt der Navigation | fertig | Playwright im Backend (Login per Test-Admin auf `localhost`): Contao 5.7, 5.3, 4.13 |
| **„Chat auf allen Seiten anzeigen“** (`GlobalWidget`, `GlobalWidgetListener`, Einstellung `chatbot_global_widget`, Seitenfeld `chatbot_hide_widget`) | fertig | 5.7: auf Such- und Eventseite eingesetzt, kein Doppel auf der Startseite, Seite ausgenommen, wieder aus; 5.3/4.13: ein/aus ohne Doppel |
| Abgelehnter Key wird nicht übernommen (altes bleibt), Formatprüfung | fertig | ungültiger und zu kurzer Key |
| **Neugestaltung der Seite** (Kopfbanner mit Fortschrittsring, Zeitleiste, Karten mit Statusanzeigen, Kennzahl-Chips, Schalter statt Checkboxen, Antwort im Chat-Look; Abstände zum Rand, Dark-Mode über Contao-Variablen) | fertig | Browser: Hell und Dunkel, Backend 5.7; Funktionstest in 5.7, 5.3, 4.13 erneut |
| Fix beim Bau: `loadDetails()` sperrt das Speichern des Seitenmodells → Wiederherstellen des Keys direkt per SQL | behoben | Test hatte den Fehler aufgedeckt |
| Fix beim Bau: `Config::persist()` schreibt erst nach der Weiterleitung → `Config::save()` sofort | behoben | Zustand direkt nach dem Klick richtig |

Offen dazu:

- [ ] **Texte der Seite** stehen fest im Twig (Deutsch) – bei der nächsten Runde in `TL_LANG` ziehen (bekanntes ToDo).
- [ ] **Seitencache** wird beim Ein-/Ausschalten nicht geleert (Hinweis auf der Seite); eine Cache-Invalidierung wäre schöner.
- [ ] Schritt 2 führt nur zum Suchindex-Aufbau; ein **„Jetzt trainieren“-Knopf** direkt im Assistenten wäre der nächste Schritt.
- [ ] Der Test fand nur mit Chrome statt (Hell- und Dunkel-Modus des Backends sind angesehen und in Ordnung).

## Was 1.5.0 enthält

| Bereich | Stand | Getestet |
|---|---|---|
| **Spracheingabe im Chat-Widget** (Mikrofon-Knopf → `POST /bluebranch/chatbot/api/v1/transcribe` → `POST /chatbot-ai/transcribe`) | fertig | Live-API (Expert, 60 s): Transkription eines `say`-Satzes, Route mit Token, Signatur, Modul an/aus, fehlender Datei; Browser (Chrome) mit künstlichem Mikrofon-Signal |
| Schalter: Vorgabe in *Einstellungen* (aus), je Modul *Standard/An/Aus* (`tl_module.chatbot_voice`) | fertig | Knopf fehlt im HTML und Route antwortet 403, wenn aus |
| Fähigkeit aus `GET /user/me/tier` (`transcriptionEnabled`, `maxAudioSeconds`), 10 Min. im `cache.app` (`VoiceInput`) | fertig | Live-API |
| Wellen-Leiste während der Aufnahme (Web Audio `AnalyserNode`), Abbrechen per × / Escape, „Wird verstanden …“ | fertig | Browser, künstliches Signal |
| Stille-Sperre: Spitzenpegel unter 0,04 → kein Upload, kein Kontingent | fertig | Browser, stilles Signal: 0 Uploads |
| `project_paused` (403) → neutraler Besuchertext (Stream- und Nicht-Stream-Route) | fertig | nicht gegen ein echtes pausiertes Projekt |
| Neues Standard-Design des Widgets (Kopf mit Avatar und Verlauf, getönter Verlauf, Pillen-Eingabe, aus der Akzentfarbe abgeleitete Töne) | fertig | Browser (Desktop, Standardfarbe) |
| README: Tarife (Free/Pro/Expert, Stripe im Frontend, Enterprise entfällt), Spracheingabe, Datenschutz, Abschnitt „Aussehen“ | fertig | – |
| **Frage-Modul und Suche: neues Standard-Design** (Pillen-Suchfeld, Antwortkarte, Quellen-Pillen, Trefferkarten), Akzentfarbe aus den Einstellungen (`Appearance`), *Kein Standard-Styling* auch dort | fertig | Browser: Contao 5.7, 5.3 und 4.13 (Desktop, 5.7 mobil) |
| Statuszeile, „Quellen:“ und Fehlertext von Frage/Suche aus den Sprachdateien (`FeedbackStrings`) | fertig | Browser: Englisch (5.7), Deutsch (5.3, 4.13) |
| Fix: Widget stand im Contao-Suchindex (`indexer::stop`) | fertig | Suchtreffer in 5.3 |
| Fix: Widget scrollt nach Quellen und Feedback ganz nach unten | fertig | Referenz-Screenshots |
| 415 (Audioformat) mit eigener Meldung; zu lang (413) und zu groß (4 MB) | fertig | Route per curl: 413 `audio_too_long` (130 s Datei), 413 (5 MB), 415 |
| **Contao 5.7, 5.3 und 4.13** (PHP 8.3 / 8.2 / 8.1) | Spracheingabe läuft in allen dreien | 29.10.2026, Chrome (Playwright, `--use-file-for-fake-audio-capture` mit einer per `say` gesprochenen Frage): Aufnahme, Wellen, Upload (200), Frage wortgenau erkannt, Antwort; Desktop 1280 × 800 und mobil 390 × 844, keine JS-Fehler (404 nur das fehlende Favicon der Testseiten) |

Nach dem Update: `composer update`, `contao:migrate` (neues Feld `tl_module.chatbot_voice`),
**Seiten-Cache leeren**. Angepasste Templates `mod_chatbot_widget.html5` brauchen für das Mikrofon den
Knopf `.chatbot-widget__voice` im Formular und `voice` in der Konfiguration – am einfachsten aus dem
Template der Erweiterung übernehmen.

**Offen zur Spracheingabe und zum Design (nach 1.5.0):**

- [ ] **Echtes Mikrofon testen.** Getestet ist der ganze Weg mit einer synthetisch gesprochenen Frage
      (Stimme „Anna“) als Mikrofon; offen sind Erkennungsqualität, Pegelschwelle (0,04) und die
      Stille-Sperre mit echter Sprache und verschiedenen Geräten
      (Safari/iOS nimmt als `audio/mp4` auf, Firefox als `audio/ogg`).
- [ ] **Design:** andere Widget-Positionen (links, oben) und sehr dunkle Seiten nicht angesehen; mobil nur
      das Widget (Desktop-Modul in 5.7 mobil gesehen). Wer das Widget mit eigenem CSS stark umgebaut hat,
      prüft es nach dem Update.
- [ ] **Contao-Backend** (neue Felder *Spracheingabe*, *Kein Standard-Styling* für Frage/Suche, Vorgabe in den
      Einstellungen) nie in der Oberfläche geöffnet; Felder und Texte nur per Lint und SQL geprüft.
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
