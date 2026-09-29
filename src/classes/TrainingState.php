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

            return;
        }

        $this->update($pageIds, 'chatbot_checksum=NULL, chatbot_trained_at=0');
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
