<?php

namespace Bluebranch\Chatbot\Cron;

use Bluebranch\Chatbot\classes\ChatbotAPI;
use Contao\Config;
use Contao\CoreBundle\DependencyInjection\Attribute\AsCronJob;
use Contao\Database;
use Contao\PageModel;
use Psr\Log\LoggerInterface;

/**
 * Reines Trainieren/Aktualisieren übernimmt bereits Contaos eigener
 * SearchIndexListener bei jedem echten Seitenaufruf (kernel.terminate ->
 * contao.search.indexer -> indexPage-Hook), dafür braucht es keinen eigenen
 * Cronjob.
 *
 * Was dabei NICHT abgedeckt ist: Seiten, die über das start-/stop-Feld
 * zeitgesteuert veröffentlicht/unveröffentlicht werden. Dieser Übergang
 * passiert ohne jedes Speichern des Datensatzes, daher greift auch der
 * onSubmitPage-Callback in IndexPageListener nicht. Dieser Cronjob durchsucht
 * daher regelmäßig tl_page nach aktuell nicht (mehr) berechtigten Seiten
 * (unveröffentlicht, von der Suche ausgeschlossen, oder zeitlich abgelaufen)
 * und entfernt sie aus dem KI-Index.
 */
#[AsCronJob('hourly')]
class PurgeIneligiblePagesCron
{
    private ChatbotAPI $chatbotApi;
    private LoggerInterface $logger;

    public function __construct(ChatbotAPI $chatbotApi, LoggerInterface $logger)
    {
        $this->chatbotApi = $chatbotApi;
        $this->logger = $logger;
    }

    public function __invoke(): void
    {
        if (!Config::get('chatbot_purge_enabled')) {
            return;
        }

        $intervalMinutes = (int) Config::get('chatbot_purge_interval');

        if ($intervalMinutes <= 0) {
            $intervalMinutes = 1440; // Fallback: täglich
        }

        $lastRun = (int) Config::get('chatbot_purge_last_run');

        if ($lastRun && (time() - $lastRun) < ($intervalMinutes * 60)) {
            return;
        }

        Config::persist('chatbot_purge_last_run', time());

        try {
            $now = time();

            $result = Database::getInstance()->execute(
                "SELECT id FROM tl_page WHERE published != '1' OR noSearch = '1'"
                . " OR (start != '' AND start > $now)"
                . " OR (stop != '' AND stop != '0' AND stop < $now)"
            );

            $count = 0;

            while ($result->next()) {
                $pageModel = PageModel::findById($result->id);
                $this->chatbotApi->deleteContent('page_' . $result->id, $pageModel);
                $count++;
            }

            $this->logger->info(sprintf('Chatbot: Bereinigungslauf abgeschlossen, %d Seite(n) aus dem KI-Index entfernt/geprüft.', $count));
        } catch (\Throwable $e) {
            $this->logger->error('Chatbot: Bereinigungslauf für den KI-Index fehlgeschlagen: ' . $e->getMessage());
        }
    }
}
