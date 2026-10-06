<?php

use Bluebranch\Chatbot\Controller\Backend\ChatLogController;
use Bluebranch\Chatbot\Controller\Backend\TrainedContentController;
use Bluebranch\Chatbot\EventListener\ExtraContentListener;

$GLOBALS['BE_MOD']['bluebranch_chatbot'] = [
    'chatbot_trained_content' => [
        'callback'   => TrainedContentController::class,
    ],
    'chatbot_log' => [
        'callback'   => ChatLogController::class,
    ],
    'chatbot_content' => [
        'tables'     => ['tl_chatbot_content'],
        'sync'       => [ExtraContentListener::class, 'syncAll'],
    ],
];

// Wie lange Fragen und Antworten aufbewahrt werden (Tage, 0 = unbegrenzt). In der localconfig.php
// ueberschreibbar ueber die Einstellungen.
$GLOBALS['TL_CONFIG']['chatbot_log_retention'] = 180;
