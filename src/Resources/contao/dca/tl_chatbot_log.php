<?php

/*
 * Fragen, Antworten und Feedback der Besucher.
 *
 * Diese DCA beschreibt nur das Schema. Angezeigt wird die Tabelle über
 * ChatLogController: Die Spalten enthalten, was Besucher eingetippt haben, und Contaos
 * Detailansicht gibt Textfelder ungefiltert aus - sie geht davon aus, dass der Inhalt beim
 * Speichern über ein Widget kodiert wurde. Das ist hier nicht der Fall.
 *
 * Bewusst ohne Spalte für IP, User-Agent oder Sitzung.
 */
$GLOBALS['TL_DCA']['tl_chatbot_log'] = [
    'config' => [
        'dataContainer' => \Contao\DC_Table::class,
        'closed' => true,
        'notEditable' => true,
        'notCopyable' => true,
        'sql' => [
            'keys' => [
                'id' => 'primary',
                'ref' => 'unique',
                'tstamp' => 'index',
                'rating' => 'index',
            ],
        ],
    ],
    'fields' => [
        'id' => ['sql' => 'int(10) unsigned NOT NULL auto_increment'],
        'tstamp' => ['sql' => "int(10) unsigned NOT NULL default '0'"],
        // Zufaellige Kennung, ueber die der Browser Feedback gibt. Die laufende ID verlaesst
        // den Server nie - sonst liesse sich fremdes Feedback ueberschreiben.
        'ref' => ['sql' => "char(32) NOT NULL default ''"],
        'root_id' => ['sql' => "int(10) unsigned NOT NULL default '0'"],
        'page_id' => ['sql' => "int(10) unsigned NOT NULL default '0'"],
        'source' => ['sql' => "varchar(16) NOT NULL default ''"],
        'language' => ['sql' => "varchar(64) NOT NULL default ''"],
        'question' => ['sql' => 'text NULL'],
        'answer' => ['sql' => 'mediumtext NULL'],
        'sources' => ['sql' => 'text NULL'],
        'rating' => ['sql' => "varchar(4) NOT NULL default ''"],
        'comment' => ['sql' => 'text NULL'],
        'feedback_at' => ['sql' => "int(10) unsigned NOT NULL default '0'"],
    ],
];
