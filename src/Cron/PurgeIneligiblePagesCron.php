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
            // Ebenso geschuetzte Seiten: Der Schutz gilt auch fuer alle Unterseiten.
            $markiert = Database::getInstance()->execute("SELECT id FROM tl_page WHERE chatbot_noAnswers='1' OR protected='1'");

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
                $result = $this->chatbotApi->deletePageContent($id, $pageModel);

                // Festhalten, damit die Seite nach einer erneuten Veroeffentlichung wieder
                // trainiert wird - ihr Inhalt hat sich ja womoeglich nicht geaendert.
                if (TrainingState::deleteSucceeded($result)) {
                    $this->trainingState->markRemoved($id);
                }

                $count++;
            }

            $this->logger->info(sprintf('Chatbot: Bereinigungslauf abgeschlossen, %d Seite(n) aus dem KI-Index entfernt/geprüft.', $count));

            $orphans = $this->purgeOrphans();

            if ($orphans > 0) {
                $this->logger->info(sprintf('Chatbot: %d verwaiste(r) Eintrag/Einträge aus dem KI-Index entfernt.', $orphans));
            }
        } catch (\Throwable $e) {
            $this->logger->error('Chatbot: Bereinigungslauf für den KI-Index fehlgeschlagen: ' . $e->getMessage());
        }
    }
    /** Obergrenze je Lauf: Ein Fehler in der Erlaubnisliste soll nicht den ganzen Index leeren. */
    private const MAX_ORPHANS_PER_RUN = 200;

    /** Eintraege, die juenger sind, bleiben unberuehrt: `tl_search` wird erst nach dem Training geschrieben. */
    private const ORPHAN_GRACE = 7200;

    /**
     * Entfernt Eintraege, die in der API liegen, aber zu keiner Seite dieser Installation mehr gehoeren.
     *
     * Die Schritte oben loeschen gezielt, ausgehend von `tl_page`. Was dort nicht mehr vorkommt -
     * eine geloeschte Seite, ein Event, dessen Suchindex-Eintrag Contao still entfernt hat -, kennen
     * sie nicht. Hier wird deshalb die Liste der API durchgegangen und gegen die Datenbank gehalten.
     *
     * Vorsicht ist Absicht, denn ein Fehler hier loescht Inhalte:
     *  - nur `page_<id>` und `page_<id>_<hash>`; Zusatzinhalte und Unbekanntes bleiben,
     *  - nur Eintraege, deren URL zu einer Domain dieser Installation gehoert (teilen sich zwei
     *    Installationen einen Key, bleiben die Seiten der anderen unangetastet); ohne eingetragene
     *    Domain an den Startpunkten laeuft der Schritt gar nicht,
     *  - ein Fehler oder eine unvollstaendige Liste bricht den Schritt ab,
     *  - nichts, was juenger als zwei Stunden ist, und hoechstens 200 Loeschungen je Lauf.
     *
     * @return int Anzahl der geloeschten Eintraege
     */
    private function purgeOrphans(): int
    {
        $db = Database::getInstance();
        $roots = $db->execute("SELECT id, dns FROM tl_page WHERE type='root'");
        $hosts = [];
        $rootByKey = [];

        while ($roots->next()) {
            if ('' !== (string) $roots->dns) {
                $hosts[strtolower((string) $roots->dns)] = true;
            }

            $key = $this->chatbotApi->rootApiKey((int) $roots->id);

            if ('' !== $key && !isset($rootByKey[$key])) {
                $rootByKey[$key] = (int) $roots->id;
            }
        }

        if ([] === $hosts || [] === $rootByKey) {
            return 0;
        }

        $pageIds = [];
        $pages = $db->execute('SELECT id FROM tl_page');

        while ($pages->next()) {
            $pageIds[(int) $pages->id] = true;
        }

        if ([] === $pageIds) {
            return 0;
        }

        $removed = 0;
        $baseUrls = [];

        foreach ($rootByKey as $rootId) {
            $offset = 0;
            $limit = 1000;
            $seen = [];

            do {
                $response = $this->chatbotApi->listContent($limit, $offset, $rootId);

                // Eine Teilliste darf nicht als vollstaendige gelten.
                if (isset($response['success']) && false === $response['success']) {
                    break;
                }

                $items = \is_array($response['data'] ?? null) ? $response['data'] : [];

                foreach ($items as $item) {
                    $externalId = (string) ($item['externalId'] ?? '');

                    if ('' === $externalId || isset($seen[$externalId])) {
                        continue;
                    }

                    $seen[$externalId] = true;

                    if (!$this->isOrphan($item, $externalId, $hosts, $pageIds, $baseUrls)) {
                        continue;
                    }

                    if ($removed >= self::MAX_ORPHANS_PER_RUN) {
                        break 2;
                    }

                    $result = $this->chatbotApi->deleteContent($externalId, $rootId);

                    if (TrainingState::deleteSucceeded($result)) {
                        $this->trainingState->forgetVariant($externalId);
                        ++$removed;
                    }
                }

                $offset += $limit;
            } while (\count($items) >= $limit && $offset < 100000);
        }

        return $removed;
    }

    /**
     * @param array<string, bool>        $hosts
     * @param array<int, bool>           $pageIds
     * @param array<int, string|null>    $baseUrls Merker je Seite
     */
    private function isOrphan(array $item, string $externalId, array $hosts, array $pageIds, array &$baseUrls): bool
    {
        $pageId = TrainingState::ownerPageId($externalId);

        if (null === $pageId) {
            return false;
        }

        $host = strtolower((string) parse_url((string) ($item['url'] ?? ''), PHP_URL_HOST));

        if ('' === $host || !isset($hosts[$host])) {
            return false;
        }

        $created = isset($item['createdAt']) ? strtotime((string) $item['createdAt']) : false;

        if (false === $created || $created > time() - self::ORPHAN_GRACE) {
            return false;
        }

        if (!isset($pageIds[$pageId])) {
            return true;
        }

        if (!TrainingState::isVariantId($externalId)) {
            return false;
        }

        if (!\array_key_exists($pageId, $baseUrls)) {
            $baseUrls[$pageId] = TrainingState::basePageUrl(PageModel::findById($pageId));
        }

        return !\in_array($externalId, $this->trainingState->variantIds($pageId, $baseUrls[$pageId]), true);
    }
}
