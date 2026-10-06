# Stand und offene ToDos

Stand: 06.10.2026, Version **1.3.0** (Tag `1.3.0`, Commit `0a44c5e`).
Anforderungen, Entscheidungen und Testprotokoll des Ausbaus: [ausbau-2026-10.md](ausbau-2026-10.md).
Die Pfade dort beziehen sich auf das Testprojekt `contao57` (`extensions/bluebranch/chatbot`).

Zusammenspiel der Repositories:

| Repository | Stand | Dokument |
|---|---|---|
| `BlueBranch-GmbH/chatbot-contao` (dieses) | 1.3.0 | dieses Dokument |
| `BlueBranch-GmbH/chatbot-wordpress` | 1.1.0 | `docs/stand-und-todos.md` |
| `spardorf-chatbot-api` | 0.0.55 | `docs/11-stand-und-todos.md` |
| `spardorf-chatbot-frontend` | 0.1.28 | `docs/stand-und-todos.md` |

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
