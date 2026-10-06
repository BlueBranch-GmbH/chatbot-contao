<?php

namespace Bluebranch\Chatbot\classes;

use Contao\FilesModel;
use Contao\PageModel;
use Contao\StringUtil;
use Contao\System;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * Zusatzinhalte (`tl_chatbot_content`): Texte und Dateien ohne eigene Seite.
 *
 * An die API geht nur Text - Dateien werden vorher mit dem TextExtractor umgewandelt - und
 * **keine URL**: Diese Inhalte haben keine Seite, auf die ein Link fuehren koennte. Die externe
 * ID ist `extra_<id>`; sie kollidiert nicht mit den Seiten (`page_<id>`), und der
 * Bereinigungslauf der Seiten laesst sie in Ruhe.
 */
class ExtraContent
{
    public const PREFIX = 'extra_';

    private Connection $connection;
    private ChatbotAPI $chatbotApi;
    private TextExtractor $extractor;
    private LoggerInterface $logger;

    public function __construct(Connection $connection, ChatbotAPI $chatbotApi, TextExtractor $extractor, LoggerInterface $logger)
    {
        $this->connection = $connection;
        $this->chatbotApi = $chatbotApi;
        $this->extractor = $extractor;
        $this->logger = $logger;
    }

    /**
     * Bringt einen Eintrag in den Index - oder heraus, wenn er nicht veroeffentlicht ist.
     *
     * @return string Statuszeile fuer die Rueckmeldung im Backend
     */
    public function sync(int $id, bool $force = false): string
    {
        $row = $this->load($id);

        if (null === $row) {
            return '';
        }

        if (!$row['published']) {
            if (null !== $row['chatbot_checksum'] && '' !== $row['chatbot_checksum']) {
                $this->remove($row);
            }

            return 'nicht veröffentlicht';
        }

        // Pruefsumme vorab: Sie wird auch bei einem Fehler gespeichert. Sonst hielte der Abgleich
        // die Datei bei jedem Lauf fuer geaendert und parste ein kaputtes PDF alle 15 Minuten neu.
        $path = 'file' === $row['type'] ? $this->filePath($row) : null;
        $fileHash = null !== $path ? (string) md5_file($path) : '';

        try {
            $text = $this->textFor($row, $path);
        } catch (\RuntimeException $e) {
            $this->setState($id, ['status' => 'error', 'error' => mb_substr($e->getMessage(), 0, 255), 'file_hash' => $fileHash]);

            return $e->getMessage();
        }

        $truncated = TextExtractor::limit($text);

        if ('' === $text) {
            $this->setState($id, ['status' => 'error', 'error' => 'Kein Text vorhanden.', 'file_hash' => $fileHash]);

            return 'Kein Text vorhanden.';
        }

        $payload = [
            'externalId' => self::PREFIX . $id,
            'title' => StringUtil::decodeEntities((string) $row['title']),
            'content' => $text,
            'language' => (string) ($row['language'] ?: 'de'),
            'type' => 'file' === $row['type'] ? 'document' : 'note',
            'tstamp' => time(),
        ];

        $checksum = md5(json_encode(array_diff_key($payload, ['tstamp' => true]), JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) . '|' . $row['root']);

        if (!$force && $checksum === $row['chatbot_checksum'] && 'trained' === $row['status']) {
            $this->setState($id, ['file_hash' => $fileHash]);

            return 'unverändert';
        }

        // Laengere Dokumente brauchen beim Einbetten mehr als das uebliche Zeitlimit.
        $result = $this->chatbotApi->trainContent($payload, $this->rootPage($row), ['timeout' => 180]);

        if (empty($result['success'])) {
            $message = \is_string($result['message'] ?? null) ? $result['message'] : 'Übertragung fehlgeschlagen.';
            $this->setState($id, ['status' => 'error', 'error' => mb_substr($message, 0, 255), 'file_hash' => $fileHash]);

            return $message;
        }

        $this->setState($id, [
            'chatbot_checksum' => $checksum,
            'trained_at' => time(),
            'chars' => mb_strlen($text),
            'file_hash' => $fileHash,
            'status' => $truncated ? 'truncated' : 'trained',
            'error' => $truncated ? sprintf('Auf %s Zeichen gekürzt.', number_format(TextExtractor::MAX_CHARS, 0, ',', '.')) : '',
        ]);

        return 'übertragen';
    }

    /**
     * Entfernt den Eintrag aus dem KI-Index.
     */
    public function remove(array $row): void
    {
        $result = $this->chatbotApi->deleteContent(self::PREFIX . $row['id'], $this->rootPage($row));

        if (TrainingState::deleteSucceeded($result)) {
            $this->setState((int) $row['id'], ['chatbot_checksum' => '', 'trained_at' => 0, 'status' => 'removed', 'error' => '']);
        } else {
            $this->logger->error(sprintf('Chatbot: Zusatzinhalt %d konnte nicht aus dem KI-Index entfernt werden.', $row['id']));
        }
    }

    public function removeById(int $id): void
    {
        $row = $this->load($id);

        if (null !== $row) {
            $this->remove($row);
        }
    }

    /**
     * Gleicht die Eintraege mit Dateien und Index ab - laeuft im Cronjob alle 15 Minuten.
     *
     * - Datei geaendert → neu uebertragen. Verglichen wird die Pruefsumme der Datei selbst, nicht
     *   `tl_files.hash`: Eine per FTP ersetzte Datei bekaeme dort erst nach der
     *   Dateisynchronisation einen neuen Wert.
     * - Datei geloescht → aus dem Index, Eintrag zeigt den Fehler.
     * - Aktiv, aber nicht im Index (etwa nach „Alle Inhalte löschen“) → wieder uebertragen.
     *
     * Eintraege mit Fehler werden nicht endlos wiederholt; sie kommen wieder an die Reihe, wenn
     * sich die Datei aendert oder der Eintrag gespeichert wird.
     */
    public function syncPending(): int
    {
        if (!$this->tableExists()) {
            return 0;
        }

        $count = 0;

        foreach ($this->connection->fetchAllAssociative("SELECT * FROM tl_chatbot_content WHERE published='1'") as $row) {
            $inIndex = null !== $row['chatbot_checksum'] && '' !== $row['chatbot_checksum'];

            if ('file' === $row['type']) {
                // Entwurf ohne gewaehlte Datei: nichts zu tun, kein Fehler.
                if (empty($row['file']) && !$inIndex) {
                    continue;
                }

                $path = $this->filePath($row);

                if (null === $path) {
                    if ($inIndex) {
                        $this->remove($row);
                    }

                    if ('error' !== $row['status']) {
                        $this->setState((int) $row['id'], ['status' => 'error', 'error' => empty($row['file']) ? 'Keine Datei ausgewählt.' : 'Datei nicht mehr vorhanden.']);
                    }

                    continue;
                }

                if (md5_file($path) !== $row['file_hash']) {
                    $this->sync((int) $row['id']);
                    ++$count;

                    continue;
                }
            }

            if (!$inIndex && 'error' !== $row['status']) {
                $this->sync((int) $row['id']);
                ++$count;
            }
        }

        return $count;
    }

    /**
     * Nach dem Wiederherstellen (Undo) oder dem Zurueckspielen einer Version: Der gespeicherte
     * Trainingsstand stammt aus der Zeit davor und stimmt nicht mehr - neu uebertragen.
     */
    public function resync(int $id): void
    {
        if (!$this->tableExists()) {
            return;
        }

        $this->setState($id, ['chatbot_checksum' => null, 'status' => '', 'error' => '']);
        $this->sync($id, true);
    }

    /**
     * Alle veroeffentlichten Eintraege erneut uebertragen.
     */
    public function syncAll(bool $force = true): array
    {
        $result = ['ok' => 0, 'failed' => 0];

        foreach ($this->connection->fetchFirstColumn('SELECT id FROM tl_chatbot_content') as $id) {
            $status = $this->sync((int) $id, $force);
            $state = $this->load((int) $id);

            if (null !== $state && 'error' === $state['status']) {
                ++$result['failed'];
            } elseif ('nicht veröffentlicht' !== $status) {
                ++$result['ok'];
            }
        }

        return $result;
    }

    /**
     * Nach „Alle Inhalte löschen“ im Backend: Die Eintraege liegen nicht mehr im Index.
     */
    public function markRemovedForRoot(?int $rootId): void
    {
        if (!$this->tableExists()) {
            return;
        }

        if (null === $rootId) {
            $this->connection->executeStatement("UPDATE tl_chatbot_content SET chatbot_checksum='', trained_at=0, status='removed'");

            return;
        }

        $this->connection->executeStatement(
            "UPDATE tl_chatbot_content SET chatbot_checksum='', trained_at=0, status='removed' WHERE root=? OR root=0",
            [$rootId]
        );
    }

    /**
     * Ein Zusatzinhalt wurde in „Trainierte Seiten“ einzeln geloescht. Der Eintrag wird
     * deaktiviert - sonst braechte ihn der naechste Abgleich gleich wieder in den Index.
     */
    public function markRemovedByExternalId(string $externalId): void
    {
        if (preg_match('/^' . self::PREFIX . '(\d+)$/', $externalId, $match) && $this->tableExists()) {
            $this->setState((int) $match[1], ['chatbot_checksum' => '', 'trained_at' => 0, 'status' => 'removed', 'published' => '']);
        }
    }

    private function textFor(array $row, ?string $path): string
    {
        if ('file' !== $row['type']) {
            // Contao speichert Eingaben kodiert; an die API geht der Klartext.
            $text = html_entity_decode(StringUtil::decodeEntities((string) $row['text']), ENT_QUOTES | ENT_HTML5, 'UTF-8');

            return $this->extractor->normalize($text);
        }

        if (null === $path) {
            throw new \RuntimeException('Keine Datei ausgewählt oder Datei gelöscht.');
        }

        return $this->extractor->extract($path);
    }

    private function filePath(array $row): ?string
    {
        $file = $row['file'] ? FilesModel::findByUuid($row['file']) : null;

        if (!$file instanceof FilesModel) {
            return null;
        }

        $path = System::getContainer()->getParameter('kernel.project_dir') . '/' . $file->path;

        return is_file($path) ? $path : null;
    }

    private function rootPage(array $row): ?PageModel
    {
        return (int) $row['root'] > 0 ? PageModel::findById((int) $row['root']) : null;
    }

    private function load(int $id): ?array
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM tl_chatbot_content WHERE id=?', [$id]);

        return false === $row ? null : $row;
    }

    private function setState(int $id, array $values): void
    {
        $this->connection->update('tl_chatbot_content', $values, ['id' => $id]);
    }

    private function tableExists(): bool
    {
        static $exists = null;

        if (null === $exists) {
            $schemaManager = method_exists($this->connection, 'createSchemaManager')
                ? $this->connection->createSchemaManager()
                : $this->connection->getSchemaManager();
            $exists = $schemaManager->tablesExist(['tl_chatbot_content']);
        }

        return $exists;
    }
}
