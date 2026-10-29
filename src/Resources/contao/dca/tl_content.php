<?php

$GLOBALS['TL_DCA']['tl_content']['palettes']['indexer_stop'] = '{type_legend},type;{invisible_legend:hide},invisible,start,stop';
$GLOBALS['TL_DCA']['tl_content']['palettes']['indexer_continue'] = '{type_legend},type;{invisible_legend:hide},invisible,start,stop';

// Add onsubmit_callback to tl_content
$GLOBALS['TL_DCA']['tl_content']['config']['onsubmit_callback'][] = [\Bluebranch\Chatbot\classes\ContentIndexerCallbackListener::class, 'createIndexerContinue'];

// Add to CTE group
if (!is_array($GLOBALS['TL_CTE'])) {
    $GLOBALS['TL_CTE'] = [];
}

$GLOBALS['TL_CTE']['chatbot'] = [
    'indexer_stop'     => \Bluebranch\Chatbot\classes\ContentIndexer::class,
    'indexer_continue' => \Bluebranch\Chatbot\classes\ContentIndexer::class,
];
