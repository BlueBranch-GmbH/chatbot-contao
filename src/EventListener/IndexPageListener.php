<?php

namespace Bluebranch\Chatbot\EventListener;

use Bluebranch\Chatbot\classes\ChatbotAPI;
use Bluebranch\Chatbot\classes\SearchUtil;
use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\CoreBundle\DependencyInjection\Attribute\AsHook;
use Contao\Database;
use Contao\DataContainer;
use Contao\PageModel;
use Psr\Log\LoggerInterface;

#[AsHook('indexPage')]
class IndexPageListener
{
    private ChatbotAPI $chatbotApi;
    private SearchUtil $searchUtil;
    private LoggerInterface $logger;

    public function __construct(ChatbotAPI $chatbotApi, SearchUtil $searchUtil, LoggerInterface $logger)
    {
        $this->chatbotApi = $chatbotApi;
        $this->searchUtil = $searchUtil;
        $this->logger = $logger;
    }

    /**
     * @param string $content The page content
     * @param array $pageData The page data (tl_page)
     * @param array $indexData The index data (to be stored in tl_search)
     */
    public function __invoke(string $content, array $pageData, array &$indexData): void
    {
        try {
            // Für die Vektor-Datenbank ignorieren wir die indexer::stop Markierungen,
            // damit auch Newslisten etc. erfasst werden.
            $ignoreIndexerMarkers = true;

            $preparedText = $this->searchUtil->prepareIndexText($content, $pageData, $ignoreIndexerMarkers);

            $markdownContent = $this->searchUtil->convertToMarkdown($content, $ignoreIndexerMarkers);

            // Extrahiere Keywords mit Relevanz (Häufigkeit)
            $keywordsWithRelevance = $this->searchUtil->getKeywordsWithRelevance(
                $preparedText,
                $pageData['language'] ?? 'de',
                15
            );

            $pageId = $pageData['id'] ?? $pageData['pid'] ?? '';

            $payload = [
                'externalId' => 'page_' . $pageId,
                'title' => ($pageData['pageTitle'] ?? '') ?: (($pageData['title'] ?? '') ?: ($indexData['title'] ?? '')),
                'url' => $indexData['url'],
                'content' => trim($markdownContent ?: ''),
                'keywords' => implode(', ', array_keys($keywordsWithRelevance)),
                'language' => $pageData['language'] ?? 'de',
                'meta_description' => $pageData['description'] ?? '',
                'tstamp' => time(),
                'type' => 'page'
            ];

            $this->writeDebugFile('train_' . $payload['externalId'] . '.json', $payload);
            $pageModel = PageModel::findById($pageId);

            $this->chatbotApi->trainContent($payload, $pageModel);

        } catch (\Exception $e) {
            $this->logger->error('Fehler bei der Vorbereitung für die Vektor-Datenbank: ' . $e->getMessage(), [
                'pageId' => $pageData['id'] ?? $pageData['pid'] ?? 'unknown',
                'exception' => $e
            ]);
        }
    }

    /**
     * Callback, wenn ein Eintrag aus tl_search gelöscht wird.
     */
    #[AsCallback(table: 'tl_search', target: 'config.ondelete')]
    public function onDeleteSearchEntry(DataContainer $dc): void
    {
        $this->writeDebugFile('delete_search_' . $dc->activeRecord->id . '.json', $dc->activeRecord->row());

        if (!$dc->activeRecord) {
            return;
        }

        try {
            $pageId = $dc->activeRecord->pid;

            if ($pageId) {
                $vectorId = 'page_' . $pageId;

                $this->writeDebugFile('delete_search_vector_' . $vectorId . '.json', ['id' => $vectorId, 'action' => 'delete', 'tstamp' => time()]);

                $pageModel = PageModel::findById($pageId);
                $this->chatbotApi->deleteContent($vectorId, $pageModel);
            }
        } catch (\Exception $e) {
            $this->logger->error('Fehler beim Vormerken zum Löschen aus der Vektor-Datenbank (tl_search): ' . $e->getMessage());
        }
    }

    /**
     * Callback, wenn eine Seite (tl_page) gelöscht wird.
     */
    #[AsCallback(table: 'tl_page', target: 'config.ondelete')]
    public function onDeletePage(DataContainer $dc): void
    {
        if (!$dc->activeRecord) {
            return;
        }

        $this->writeDebugFile('delete_page_' . $dc->activeRecord->id . '.json', $dc->activeRecord->row());

        try {
            $pageId = $dc->activeRecord->id;

            if ($pageId) {
                $vectorId = 'page_' . $pageId;

                $this->writeDebugFile('delete_page_vector_' . $vectorId . '.json', ['id' => $vectorId, 'action' => 'delete', 'tstamp' => time()]);

                $pageModel = PageModel::findById($pageId);
                $this->chatbotApi->deleteContent($vectorId, $pageModel);
            }
        } catch (\Exception $e) {
            $this->logger->error('Fehler beim Löschen aus der Vektor-Datenbank (tl_page): ' . $e->getMessage());
        }
    }

    /**
     * Callback, wenn eine Seite (tl_page) gespeichert wird - sowohl beim regulären
     * Speichern im Editier-Formular als auch beim schnellen Veröffentlichen/
     * Verstecken über das Sichtbarkeits-Icon in der Seitenübersicht (beides läuft
     * über DC_Table::save() bzw. DC_Table::toggle(), die beide den
     * onsubmit_callback auslösen).
     *
     * Wird eine Seite unveröffentlicht oder von der Suche ausgeschlossen, muss sie
     * aus dem KI-Index entfernt werden, da sie sonst dort als Content-Quelle
     * bestehen bleibt, obwohl sie auf der Website nicht mehr sichtbar ist.
     *
     * Das erneute Aufnehmen einer wieder veröffentlichten Seite passiert bewusst
     * nicht hier (dafür fehlt der gerenderte Seiteninhalt), sondern weiterhin über
     * den nächsten Suchindex-Lauf (Crawler).
     */
    #[AsCallback(table: 'tl_page', target: 'config.onsubmit')]
    public function onSubmitPage(DataContainer $dc): void
    {
        if (!$dc->id) {
            return;
        }

        try {
            // Bewusst eine frische Datenbankabfrage statt $dc->activeRecord oder
            // PageModel::findById(): activeRecord kann beim Toggle noch den Stand
            // vor dem Speichern enthalten und der Model-Registry-Cache kann
            // veraltete Werte liefern.
            $row = Database::getInstance()
                ->prepare("SELECT published, noSearch FROM tl_page WHERE id=?")
                ->limit(1)
                ->execute($dc->id);

            if ($row->numRows < 1) {
                return;
            }

            $isEligibleForIndex = (bool) $row->published && !$row->noSearch;

            if ($isEligibleForIndex) {
                return;
            }

            $vectorId = 'page_' . $dc->id;
            $pageModel = PageModel::findById($dc->id);

            $this->writeDebugFile('unpublish_page_' . $vectorId . '.json', ['id' => $vectorId, 'action' => 'delete', 'reason' => 'unpublished_or_nosearch', 'tstamp' => time()]);

            $this->chatbotApi->deleteContent($vectorId, $pageModel);
        } catch (\Exception $e) {
            $this->logger->error('Fehler beim Entfernen aus der Vektor-Datenbank nach dem Speichern (tl_page): ' . $e->getMessage());
        }
    }

    /**
     * Schreibt eine Debug-Datei in das Verzeichnis var/chatbot/.
     */
    private function writeDebugFile(string $filename, array $data): void
    {
        try {
            $rootDir = dirname(__DIR__, 5); // Geht von extensions/bluebranch/chatbot/src/EventListener/IndexPageListener.php zum Projekt-Root
            $debugDir = $rootDir . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'chatbot';

            if (!is_dir($debugDir)) {
                mkdir($debugDir, 0777, true);
            }

            file_put_contents($debugDir . DIRECTORY_SEPARATOR . $filename, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } catch (\Exception $e) {
            $this->logger->warning('Konnte Chatbot-Debug-Datei nicht schreiben: ' . $e->getMessage());
        }
    }
}
