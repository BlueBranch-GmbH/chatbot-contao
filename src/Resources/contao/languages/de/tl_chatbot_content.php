<?php

$GLOBALS['TL_LANG']['tl_chatbot_content']['title_legend'] = 'Titel und Art';
$GLOBALS['TL_LANG']['tl_chatbot_content']['content_legend'] = 'Inhalt';
$GLOBALS['TL_LANG']['tl_chatbot_content']['config_legend'] = 'Einstellungen';
$GLOBALS['TL_LANG']['tl_chatbot_content']['publish_legend'] = 'Veröffentlichung';

$GLOBALS['TL_LANG']['tl_chatbot_content']['title'] = ['Titel', 'Die KI liest ihn als Überschrift; Besuchern wird er nicht als Quelle gezeigt.'];
$GLOBALS['TL_LANG']['tl_chatbot_content']['type'] = ['Art', 'Text (z. B. Öffnungszeiten, Ansprechpartner) oder Datei.'];
$GLOBALS['TL_LANG']['tl_chatbot_content']['type_options'] = ['note' => 'Text', 'file' => 'Datei'];
$GLOBALS['TL_LANG']['tl_chatbot_content']['text'] = ['Text', 'Reiner Text. Wird so an die KI übertragen.'];
$GLOBALS['TL_LANG']['tl_chatbot_content']['file'] = ['Datei', 'TXT, MD, CSV, PDF, DOCX, ODT oder HTML bis 20 MB. Ausgelesen wird auf diesem Server; die KI bekommt nur Text. Gescannte PDFs ohne Textebene gehen nicht.'];
$GLOBALS['TL_LANG']['tl_chatbot_content']['root'] = ['Website', 'Startpunkt, dessen API-Key gilt. Leer = globaler Schlüssel.'];
$GLOBALS['TL_LANG']['tl_chatbot_content']['language'] = ['Sprache', 'Sprachkürzel, z. B. de.'];
$GLOBALS['TL_LANG']['tl_chatbot_content']['published'] = ['Für den Chatbot aktiv', 'Nur aktive Einträge kennt der Chatbot; Deaktivieren nimmt den Inhalt aus dem KI-Index.'];
$GLOBALS['TL_LANG']['tl_chatbot_content']['status_options'] = [
    'trained' => 'übertragen',
    'truncated' => 'übertragen (gekürzt)',
    'error' => 'Fehler',
    'removed' => 'nicht im Index',
];

$GLOBALS['TL_LANG']['tl_chatbot_content']['new'] = ['Neuer Zusatzinhalt', 'Einen neuen Zusatzinhalt anlegen'];
$GLOBALS['TL_LANG']['tl_chatbot_content']['sync'] = ['Alle neu übertragen', 'Alle aktiven Zusatzinhalte erneut an die KI übertragen'];
$GLOBALS['TL_LANG']['tl_chatbot_content']['edit'] = 'Zusatzinhalt ID %s bearbeiten';
$GLOBALS['TL_LANG']['tl_chatbot_content']['delete'] = 'Zusatzinhalt ID %s löschen';
$GLOBALS['TL_LANG']['tl_chatbot_content']['toggle'] = 'Zusatzinhalt ID %s aktivieren/deaktivieren';
$GLOBALS['TL_LANG']['tl_chatbot_content']['show'] = 'Details des Zusatzinhalts ID %s anzeigen';
