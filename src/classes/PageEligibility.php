<?php

namespace Bluebranch\Chatbot\classes;

use Contao\Database;

/**
 * Entscheidet, ob eine Seite in den KI-Index gehört.
 *
 * Die Regeln lagen vorher an drei Stellen verstreut (IndexPageListener, Cronjob, Backend).
 * Kommt eine Regel hinzu - wie der manuelle Ausschluss über `chatbot_noAnswers` -, muss sie sonst
 * dreimal gepflegt werden, und eine vergessene Stelle fällt niemandem auf: Der Index enthält dann
 * still Seiten, die der Redakteur ausgeschlossen zu haben glaubt.
 */
class PageEligibility
{
    /**
     * Merker je Seiten-ID innerhalb eines Requests.
     *
     * Der Cronjob prüft hunderte Seiten, die sich denselben Elternpfad teilen. Ohne den Merker
     * liefe die Elternabfrage für jede davon erneut.
     */
    private array $cache = [];

    /**
     * Ob die Seite selbst oder einer ihrer Vorfahren von KI-Antworten ausgeschlossen ist.
     *
     * Die Vererbung ist der Punkt: Wer eine Rubrik ausschließt, meint den ganzen Zweig. Ein
     * Redakteur, der die Rubrik „Interna" abhakt und danach jede Unterseite einzeln nachpflegen
     * müsste, hätte die Einstellung ebenso gut weglassen können.
     */
    public function isExcludedFromAnswers(int $pageId): bool
    {
        if ($pageId < 1) {
            return false;
        }

        if (isset($this->cache[$pageId])) {
            return $this->cache[$pageId];
        }

        $ids = array_merge([$pageId], $this->parentIds($pageId));

        $result = Database::getInstance()
            ->prepare(
                'SELECT COUNT(*) AS anzahl FROM tl_page WHERE id IN ('
                . implode(',', array_fill(0, \count($ids), '?'))
                . ") AND chatbot_noAnswers='1'"
            )
            ->execute(...$ids);

        return $this->cache[$pageId] = ((int) $result->anzahl) > 0;
    }

    /**
     * Ob die Seite überhaupt in den Index darf: veröffentlicht, im Zeitfenster, nicht von der
     * Suche und nicht von den KI-Antworten ausgeschlossen.
     */
    public function isEligible(int $pageId): bool
    {
        if ($pageId < 1) {
            return false;
        }

        $row = Database::getInstance()
            ->prepare('SELECT published, noSearch, start, stop FROM tl_page WHERE id=?')
            ->limit(1)
            ->execute($pageId);

        if ($row->numRows < 1) {
            return false;
        }

        $jetzt = time();

        if (!$row->published || $row->noSearch) {
            return false;
        }

        if ($row->start !== '' && (int) $row->start > $jetzt) {
            return false;
        }

        if ($row->stop !== '' && $row->stop !== '0' && (int) $row->stop < $jetzt) {
            return false;
        }

        return !$this->isExcludedFromAnswers($pageId);
    }

    /**
     * Die Seite selbst und alle ihre Nachfahren.
     *
     * Gebraucht beim Setzen der Sperre auf einer Überseite: Deren Kinder stehen zu diesem
     * Zeitpunkt noch im Index und müssen mit hinaus. Der stündliche Cronjob würde sie zwar auch
     * erwischen, aber eine Sperre, die erst in einer Stunde wirkt, ist keine.
     */
    public function branchIds(int $pageId): array
    {
        if ($pageId < 1) {
            return [];
        }

        $ids = [$pageId];
        $ebene = [$pageId];

        // Iterativ statt rekursiv: Die Verschachtelungstiefe eines Seitenbaums ist unbekannt, und
        // eine Rekursion über eine defekte pid-Kette liefe bis zum Stack-Ende.
        while ($ebene !== []) {
            $result = Database::getInstance()
                ->prepare(
                    'SELECT id FROM tl_page WHERE pid IN ('
                    . implode(',', array_fill(0, \count($ebene), '?'))
                    . ')'
                )
                ->execute(...$ebene);

            $naechste = [];

            while ($result->next()) {
                $id = (int) $result->id;

                // Schutz vor Zyklen in der pid-Kette: ohne ihn liefe die Schleife endlos.
                if (\in_array($id, $ids, true)) {
                    continue;
                }

                $ids[] = $id;
                $naechste[] = $id;
            }

            $ebene = $naechste;
        }

        return $ids;
    }

    /** Leert den Merker - nötig, wenn sich die Einstellung im selben Request ändert. */
    public function reset(): void
    {
        $this->cache = [];
    }

    /**
     * Die IDs aller Vorfahren.
     *
     * `Database::getParentRecords()` liefert die Kette samt Startseite, allerdings als Strings und
     * inklusive der eigenen ID. Hier wird selbst gelaufen, um beides eindeutig zu halten.
     */
    private function parentIds(int $pageId): array
    {
        $ids = [];
        $aktuell = $pageId;

        while (true) {
            $row = Database::getInstance()
                ->prepare('SELECT pid FROM tl_page WHERE id=?')
                ->limit(1)
                ->execute($aktuell);

            if ($row->numRows < 1) {
                break;
            }

            $pid = (int) $row->pid;

            if ($pid < 1 || \in_array($pid, $ids, true)) {
                break;
            }

            $ids[] = $pid;
            $aktuell = $pid;
        }

        return $ids;
    }
}
