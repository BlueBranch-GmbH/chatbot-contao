<?php

namespace Bluebranch\Chatbot\Cron;

use Bluebranch\Chatbot\classes\ChatbotAPI;
use Bluebranch\Chatbot\classes\ExtraContent;
use Bluebranch\Chatbot\classes\PageEligibility;
use Bluebranch\Chatbot\classes\TrainingState;
use Contao\CoreBundle\DependencyInjection\Attribute\AsCronJob;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\PageModel;
use Doctrine\DBAL\Connection;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Haelt den KI-Index bei zeitgesteuerten Inhalten aktuell („Anzeigen ab/bis“).
 *
 * Laeuft ein Inhaltselement oder Artikel ab, aendert sich die Seite, ohne dass jemand speichert.
 * Contao begrenzt dafuer zwar die Cache-Lebensdauer, neu trainiert wurde bisher aber erst beim
 * naechsten Besuch der Seite - eine wenig besuchte Seite hielt ein abgelaufenes Angebot so
 * beliebig lange in den KI-Antworten. Eine abgelaufene Seite entfernte nur der
 * Bereinigungslauf, im Standard einmal am Tag.
 *
 * Dieser Lauf sucht alle 15 Minuten, was seit dem letzten Lauf seine Start- oder Stoppzeit
 * ueberschritten hat:
 *
 * - Seite nicht mehr berechtigt → sofort aus dem Index.
 * - Sonst → die Seite einmal abrufen. Contaos Suchindexer rendert sie, der indexPage-Hook
 *   erkennt die geaenderte Pruefsumme und trainiert neu.
 *
 * Nebenbei gleicht er die Zusatzinhalte mit ihren Dateien ab (geaenderte Datei → neu uebertragen).
 */
#[AsCronJob('*/15 * * * *')]
class ScheduledContentCron
{
    private const CACHE_KEY = 'bluebranch_chatbot_schedule_last_run';

    /** Hoechstzahl an Seitenabrufen je Lauf; der Rest folgt beim naechsten. */
    private const MAX_FETCHES = 20;

    private ContaoFramework $framework;
    private Connection $connection;
    private CacheItemPoolInterface $cache;
    private HttpClientInterface $httpClient;
    private ChatbotAPI $chatbotApi;
    private PageEligibility $eligibility;
    private ExtraContent $extraContent;
    private LoggerInterface $logger;

    public function __construct(
        ContaoFramework $framework,
        Connection $connection,
        CacheItemPoolInterface $cache,
        HttpClientInterface $httpClient,
        ChatbotAPI $chatbotApi,
        PageEligibility $eligibility,
        ExtraContent $extraContent,
        LoggerInterface $logger
    ) {
        $this->framework = $framework;
        $this->connection = $connection;
        $this->cache = $cache;
        $this->httpClient = $httpClient;
        $this->chatbotApi = $chatbotApi;
        $this->eligibility = $eligibility;
        $this->extraContent = $extraContent;
        $this->logger = $logger;
    }

    public function __invoke(): void
    {
        $this->framework->initialize();

        $now = time();
        $item = $this->cache->getItem(self::CACHE_KEY);
        // Ohne gemerkten Lauf (erster Lauf, geleerter Cache) eine Stunde zurueckschauen.
        $since = $item->isHit() ? (int) $item->get() : $now - 3600;

        try {
            $this->processSchedule($since, $now);
            $this->extraContent->syncPending();

            // Erst nach Erfolg weiterruecken - sonst ginge ein Fenster verloren.
            $item->set($now);
            $this->cache->save($item);
        } catch (\Throwable $e) {
            $this->logger->error('Chatbot: Abgleich zeitgesteuerter Inhalte fehlgeschlagen: ' . $e->getMessage());
        }
    }

    /**
     * @return array{removed: int[], refreshed: int[]}
     */
    public function processSchedule(int $since, int $now): array
    {
        $pageIds = $this->changedPageIds($since, $now);
        $state = new TrainingState();
        $removed = [];
        $refreshed = [];

        foreach ($pageIds as $pageId) {
            if (!$this->eligibility->isEligible($pageId)) {
                if (!$state->isKnownAbsent($pageId)) {
                    $result = $this->chatbotApi->deleteContent('page_' . $pageId, PageModel::findById($pageId));

                    if (TrainingState::deleteSucceeded($result)) {
                        $state->markRemoved($pageId);
                        $removed[] = $pageId;
                    }
                }

                continue;
            }

            if (\count($refreshed) >= self::MAX_FETCHES) {
                $this->logger->info('Chatbot: Mehr zeitgesteuerte Seiten als Abrufe je Lauf - der Rest wird beim nächsten Seitenaufruf trainiert.');
                break;
            }

            if ($this->fetch($pageId)) {
                $refreshed[] = $pageId;
            }
        }

        if ($removed || $refreshed) {
            $this->logger->info(sprintf(
                'Chatbot: Zeitgesteuerte Inhalte - %d Seite(n) aus dem KI-Index entfernt, %d neu abgerufen.',
                \count($removed),
                \count($refreshed)
            ));
        }

        return ['removed' => $removed, 'refreshed' => $refreshed];
    }

    /**
     * Seiten, bei denen selbst, in einem Artikel oder in einem Inhaltselement seit `$since`
     * eine Start- oder Stoppzeit erreicht wurde.
     *
     * `start` und `stop` sind in Contao Zeichenketten ('' = nicht gesetzt). Der Vergleich mit
     * Zahlen wandelt '' in 0, und 0 liegt nie im Fenster.
     *
     * @return int[]
     */
    private function changedPageIds(int $since, int $now): array
    {
        $window = '((start != \'\' AND start > :since AND start <= :now) OR (stop != \'\' AND stop > :since AND stop <= :now))';
        $params = ['since' => $since, 'now' => $now];

        $ids = $this->connection->fetchFirstColumn('SELECT id FROM tl_page WHERE ' . $window, $params);

        $ids = array_merge($ids, $this->connection->fetchFirstColumn(
            'SELECT pid FROM tl_article WHERE ' . $window,
            $params
        ));

        $ids = array_merge($ids, $this->connection->fetchFirstColumn(
            'SELECT a.pid FROM tl_content c INNER JOIN tl_article a ON a.id=c.pid'
            . " WHERE (c.ptable='tl_article' OR c.ptable='')"
            . ' AND ((c.start != \'\' AND c.start > :since AND c.start <= :now) OR (c.stop != \'\' AND c.stop > :since AND c.stop <= :now))',
            $params
        ));

        return array_values(array_unique(array_filter(array_map('intval', $ids))));
    }

    /**
     * Ruft die Seite ab, damit Contao sie rendert und indexiert. Fehler nur ins Log: Der
     * naechste echte Besuch heilt es ohnehin.
     */
    private function fetch(int $pageId): bool
    {
        $page = PageModel::findById($pageId);

        if (!$page instanceof PageModel || !\in_array($page->type, ['regular', 'forward'], true)) {
            return false;
        }

        // Nur Seiten mit fest eingetragener Domain: Ohne sie nimmt Contao den Host der laufenden
        // Anfrage - im Web-Cron der Host-Header eines beliebigen Besuchers. Der Abruf liesse sich
        // so auf interne Adressen lenken. Ohne Domain trainiert eben der naechste Besuch.
        $page->loadDetails();

        if ('' === (string) $page->domain) {
            return false;
        }

        try {
            $url = $page->getAbsoluteUrl();
            $response = $this->httpClient->request('GET', $url, [
                'timeout' => 15,
                'max_redirects' => 0,
                'headers' => ['User-Agent' => 'BlueBranch-Chatbot-Refresh/1.0'],
            ]);

            $status = $response->getStatusCode();

            // Bis zum Ende lesen: Bricht die Verbindung vorher ab, kann der Server das Rendern
            // abbrechen, bevor der Suchindexer gelaufen ist.
            $response->getContent(false);

            if ($status >= 400) {
                $this->logger->warning(sprintf('Chatbot: Abruf von %s für den KI-Index ergab HTTP %d.', $url, $status));

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            $this->logger->warning(sprintf('Chatbot: Seite %d konnte für den KI-Index nicht abgerufen werden: %s', $pageId, $e->getMessage()));

            return false;
        }
    }
}
