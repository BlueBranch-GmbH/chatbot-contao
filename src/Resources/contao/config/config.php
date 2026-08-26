<?php

use Bluebranch\Chatbot\Controller\Backend\TrainedContentController;

$GLOBALS['BE_MOD']['bluebranch_chatbot'] = [
    'chatbot_trained_content' => [
        'callback'   => TrainedContentController::class,
    ],
];
