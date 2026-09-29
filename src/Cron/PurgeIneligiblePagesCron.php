<?php

namespace Bluebranch\Chatbot\Cron;

use Bluebranch\Chatbot\classes\ChatbotAPI;
use Bluebranch\Chatbot\classes\PageEligibility;
use Bluebranch\Chatbot\classes\TrainingState;
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
 * (unveröffentlicht, von der Suche oder den KI-Antworten ausgeschlossen, oder
 * zeitlich abgelaufen) und entfernt sie aus dem KI-Index. Beim manuellen
 * Ausschluss gehören die Unterseiten dazu - die Vererbung hängt am Seitenbaum
 * und lässt sich nicht als Spaltenbedingung formulieren.
 */
#[AsCronJob('hourly')]
class PurgeIneligiblePagesCron
{
    private ChatbotAPI $chatbotApi;
    private PageEligibility $eligibility;
    private LoggerInterface $logger;
    private TrainingState $trainingState;

    public function __construct(ChatbotAPI $chatbotApi, PageEligibility $eligibility, LoggerInterface $logger, ?TrainingState $trainingState = null)
    {
        $this->chatbotApi = $chatbotApi;
        $this->eligibility = $eligibility;
        $this->logger = $logger;
        $this->trainingState = $trainingState ?? new TrainingState();
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
                "SELECT id FROM tl_page WHERE published != '1' OR " . PageEligibility::excludedFromSearchSql()
                . " OR (start != '' AND start > $now)"
                . " OR (stop != '' AND stop != '0' AND stop < $now)"
            );

            $ids = [];

            while ($result->next()) {
                $ids[(int) $result->id] = true;
            }

            /*
             * Manuell ausgeschlossene Seiten samt ihrer Unterseiten.
             *
             * Die Vererbung laesst sich in der Abfrage oben nicht ausdruecken - sie haengt am
             * Seitenbaum, nicht an einer Spalte. Ohne diesen Schritt bliebe ein Zweig im Index,
             * dessen Ueberseite laengst abgehakt ist.
             */
            $markiert = Database::getInstance()->execute("SELECT id FROM tl_page WHERE chatbot_noAnswers='1'");

            while ($markiert->next()) {
                foreach ($this->eligibility->branchIds((int) $markiert->id) as $id) {
                    $ids[$id] = true;
                }
            }

            $count = 0;

            foreach (array_keys($ids) as $id) {
                // Was nachweislich nicht im Index liegt, muss nicht geloescht werden.
                if ($this->trainingState->isKnownAbsent($id)) {
                    continue;
                }

                $pageModel = PageModel::findById($id);
                $result = $this->chatbotApi->deleteContent('page_' . $id, $pageModel);

                // Festhalten, damit die Seite nach einer erneuten Veroeffentlichung wieder
                // trainiert wird - ihr Inhalt hat sich ja womoeglich nicht geaendert.
                if (TrainingState::deleteSucceeded($result)) {
                    $this->trainingState->markRemoved($id);
                }

                $count++;
            }

            $this->logger->info(sprintf('Chatbot: Bereinigungslauf abgeschlossen, %d Seite(n) aus dem KI-Index entfernt/geprüft.', $count));
        } catch (\Throwable $e) {
            $this->logger->error('Chatbot: Bereinigungslauf für den KI-Index fehlgeschlagen: ' . $e->getMessage());
        }
    }
}
