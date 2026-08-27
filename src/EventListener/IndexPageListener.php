<?php

namespace Bluebranch\Chatbot\EventListener;

use Bluebranch\Chatbot\classes\ChatbotAPI;
use Bluebranch\Chatbot\classes\PageEligibility;
use Bluebranch\Chatbot\classes\SearchUtil;
use Contao\Config;
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
    private PageEligibility $eligibility;
    private LoggerInterface $logger;

    public function __construct(
        ChatbotAPI $chatbotApi,
        SearchUtil $searchUtil,
        PageEligibility $eligibility,
        LoggerInterface $logger
    ) {
        $this->chatbotApi = $chatbotApi;
        $this->searchUtil = $searchUtil;
        $this->eligibility = $eligibility;
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
            $pageId = (int) ($pageData['id'] ?? $pageData['pid'] ?? 0);

            /*
             * Der Ausschluss wird hier geprueft, obwohl Contao den Hook fuer Seiten mit
             * `noSearch` gar nicht erst aufruft: `chatbot_noAnswers` ist von `noSearch`
             * unabhaengig - eine Seite darf in der Volltextsuche stehen und trotzdem aus den
             * KI-Antworten heraus. Ohne diese Pruefung traegt der naechste Crawler-Lauf sie
             * wieder ein, und der Ausschluss haelt genau bis dahin.
             */
            if ($this->eligibility->isExcludedFromAnswers($pageId)) {
                $this->chatbotApi->deleteContent('page_' . $pageId, PageModel::findById($pageId));
                return;
            }

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
        // Erst prüfen, dann zugreifen: activeRecord kann null sein, und die Debug-Zeile
        // hat den Datensatz zuvor bereits dereferenziert.
        if (!$dc->activeRecord) {
            return;
        }

        $this->writeDebugFile('delete_search_' . $dc->activeRecord->id . '.json', $dc->activeRecord->row());

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
            // Der Merker der Vorpruefung stammt aus der Zeit vor dem Speichern.
            $this->eligibility->reset();

            $pageId = (int) $dc->id;

            /*
             * Beim manuellen Ausschluss haengt der ganze Zweig daran: Die Kindseiten stehen zu
             * diesem Zeitpunkt noch im Index und erben die Sperre erst durch diese Pruefung.
             * Der stuendliche Cronjob wuerde sie zwar auch erwischen, aber eine Sperre, die erst
             * in einer Stunde wirkt, ist keine - der Redakteur haelt die Seite fuer erledigt.
             */
            $betroffen = $this->eligibility->isExcludedFromAnswers($pageId)
                ? $this->eligibility->branchIds($pageId)
                : [$pageId];

            $entfernt = 0;

            foreach ($betroffen as $id) {
                if ($this->eligibility->isEligible($id)) {
                    continue;
                }

                $vectorId = 'page_' . $id;

                $this->writeDebugFile(
                    'unpublish_page_' . $vectorId . '.json',
                    ['id' => $vectorId, 'action' => 'delete', 'reason' => 'not_eligible', 'tstamp' => time()]
                );

                $this->chatbotApi->deleteContent($vectorId, PageModel::findById($id));
                ++$entfernt;
            }

            if ($entfernt > 0) {
                $this->logger->info(sprintf(
                    'Chatbot: %d Seite(n) nach dem Speichern von Seite %d aus dem KI-Index entfernt.',
                    $entfernt,
                    $pageId
                ));
            }
        } catch (\Exception $e) {
            $this->logger->error('Fehler beim Entfernen aus der Vektor-Datenbank nach dem Speichern (tl_page): ' . $e->getMessage());
        }
    }

    /**
     * Schreibt eine Debug-Datei in das Verzeichnis var/chatbot/.
     *
     * Nur aktiv, wenn die Einstellung `chatbot_debug` gesetzt ist. Ohne diesen Schalter
     * legte der Indexer bei jedem Crawler-Lauf eine Datei je Seite an — mitsamt dem
     * vollständigen Seiteninhalt und ohne dass irgendetwas sie wieder aufgeräumt hätte.
     */
    private function writeDebugFile(string $filename, array $data): void
    {
        if (!Config::get('chatbot_debug')) {
            return;
        }

        try {
            $rootDir = dirname(__DIR__, 5); // Geht von extensions/bluebranch/chatbot/src/EventListener/IndexPageListener.php zum Projekt-Root
            $debugDir = $rootDir . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'chatbot';

            if (!is_dir($debugDir)) {
                mkdir($debugDir, 0775, true);
            }

            file_put_contents($debugDir . DIRECTORY_SEPARATOR . $filename, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } catch (\Exception $e) {
            $this->logger->warning('Konnte Chatbot-Debug-Datei nicht schreiben: ' . $e->getMessage());
        }
    }
}
