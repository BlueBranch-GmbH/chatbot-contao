# Sicherheits-Maßnahmenplan

Stand: 16.09.2026. Anlass war ein bekanntes Fehlermuster bei Rollenprüfungen: Ein Guard liest
die Berechtigungs-Metadaten nur vom Handler, nie von der Controller-Klasse — jedes angemeldete
Konto erreicht dann alle Admin-Routen. Geprüft wurde, ob dasselbe Muster hier steckt; der
Durchgang lief danach über die gesamte API dieser Erweiterung weiter.

**Alle Befunde sind mit 1.3.0 behoben** (siehe Changelog im README, Abschnitt *Sicherheit*). Das
Dokument bleibt als Begründung der damaligen Maßnahmen stehen.

Kennung `C-` = eigener Durchgang an dieser Erweiterung.

---

## 0. Ergebnis vorweg: das Guard-Muster ist hier nicht vorhanden

Das Muster „Berechtigung an der einen Stelle deklariert, Prüfung liest die andere" kann hier nicht
auftreten, weil es keine Guard-Abstraktion gibt. Jede schreibende Route prüft von Hand und
sichtbar im Methodenrumpf:

| Route | Prüfung | Fundstelle |
|---|---|---|
| `DELETE …/chatbot/api/v1/content` | `isAdmin()` + Sitzungs-Token | `ChatbotAPIController.php:83-93` |
| `DELETE …/chatbot/api/v1/content/{externalId}` | `isAdmin()` + Sitzungs-Token | `ChatbotAPIController.php:108-118` |
| Backend-Modul „Trainierte Inhalte" | `isAdmin()` | `TrainedContentController.php:50-53` |

Beide DELETE-Routen liegen zusätzlich unter `%contao.backend.route_prefix%`, laufen also ohnehin
durch die Contao-Backend-Firewall. `_token_check => false` ist dort vertretbar, weil der
Sitzungs-Token die CSRF-Rolle übernimmt (`hash_equals`, korrekt verwendet).

---

## 1. Befundübersicht

| # | Befund | Schwere | Status |
|---|--------|---------|--------|
| **C-01** | `pageId` ungeprüft → fremder API-Schlüssel und fremder Vektor-Index wählbar | mittel–hoch | behoben (1.3.0) |
| **C-02** | Keine Ratenbegrenzung auf den öffentlichen Antwort-Routen | mittel | behoben (1.3.0) |
| **C-03** | Geschützte Seiten landen ohne Gruppenfilter im KI-Index | mittel | behoben (1.3.0) |
| **C-04** | `marked` ohne Sanitizer an `innerHTML` | mittel | behoben (1.3.0) |
| **C-05** | `botName` unescaped im Widget-Template | mittel–niedrig | behoben (1.3.0) |
| **C-06** | Prompt, Gesprächsverlauf und Token in Query-Strings und Logs | niedrig (DSGVO-relevant) | behoben (1.3.0) |
| **C-07** | Kleinigkeiten: toter Berechtigungs-Code, fehlendes `rawurlencode`, `JSON_HEX_TAG` | niedrig | behoben (1.3.0) |

### Geprüft und in Ordnung

- **Autorisierung der schreibenden Routen:** siehe Abschnitt 0.
- **SQL-Injection:** `PageEligibility` arbeitet durchgängig mit Platzhaltern, auch bei den
  dynamisch aufgebauten `IN (...)`-Listen (`isExcludedFromAnswers`, `branchIds`).
- **API-Schlüssel im Frontend:** Der Browser sieht ihn zu keinem Zeitpunkt; `ApiKeyFieldListener`
  hält ihn sogar aus dem Backend-Formular heraus (`hideInput` allein genügt dafür nicht).
- **CSRF auf den schreibenden Routen:** Sitzungs-Token im Header, `hash_equals`.
- **Pfad-Traversal:** über die Symfony-Route nicht erreichbar (kein `/` im Routenparameter).
- **`indexer::stop`:** Contao entfernt diese Bereiche **vor** dem Hook (`Search.php:99-125`), das
  `$ignoreIndexerMarkers = true` im `IndexPageListener` kann sie also nicht zurückholen.

---

## 2. P1 — zuerst

### P1.1 — C-01: `pageId` an den Auftritt binden

`ChatbotAPIController.php:203-206` (Stream) und `:62-65` (Suche): die `pageId` kommt aus Query
bzw. Rumpf und geht ungeprüft in `PageModel::findById()`. `ChatbotAPI::getApiKey()`
(`ChatbotAPI.php:195-209`) läuft von dort über `rootId` zum `chatbot_api_key` der Startseite.

Damit wählt der Aufrufer den Schlüssel. In einer Installation mit mehreren Startpunkten reicht ein
Sitzungs-Token von Seite A, um Anfragen auf Kosten von Seite B zu stellen — und die Antworten
kommen aus **B's** Vektor-Index. Nichts prüft, ob die gewählte Seite zu dem Auftritt gehört, der
gerade ausgeliefert wird.

Der Kommentar in `:45-50` beschreibt das Problem bereits, aber der Sitzungs-Token löst es nicht:
den bekommt jeder anonyme Besucher beim ersten Seitenaufruf (`StreamToken::forSession`, in jedem
Modul-Template ausgegeben). Er belegt „war schon mal auf der Seite", nicht „darf diesen Schlüssel
benutzen".

**Maßnahme:** Beim Rendern die erlaubte `rootId` neben den Token in die Session legen und in
`resolveGenerationPayload()` dagegen prüfen. Dann ist der Token an genau den Auftritt gebunden,
aus dem er stammt. `StreamToken` ist der richtige Ort dafür — er kapselt den Session-Zugriff schon.

### P1.2 — C-02: Ratenbegrenzung

Es gibt keinerlei Drosselung. Ein Token, eine Schleife, und das Kontingent ist weg; die
429-Behandlung in `buildStreamedResponse` (`ChatbotAPIController.php:255-275`) ist die einzige
Reaktion, und sie kommt erst, wenn es zu spät ist.

Da die Antwort-Routen bewusst öffentlich sind, ist die Drosselung die eigentliche Schutzschicht —
nicht der Token.

**Maßnahme:** `symfony/rate-limiter` je Sitzung **und** je IP vor den drei Antwort-Routen.
Die Grenze gehört in die globalen Einstellungen, nicht fest in den Code.

### P1.3 — C-03: Geschützte Seiten aus dem KI-Index halten

Der `indexPage`-Hook bekommt `$arrData['protected']` und `$arrData['groups']`
(`Search.php:55-57`). `IndexPageListener::__invoke()` wertet beides nicht aus: geprüft werden
`published`, `noSearch`, Zeitfenster und `chatbot_noAnswers` (`PageEligibility::isEligible`),
aber nie `protected`.

Ist `indexProtected` aktiv — Contao ruft den Hook für geschützte Seiten sonst gar nicht auf
(`DefaultIndexer.php:84-86`) —, geht Mitglieder-Inhalt an die Vektor-Datenbank und wird anonymen
Besuchern über den Chatbot beantwortet.

Der Kontrast steht im selben Repository: die native Suche filtert im selben Fall korrekt nach
Mitgliedsgruppen (`ChatbotGenerateSearchController.php:116-123`), der KI-Weg nicht: Daten
über einen zweiten Pfad erreichbar, an dem die Berechtigungsprüfung des ersten fehlt.

**Maßnahme:** In `IndexPageListener::__invoke()` bei `protected` aussteigen und die Seite aus dem
Index löschen — so, wie es der Ausschluss über `chatbot_noAnswers` in `:53-56` bereits tut. Nach
dem Fix einmal `deleteAllContent` und neu indizieren, sonst bleibt der Altbestand stehen.
`PageEligibility::isEligible()` muss `protected` mitführen, damit der Cronjob dieselbe Regel kennt.

---

## 3. P2 — danach

### P2.1 — C-04: `marked` ohne Sanitizer

`chatbot.js:327`, `:413` und `search.js:173` schreiben `marked.parse(...)` nach `innerHTML`.
marked v15 hat keine `sanitize`-Option mehr (seit v7 entfernt), rohes HTML im Markdown geht
unverändert durch, DOMPurify ist nicht eingebunden.

Die Antwort stammt aus der eigenen API, ist aber vom indizierten Seiteninhalt geprägt. Auf einem
Auftritt mit fremdbefüllten Inhalten (Kommentare, Einreichungen, externe Redakteur:innen) ist das
ein Prompt-Injection-Pfad zu XSS. Ob das Modell mitspielt, ist die offene Frage — die Senke ist
offen. Verschärfend: `loadHistory()` (`chatbot.js:89-107`) rendert den in `localStorage`
abgelegten Verlauf bei jedem Seitenaufruf erneut durch dieselbe Senke, ein Treffer bleibt also.

**Maßnahme:** DOMPurify vor `innerHTML`, oder `marked` mit einem Renderer, der HTML-Blöcke und
-Inlines verwirft. Dazu in `search.js:187-189` `rel="noopener noreferrer"` ergänzen und
`source.url` gegen `http(s):` prüfen, bevor es in `href` landet (`javascript:`).

### P2.2 — C-05: `botName` escapen

`mod_chatbot_widget.html5:14` und `:21` geben `<?= $this->botName ?>` roh aus, einmal im
Textknoten, einmal in `aria-label="…"`. Das Feld hat `decodeEntities => true`
(`tl_module.php:37`, `tl_settings.php:12`). Tags fängt Contaos `Input::post()` ab,
Anführungszeichen nicht — ein Wert wie `x" onmouseover="…` bricht aus dem Attribut aus. Reicht
also von „darf Module bearbeiten" zu JavaScript auf jeder Seite mit Widget.

`$this->color` und `$this->iconUrl` landen in `:13` ebenfalls unescaped im `style`-Attribut; dort
rettet aktuell nur `isHexColor` in der DCA — Prüfung beim Speichern, nicht bei der Ausgabe.

**Maßnahme:** `StringUtil::specialchars()` an allen drei Stellen. Kostet nichts und ist die
Contao-Konvention.

### P2.3 — C-06: Prompt und Token aus Query-Strings und Logs halten

`EventSource` kann nur GET, also stehen Prompt, `chat_context` (die letzten zehn
Gesprächsbeiträge, `chatbot.js:349-354`) und der Sitzungs-Token in der URL — und damit im
Zugriffslog des Webservers und im Browserverlauf. Dazu protokolliert
`ChatbotAPIController.php:69-73` bei jeder Suchanfrage Nutzlast, IP und User-Agent auf
`info`-Ebene, ohne Schalter.

Der README-Abschnitt „Datenschutz und Hosting" verspricht einiges über die Verarbeitung; die Logs
auf dem Contao-Server gehören in diese Betrachtung.

**Maßnahme:** Das `info`-Log hinter `chatbot_debug` legen oder auf Zeitstempel und Seiten-ID
kürzen. Für den Query-String: den Token in ein Cookie/Header verlagern geht bei `EventSource`
nicht — stattdessen im Betriebshandbuch vermerken, dass diese Routen aus dem Zugriffslog
ausgenommen gehören.

---

## 4. P3 — Kleinigkeiten

- `src/Security/ChatbotPermissions.php` wird nirgends verwendet — toter Code, der ein
  Berechtigungsmodell suggeriert, das nie verdrahtet wurde. Entweder nutzen oder löschen.
- `ChatbotAPI::deleteContent()` hängt `$id` ohne `rawurlencode()` an den Pfad
  (`ChatbotAPI.php:63`). Über die Symfony-Route nicht ausnutzbar, aber eine Kodierung kostet nichts.
- `json_encode()` in den Templates ohne `JSON_HEX_TAG`: die Standard-Maskierung von `/` verhindert
  den `</script>`-Ausbruch heute, das ist aber ein Verlass auf ein Default.

---

## 5. Reihenfolge

1. **C-01 + C-02** zusammen — die Endpunkte sind öffentlich und der Schlüssel frei wählbar; die
   beiden Maßnahmen greifen an derselben Stelle und gehören in einen Durchgang.
2. **C-03** — Datenabfluss, still und ohne Fehlermeldung. Danach Index neu aufbauen.
3. **C-04**, **C-05**, **C-06**.
4. **P3** nebenbei.

## 6. Prozess

Die Erweiterung hat keine Tests. Bei C-01 und C-03 geht es jeweils um eine Entscheidung
(„gehört diese Seite zu diesem Auftritt?", „ist diese Seite geschützt?"), die sich als reine
Funktion prüfen lässt — so wie `ApiKeyFieldListener::entscheideWert()` es vormacht. Bei diesen
beiden Befunden sollte je ein Test entstehen, der gegen den heutigen Stand fehlschlägt. Ein Mock,
der genau die fehlerhafte Stelle ersetzt, ist kein Test.
