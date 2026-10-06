<?php

$GLOBALS['TL_LANG']['tl_chatbot_content']['title_legend'] = 'Titel und Art';
$GLOBALS['TL_LANG']['tl_chatbot_content']['content_legend'] = 'Inhalt';
$GLOBALS['TL_LANG']['tl_chatbot_content']['config_legend'] = 'Zuordnung';
$GLOBALS['TL_LANG']['tl_chatbot_content']['publish_legend'] = 'Veröffentlichung';

$GLOBALS['TL_LANG']['tl_chatbot_content']['title'] = ['Titel', 'Interner Titel; die KI sieht ihn als Überschrift des Inhalts. Er wird Besuchern nicht als Quelle angezeigt.'];
$GLOBALS['TL_LANG']['tl_chatbot_content']['type'] = ['Art', 'Text für Meta-Infos (z. B. Öffnungszeiten, Ansprechpartner, Hinweise) oder eine Datei.'];
$GLOBALS['TL_LANG']['tl_chatbot_content']['type_options'] = ['note' => 'Text', 'file' => 'Datei'];
$GLOBALS['TL_LANG']['tl_chatbot_content']['text'] = ['Text', 'Reiner Text. Wird so an die KI übertragen.'];
$GLOBALS['TL_LANG']['tl_chatbot_content']['file'] = ['Datei', 'TXT, MD, CSV, PDF, DOCX, ODT oder HTML (max. 20 MB). Der Text wird auf diesem Server ausgelesen – an die KI geht nur Text, nie die Datei. Gescannte PDFs ohne Textebene funktionieren nicht.'];
$GLOBALS['TL_LANG']['tl_chatbot_content']['root'] = ['Website', 'Startpunkt, dessen API-Key verwendet wird. Leer = globaler Schlüssel.'];
$GLOBALS['TL_LANG']['tl_chatbot_content']['language'] = ['Sprache', 'Sprachkürzel, z. B. de.'];
$GLOBALS['TL_LANG']['tl_chatbot_content']['published'] = ['Für den Chatbot aktiv', 'Nur aktive Einträge kennt der Chatbot. Deaktivieren entfernt den Inhalt aus dem KI-Index.'];

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
