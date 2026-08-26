<?php

namespace Bluebranch\Chatbot\classes;

use Contao\ContentModel;
use Contao\DataContainer;

class ContentIndexerCallbackListener
{
    /**
     * Automatically create an indexer_continue element after an indexer_stop element
     */
    public function createIndexerContinue(DataContainer $dc): void
    {
        if ($dc->activeRecord->type !== 'indexer_stop') {
            return;
        }

        // Check if there is already an indexer_continue element after this one
        $objNext = ContentModel::findOneBy(['pid=?', 'ptable=?', 'sorting>?'], [$dc->activeRecord->pid, $dc->activeRecord->ptable, $dc->activeRecord->sorting], ['order' => 'sorting']);

        if ($objNext !== null && $objNext->type === 'indexer_continue') {
            return;
        }

        $objNew = new ContentModel();
        $objNew->pid = $dc->activeRecord->pid;
        $objNew->ptable = $dc->activeRecord->ptable;
        $objNew->type = 'indexer_continue';
        $objNew->sorting = $dc->activeRecord->sorting + 1;
        $objNew->tstamp = time();
        $objNew->save();
    }
}
