<?php

use Contao\DC_Table;

/*
 * Zusatzinhalte: Texte (Meta-Infos) und Dateien, die der Chatbot zusaetzlich zu den Seiten kennt.
 *
 * Uebertragen wird beim Speichern und beim Umschalten der Sichtbarkeit (ExtraContentListener),
 * geloescht beim Loeschen. Die Spalten ab `file_hash` schreibt nur die Erweiterung.
 */
$GLOBALS['TL_DCA']['tl_chatbot_content'] = [
    'config' => [
        'dataContainer' => DC_Table::class,
        'enableVersioning' => true,
        'sql' => [
            'keys' => [
                'id' => 'primary',
            ],
        ],
    ],
    'list' => [
        'sorting' => [
            'mode' => 1,
            'fields' => ['title'],
            'flag' => 1,
            'panelLayout' => 'filter;search,limit',
        ],
        'label' => [
            'fields' => ['title', 'type'],
            'format' => '%s',
        ],
        'global_operations' => [
            'sync' => [
                'href' => 'key=sync',
                'class' => 'header_sync',
                'icon' => 'sync.svg',
                'attributes' => 'onclick="Backend.getScrollOffset()"',
            ],
            'all' => [
                'href' => 'act=select',
                'class' => 'header_edit_all',
                'attributes' => 'onclick="Backend.getScrollOffset()" accesskey="e"',
            ],
        ],
        'operations' => [
            'edit' => [
                'href' => 'act=edit',
                'icon' => 'edit.svg',
            ],
            'delete' => [
                'href' => 'act=delete',
                'icon' => 'delete.svg',
                'attributes' => 'onclick="if(!confirm(\'' . ($GLOBALS['TL_LANG']['MSC']['deleteConfirm'] ?? 'Löschen?') . '\'))return false;Backend.getScrollOffset()"',
            ],
            'toggle' => [
                'href' => 'act=toggle&amp;field=published',
                'icon' => 'visible.svg',
            ],
            'show' => [
                'href' => 'act=show',
                'icon' => 'show.svg',
            ],
        ],
    ],
    'palettes' => [
        '__selector__' => ['type'],
        'default' => '{title_legend},title,type;{content_legend},text;{config_legend},root,language;{publish_legend},published',
        'file' => '{title_legend},title,type;{content_legend},file;{config_legend},root,language;{publish_legend},published',
    ],
    'fields' => [
        'id' => ['sql' => 'int(10) unsigned NOT NULL auto_increment'],
        'tstamp' => ['sql' => "int(10) unsigned NOT NULL default '0'"],
        'title' => [
            'exclude' => true,
            'search' => true,
            'inputType' => 'text',
            'eval' => ['mandatory' => true, 'maxlength' => 255, 'tl_class' => 'w50'],
            'sql' => "varchar(255) NOT NULL default ''",
        ],
        'type' => [
            'exclude' => true,
            'filter' => true,
            'inputType' => 'select',
            'options' => ['note', 'file'],
            'reference' => &$GLOBALS['TL_LANG']['tl_chatbot_content']['type_options'],
            'eval' => ['submitOnChange' => true, 'tl_class' => 'w50'],
            'sql' => "varchar(8) NOT NULL default 'note'",
        ],
        'text' => [
            'exclude' => true,
            'search' => true,
            'inputType' => 'textarea',
            'eval' => ['mandatory' => true, 'tl_class' => 'clr', 'rows' => 12],
            'sql' => 'mediumtext NULL',
        ],
        'file' => [
            'exclude' => true,
            'inputType' => 'fileTree',
            'eval' => ['mandatory' => true, 'filesOnly' => true, 'fieldType' => 'radio', 'extensions' => 'txt,md,csv,pdf,docx,odt,html,htm', 'tl_class' => 'clr'],
            'sql' => 'binary(16) NULL',
        ],
        'root' => [
            'exclude' => true,
            'filter' => true,
            'inputType' => 'select',
            'eval' => ['includeBlankOption' => true, 'blankOptionLabel' => '-', 'tl_class' => 'w50'],
            'sql' => "int(10) unsigned NOT NULL default '0'",
        ],
        'language' => [
            'exclude' => true,
            'inputType' => 'text',
            'default' => 'de',
            'eval' => ['maxlength' => 5, 'rgxp' => 'language', 'tl_class' => 'w50'],
            'sql' => "varchar(5) NOT NULL default 'de'",
        ],
        'published' => [
            'exclude' => true,
            'toggle' => true,
            'filter' => true,
            'inputType' => 'checkbox',
            'default' => true,
            'eval' => ['doNotCopy' => true],
            'sql' => "char(1) NOT NULL default '1'",
        ],
        'file_hash' => ['sql' => "varchar(32) NOT NULL default ''"],
        'chatbot_checksum' => ['sql' => 'varchar(32) NULL'],
        'trained_at' => ['sql' => "int(10) unsigned NOT NULL default '0'"],
        'chars' => ['sql' => "int(10) unsigned NOT NULL default '0'"],
        'status' => ['sql' => "varchar(16) NOT NULL default ''"],
        'error' => ['sql' => "varchar(255) NOT NULL default ''"],
    ],
];
