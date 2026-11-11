<?php

namespace Bluebranch\Chatbot\classes;

use Contao\Database;

/**
 * Merkt sich je Seite, was zuletzt an den KI-Index uebertragen wurde.
 *
 * Contao ruft den indexPage-Hook bei jedem indexierten Seitenaufruf auf - vor der eigenen
 * Pruefung, ob sich der Inhalt geaendert hat. Ohne diesen Merker schickte jeder Besuch den
 * kompletten Seiteninhalt erneut zum Training, und ausgeschlossene Seiten loesten bei jedem
 * Besuch eine Loeschung aus.
 *
 * Der Stand steht in `tl_page.chatbot_checksum`:
 *
 * | Wert | Bedeutung |
 * |---|---|
 * | NULL | unbekannt - etwa vor dem Update trainiert oder nach „Alles löschen“ im Backend |
 * | '' | liegt nicht im Index |
 * | Pruefsumme | dieser Inhalt liegt im Index |
 */
class TrainingState
{
    /**
     * Nach dieser Zeit wird auch unveraenderter Inhalt erneut uebertragen. Faellt der Index
     * auf der API-Seite einmal aus, heilt er sich so von selbst, ohne dass jemand die Seiten
     * anfassen muss.
     */
    public const REFRESH_AFTER = 7 * 86400;

    /**
     * Pruefsumme des Trainings-Payloads. Der Zeitstempel bleibt aussen vor - er aendert sich
     * bei jedem Aufruf, der Inhalt nicht.
     */
    public function checksum(array $payload): string
    {
        unset($payload['tstamp']);

        return md5(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));
    }

    /** Ob dieser Inhalt uebertragen werden muss: geaendert, unbekannt oder zu alt. */
    public function needsTraining(int $pageId, string $checksum): bool
    {
        $row = $this->load($pageId);

        if ($row === null) {
            return true;
        }

        return $row['checksum'] !== $checksum
            || $row['trainedAt'] < time() - self::REFRESH_AFTER;
    }

    /** Ob die Seite sicher nicht im Index liegt - dann ist keine Loeschung noetig. */
    public function isKnownAbsent(int $pageId): bool
    {
        $row = $this->load($pageId);

        return $row !== null && $row['checksum'] === '';
    }

    public function markTrained(int $pageId, string $checksum): void
    {
        Database::getInstance()
            ->prepare('UPDATE tl_page SET chatbot_checksum=?, chatbot_trained_at=? WHERE id=?')
            ->execute($checksum, time(), $pageId);
    }

    /** Die Seite(n) liegen nicht (mehr) im Index. */
    public function markRemoved(int ...$pageIds): void
    {
        $this->update($pageIds, "chatbot_checksum='', chatbot_trained_at=0");
    }

    /**
     * Der Stand ist nicht mehr verlaesslich - beim naechsten Aufruf wird neu uebertragen.
     * Ohne IDs gilt das fuer alle Seiten.
     */
    public function markUnknown(int ...$pageIds): void
    {
        if ($pageIds === []) {
            Database::getInstance()->query('UPDATE tl_page SET chatbot_checksum=NULL, chatbot_trained_at=0');
            $this->clearVariants();

            return;
        }

        $this->update($pageIds, 'chatbot_checksum=NULL, chatbot_trained_at=0');
        $this->clearVariants(...$pageIds);
    }

    /**
     * Ob eine Loeschung durch ist. 404 zaehlt dazu: Der Inhalt ist dann ebenfalls nicht im
     * Index, und ohne diese Ausnahme liefe die Loeschung bei jedem Aufruf erneut.
     */
    public static function deleteSucceeded(array $response): bool
    {
        return !empty($response['success']) || 404 === (int) ($response['statusCode'] ?? 0);
    }

    /**
     * Wandelt eine externe ID der API (`page_<id>`) in die Seiten-ID; andere Inhalte haben
     * keinen Stand in tl_page.
     */
    public static function pageIdFromExternalId(string $externalId): ?int
    {
        return preg_match('/^page_(\d+)$/', $externalId, $treffer) ? (int) $treffer[1] : null;
    }

    /** Laenge des Hash-Anhangs einer Einzel-URL (`page_<id>_<hash>`). */
    private const VARIANT_HASH_LENGTH = 12;

    /**
     * Externe ID fuer die gerade indexierte URL.
     *
     * Eine Contao-Seite kann viele Adressen bedienen: Eine Reader-Seite zeigt jedes News-Archiv-
     * oder Event-Detail unter einer eigenen URL, eine Liste jede Pagination-Stufe. `page_<id>`
     * ist je Seite eindeutig, nicht je URL - jede Detailseite ueberschriebe die vorige, und nur
     * die zuletzt gecrawlte bliebe im Index.
     *
     * Die Grundadresse der Seite behaelt deshalb `page_<id>` (bestehende Eintraege bleiben
     * gueltig, es muss nichts neu trainiert werden); jede andere URL bekommt einen Hash
     * angehaengt.
     */
    public static function externalIdFor(int $pageId, string $url, ?string $pageUrl): string
    {
        $base = 'page_' . $pageId;

        if (null === $pageUrl || '' === $pageUrl || self::normalizeUrl($url) === self::normalizeUrl($pageUrl)) {
            return $base;
        }

        return $base . '_' . substr(md5(self::normalizeUrl($url)), 0, self::VARIANT_HASH_LENGTH);
    }

    /** Ob die externe ID eine Einzel-URL einer Seite ist (`page_<id>_<hash>`). */
    public static function isVariantId(string $externalId): bool
    {
        return 1 === preg_match('/^page_\d+_[0-9a-f]{' . self::VARIANT_HASH_LENGTH . '}$/', $externalId);
    }

    /** Seiten-ID zu einer Basis- oder Einzel-URL-ID; sonst null. */
    public static function ownerPageId(string $externalId): ?int
    {
        return 1 === preg_match('/^page_(\d+)(?:_[0-9a-f]{' . self::VARIANT_HASH_LENGTH . '})?$/', $externalId, $m) ? (int) $m[1] : null;
    }

    /** Ob diese Einzel-URL uebertragen werden muss: geaendert, unbekannt oder zu alt. */
    public function variantNeedsTraining(string $externalId, string $checksum): bool
    {
        $entry = $this->cacheItem($externalId);

        if (!$entry->isHit()) {
            return true;
        }

        $value = (array) $entry->get();

        return ($value['checksum'] ?? '') !== $checksum || (int) ($value['trainedAt'] ?? 0) < time() - self::REFRESH_AFTER;
    }

    public function markVariantTrained(string $externalId, string $checksum): void
    {
        $entry = $this->cacheItem($externalId);
        $entry->set(['checksum' => $checksum, 'trainedAt' => time()]);
        $entry->expiresAfter(2 * self::REFRESH_AFTER);
        $this->cache()->save($entry);
    }

    public function forgetVariant(string $externalId): void
    {
        $this->cache()->deleteItem($this->cacheKey($externalId));
    }

    /**
     * Externe IDs der Einzel-URLs dieser Seite.
     *
     * Sie werden aus `tl_search` abgeleitet statt gespeichert: Dort steht jede indexierte URL der
     * Seite, und die ID folgt eindeutig aus der URL. Eine eigene Tabelle (und damit eine
     * Datenbank-Migration) ist nicht noetig.
     *
     * @return string[]
     */
    public function variantIds(int $pageId, ?string $pageUrl): array
    {
        $ids = [];

        foreach ($this->searchUrls($pageId) as $url) {
            $id = self::externalIdFor($pageId, $url, $pageUrl);

            if (self::isVariantId($id)) {
                $ids[$id] = true;
            }
        }

        return array_keys($ids);
    }

    /** Ob die Grundadresse der Seite selbst im Suchindex steht (und nicht nur Einzel-URLs). */
    public function baseIndexed(int $pageId, ?string $pageUrl): bool
    {
        foreach ($this->searchUrls($pageId) as $url) {
            if ('page_' . $pageId === self::externalIdFor($pageId, $url, $pageUrl)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Vergisst den Stand der Einzel-URLs, damit sie beim naechsten Aufruf neu uebertragen werden.
     * Ohne IDs gilt das fuer alle: Ein neuer Zaehler macht jeden alten Eintrag unauffindbar.
     */
    public function clearVariants(int ...$pageIds): void
    {
        if ([] === $pageIds) {
            $epoch = $this->cache()->getItem('chatbot_reader_epoch');
            $epoch->set(((int) $epoch->get()) + 1);
            $this->cache()->save($epoch);
            $this->epoch = null;

            return;
        }

        foreach ($pageIds as $pageId) {
            $page = \Contao\PageModel::findById((int) $pageId);

            foreach ($this->variantIds((int) $pageId, self::basePageUrl($page)) as $id) {
                $this->forgetVariant($id);
            }
        }
    }

    /**
     * Die Adresse der Seite selbst, ohne Reader-Parameter (Alias, Seitennummer).
     *
     * @return string|null null, wenn sie sich nicht bestimmen laesst - dann gilt die Seite als
     *                     eine Adresse, wie bisher
     */
    public static function basePageUrl(?\Contao\PageModel $page): ?string
    {
        if (null === $page) {
            return null;
        }

        try {
            return $page->getAbsoluteUrl();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** @return string[] */
    private function searchUrls(int $pageId): array
    {
        $urls = [];
        $result = Database::getInstance()->prepare('SELECT url FROM tl_search WHERE pid=?')->execute($pageId);

        while ($result->next()) {
            $urls[] = (string) $result->url;
        }

        return $urls;
    }

    private ?int $epoch = null;

    private function cache(): \Psr\Cache\CacheItemPoolInterface
    {
        return \Contao\System::getContainer()->get('cache.app');
    }

    private function cacheKey(string $externalId): string
    {
        $this->epoch ??= (int) $this->cache()->getItem('chatbot_reader_epoch')->get();

        return 'chatbot_reader_' . md5($this->epoch . '|' . $externalId);
    }

    private function cacheItem(string $externalId): \Psr\Cache\CacheItemInterface
    {
        return $this->cache()->getItem($this->cacheKey($externalId));
    }

    /**
     * Nur Pfad und Query: ohne Host und Schema, denn der Crawler ruft die Seite womoeglich ueber
     * eine andere Adresse ab (www oder nicht, http oder https) als Contao sie ausgibt - sonst
     * gaelte jede Seite als Einzel-URL. Ohne Fragment und ohne abschliessenden Schraegstrich, damit
     * `/a` und `/a/` nicht auseinanderfallen.
     */
    private static function normalizeUrl(string $url): string
    {
        $parts = parse_url($url);

        if (false === $parts) {
            return $url;
        }

        return rtrim($parts['path'] ?? '', '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    private function update(array $pageIds, string $set): void
    {
        $pageIds = array_values(array_filter(array_map('intval', $pageIds), static fn (int $id): bool => $id > 0));

        if ($pageIds === []) {
            return;
        }

        Database::getInstance()
            ->prepare('UPDATE tl_page SET ' . $set . ' WHERE id IN (' . implode(',', array_fill(0, \count($pageIds), '?')) . ')')
            ->execute(...$pageIds);
    }

    /** @return array{checksum: ?string, trainedAt: int}|null */
    private function load(int $pageId): ?array
    {
        $result = Database::getInstance()
            ->prepare('SELECT chatbot_checksum, chatbot_trained_at FROM tl_page WHERE id=?')
            ->limit(1)
            ->execute($pageId);

        if ($result->numRows < 1 || $result->chatbot_checksum === null) {
            return null;
        }

        return ['checksum' => (string) $result->chatbot_checksum, 'trainedAt' => (int) $result->chatbot_trained_at];
    }
}
