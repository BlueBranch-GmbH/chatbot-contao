<?php

namespace Bluebranch\Chatbot\classes;

use Contao\Config;
use Doctrine\DBAL\Connection;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Fragen, Antworten und Feedback der Besucher in `tl_chatbot_log`.
 *
 * Zwei Schalter wirken zusammen:
 *
 * | Speichern (global) | Feedback (Modul) | Ergebnis |
 * |---|---|---|
 * | an | egal | Jede Antwort wird beim Ende des Streams eine Zeile; Feedback ergaenzt sie. |
 * | aus | an | Frage und Antwort liegen zwei Stunden im Cache. Erst Feedback macht eine Zeile daraus. |
 * | aus | aus | Nichts wird gespeichert. |
 *
 * Der Zwischenspeicher ist der Cache und nicht die Session: Waehrend der Antwort laeuft, ist die
 * Session laengst geschrieben und geschlossen - eine StreamedResponse schickt ihren Rumpf erst
 * nach `kernel.response`, und ein erneutes Oeffnen scheitert an den bereits gesendeten Headern.
 */
class ChatLog
{
    public const SOURCES = ['widget', 'ask', 'search'];
    public const COMMENT_MAX = 1000;
    public const SUMMARIZE_PLACEHOLDER = '[Seite zusammenfassen]';

    /** So lange kann zu einer nicht gespeicherten Antwort noch Feedback kommen. */
    private const PENDING_TTL = 7200;
    private const CACHE_PREFIX = 'bluebranch_chatbot_answer_';

    private Connection $connection;
    private CacheItemPoolInterface $cache;

    public function __construct(Connection $connection, CacheItemPoolInterface $cache)
    {
        $this->connection = $connection;
        $this->cache = $cache;
    }

    public static function isEnabled(): bool
    {
        return (bool) Config::get('chatbot_log_enabled');
    }

    /**
     * Ob ein Modul nach Feedback fragt. Im Modul steht '1' (an), '0' (aus) oder '' - dann gilt
     * die Vorgabe aus den Einstellungen.
     *
     * @param object $module ModuleModel oder Datensatz mit `chatbot_feedback`
     */
    public static function feedbackEnabled($module): bool
    {
        $value = (string) ($module->chatbot_feedback ?? '');

        if ('1' === $value || '0' === $value) {
            return '1' === $value;
        }

        return (bool) Config::get('chatbot_feedback_default');
    }

    public static function newRef(): string
    {
        return bin2hex(random_bytes(16));
    }

    public static function isValidRef($ref): bool
    {
        return \is_string($ref) && 1 === preg_match('/^[a-f0-9]{32}$/', $ref);
    }

    /**
     * Haelt eine fertige Antwort fest - als Zeile oder, ohne Speichern, voruebergehend im Cache.
     *
     * @param array{rootId?: int, pageId?: int, source?: string, language?: string, question: string, answer: string, sources?: array} $entry
     *
     * @return string|null Die Kennung fuer Feedback; null, wenn nichts festgehalten wurde
     */
    public function record(array $entry, bool $feedbackEnabled): ?string
    {
        if ('' === trim($entry['answer'] ?? '')) {
            return null;
        }

        $logEnabled = self::isEnabled();

        if (!$logEnabled && !$feedbackEnabled) {
            return null;
        }

        $ref = self::newRef();
        $row = $this->buildRow($ref, $entry);

        if ($logEnabled) {
            $this->connection->insert('tl_chatbot_log', $row);
        } else {
            $item = $this->cache->getItem(self::CACHE_PREFIX . $ref);
            $item->set($row);
            $item->expiresAfter(self::PENDING_TTL);
            $this->cache->save($item);
        }

        return $feedbackEnabled ? $ref : null;
    }

    /**
     * Speichert eine Bewertung. Gibt false zurueck, wenn die Kennung unbekannt (oder abgelaufen) ist.
     */
    public function applyFeedback(string $ref, string $rating, string $comment = ''): bool
    {
        if (!self::isValidRef($ref) || !\in_array($rating, ['up', 'down'], true)) {
            return false;
        }

        // Ein Kommentar gehoert nur zum Daumen nach unten; beim Umentscheiden faellt er weg.
        $comment = 'down' === $rating ? self::cleanComment($comment) : '';

        $feedback = [
            'rating' => $rating,
            'comment' => $comment,
            'feedback_at' => time(),
        ];

        $updated = $this->connection->update('tl_chatbot_log', $feedback, ['ref' => $ref]);

        if ($updated > 0 || $this->existsRef($ref)) {
            return true;
        }

        // Nicht gespeichert, aber bewertet: Jetzt wird aus der Antwort eine Zeile.
        $item = $this->cache->getItem(self::CACHE_PREFIX . $ref);

        if (!$item->isHit() || !\is_array($item->get())) {
            return false;
        }

        $this->connection->insert('tl_chatbot_log', array_merge($item->get(), $feedback));
        $this->cache->deleteItem(self::CACHE_PREFIX . $ref);

        return true;
    }

    /**
     * Loescht Zeilen, die aelter sind als die eingestellte Aufbewahrungsdauer.
     */
    public function purgeExpired(): int
    {
        $days = (int) Config::get('chatbot_log_retention');

        if ($days <= 0) {
            return 0;
        }

        return (int) $this->connection->executeStatement(
            'DELETE FROM tl_chatbot_log WHERE tstamp < ?',
            [time() - $days * 86400]
        );
    }

    /**
     * Ersetzt E-Mail-Adressen und Telefonnummern.
     *
     * Gespeichert wird ohne Nutzerdaten - was Besucher selbst in die Frage tippen, kann aber
     * trotzdem welche enthalten („Ich bin Max, erreichbar unter 0171 …“). Die Antworten bleiben
     * unangetastet: Dort stehen die Kontaktdaten der Website selbst.
     */
    public static function mask(string $text): string
    {
        $text = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[E-Mail]', $text) ?? $text;
        // IBAN vor den Telefonnummern, sonst bliebe das Laenderkennzeichen stehen.
        $text = preg_replace('/\b[A-Z]{2}\d{2}(?:\s?[A-Z0-9]{4}){3,7}(?:\s?[A-Z0-9]{1,4})?\b/', '[IBAN]', $text) ?? $text;
        // Telefonnummern: beginnen mit +, 00 oder 0 und haben mindestens sieben Ziffern. Punkte
        // zaehlen bewusst nicht als Trenner - sonst wuerde jedes Datum („24.12.2026“) maskiert.
        $text = preg_replace('/(?<![\w.])(?:\+\d|0)(?:[ \/\-()]*\d){6,}(?![\w.])/', '[Telefon]', $text) ?? $text;

        return $text;
    }

    /**
     * Bereinigt den Freitext unter einem Daumen nach unten: ohne Tags, ohne Steuerzeichen,
     * hoechstens COMMENT_MAX Zeichen, Kontaktdaten maskiert.
     */
    public static function cleanComment(string $comment): string
    {
        $comment = strip_tags($comment);
        $comment = preg_replace('/[^\P{C}\n]+/u', '', $comment) ?? '';
        $comment = preg_replace("/\n{3,}/", "\n\n", $comment) ?? $comment;
        $comment = trim($comment);

        if (mb_strlen($comment) > self::COMMENT_MAX) {
            $comment = mb_substr($comment, 0, self::COMMENT_MAX);
        }

        return self::mask($comment);
    }

    private function existsRef(string $ref): bool
    {
        return false !== $this->connection->fetchOne('SELECT id FROM tl_chatbot_log WHERE ref=?', [$ref]);
    }

    private function buildRow(string $ref, array $entry): array
    {
        $source = \in_array($entry['source'] ?? '', self::SOURCES, true) ? $entry['source'] : '';

        $sources = [];

        foreach (\array_slice((array) ($entry['sources'] ?? []), 0, 10) as $item) {
            if (!\is_array($item)) {
                continue;
            }

            $sources[] = [
                'title' => mb_substr((string) ($item['title'] ?? ''), 0, 255),
                'url' => mb_substr((string) ($item['url'] ?? ''), 0, 1000),
            ];
        }

        return [
            'tstamp' => time(),
            'ref' => $ref,
            'root_id' => (int) ($entry['rootId'] ?? 0),
            'page_id' => (int) ($entry['pageId'] ?? 0),
            'source' => $source,
            'language' => mb_substr((string) ($entry['language'] ?? ''), 0, 64),
            'question' => mb_substr(self::mask((string) $entry['question']), 0, 10000),
            // Auch maskiert: Modelle wiederholen Kontaktdaten aus der Frage in der Antwort.
            'answer' => self::mask((string) $entry['answer']),
            'sources' => json_encode($sources, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'rating' => '',
            'comment' => '',
            'feedback_at' => 0,
        ];
    }
}
