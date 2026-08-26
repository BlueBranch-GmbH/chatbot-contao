<?php

$GLOBALS['TL_LANG']['tl_settings']['chatbot_legend'] = 'Chatbot-Einstellungen';
$GLOBALS['TL_LANG']['tl_settings']['chatbot_default_name'] = ['Name des Chatbots', 'Standard-Name, der im Chat-Header angezeigt wird, wenn im Modul kein eigener Name hinterlegt ist.'];
$GLOBALS['TL_LANG']['tl_settings']['chatbot_default_color'] = ['Akzentfarbe', 'Standard-Akzentfarbe des Chat-Widgets, wenn im Modul keine eigene Farbe hinterlegt ist.'];
$GLOBALS['TL_LANG']['tl_settings']['chatbot_default_icon'] = ['Icon (SVG)', 'Ersetzt das Standard-KI-Icon im Chat-Button, wenn im Modul kein eigenes Icon hinterlegt ist.'];
$GLOBALS['TL_LANG']['tl_settings']['chatbot_default_greeting'] = ['Standard-Begrüßung', 'Wird beim ersten Öffnen des Chats angezeigt, wenn im Modul keine eigene Begrüßung hinterlegt ist.'];
$GLOBALS['TL_LANG']['tl_settings']['chatbot_default_suggestions'] = ['Standard-Vorschläge', 'Vorschlags-Fragen als Pills über dem Eingabefeld, wenn im Modul keine eigenen Vorschläge hinterlegt sind.'];
$GLOBALS['TL_LANG']['tl_settings']['chatbot_default_hide_summarize'] = ['"Inhalt zusammenfassen"-Pill ausblenden', 'Blendet die Pill zum Zusammenfassen des Seiteninhalts standardmäßig für alle Chat-Widgets aus.'];
$GLOBALS['TL_LANG']['tl_settings']['chatbot_default_hide_disclaimer'] = ['Datenschutz-Hinweis ausblenden', 'Blendet den Hinweis "Private chats & Hosted in Germany" standardmäßig für alle Chat-Widgets aus.'];

$GLOBALS['TL_LANG']['tl_settings']['chatbot_purge_legend'] = 'Automatische Bereinigung des KI-Index';
$GLOBALS['TL_LANG']['tl_settings']['chatbot_purge_enabled'] = ['Automatische Bereinigung aktivieren', 'Entfernt regelmäßig Seiten aus dem KI-Index, die nicht mehr veröffentlicht, von der Suche ausgeschlossen oder über das Start-/Stop-Datum abgelaufen sind (ein zeitgesteuertes Ablaufen passiert ohne Speichern des Datensatzes und wird sonst nicht erkannt). Läuft über Contaos eigenes Cron-System, dafür ist kein Server-Cronjob nötig - es reicht, wenn die Website regelmäßig aufgerufen wird.'];
$GLOBALS['TL_LANG']['tl_settings']['chatbot_purge_interval'] = ['Intervall', 'Wie oft der Bereinigungslauf höchstens ausgelöst wird.'];
$GLOBALS['TL_LANG']['tl_settings']['chatbot_purge_interval_options'] = [
    60 => 'Stündlich',
    360 => 'Alle 6 Stunden',
    1440 => 'Täglich',
    10080 => 'Wöchentlich',
];
