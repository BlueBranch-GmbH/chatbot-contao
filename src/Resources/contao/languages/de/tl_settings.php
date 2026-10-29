<?php

$GLOBALS['TL_LANG']['tl_settings']['chatbot_legend'] = 'Chatbot – Einrichtung';
$GLOBALS['TL_LANG']['tl_settings']['chatbot_info'] = 'Am einfachsten richten Sie alles unter <strong>BlueBranch Chatbot → Erste Schritte</strong> ein (Zugang, Training, Anzeige, Test). Hier stehen die Vorgaben für alle Chat-Module; jedes Modul kann sie überschreiben. Zugang und Anleitung: <a href="https://chatbot.bluebranch.de/" target="_blank" rel="noopener">chatbot.bluebranch.de</a>.';
$GLOBALS['TL_LANG']['tl_settings']['chatbot_default_name'] = ['Name des Chatbots', 'Steht im Kopf des Chats, wenn das Modul keinen eigenen Namen hat.'];
$GLOBALS['TL_LANG']['tl_settings']['chatbot_default_color'] = ['Akzentfarbe', 'Farbe für Widget, Frage-Modul und Suche, wenn das Modul keine eigene hat.'];
$GLOBALS['TL_LANG']['tl_settings']['chatbot_default_icon'] = ['Icon (SVG)', 'Ersetzt das Standard-Symbol, wenn das Modul kein eigenes hat.'];
$GLOBALS['TL_LANG']['tl_settings']['chatbot_default_greeting'] = ['Begrüßung', 'Erste Nachricht beim Öffnen des Chats, wenn das Modul keine eigene hat.'];
$GLOBALS['TL_LANG']['tl_settings']['chatbot_default_suggestions'] = ['Fragen-Vorschläge', 'Klickbare Fragen über dem Eingabefeld, wenn das Modul keine eigenen hat.'];
$GLOBALS['TL_LANG']['tl_settings']['chatbot_default_hide_summarize'] = ['„Inhalt zusammenfassen“ ausblenden', 'Blendet den Zusammenfassen-Knopf standardmäßig in allen Chat-Widgets aus.'];
$GLOBALS['TL_LANG']['tl_settings']['chatbot_default_hide_disclaimer'] = ['Datenschutz-Hinweis ausblenden', 'Blendet „Private Chats & Hosted in Germany“ standardmäßig in allen Chat-Widgets aus.'];
$GLOBALS['TL_LANG']['tl_settings']['chatbot_purge_legend'] = 'Automatische Bereinigung des KI-Index';
$GLOBALS['TL_LANG']['tl_settings']['chatbot_purge_enabled'] = ['Automatische Bereinigung aktivieren', 'Entfernt regelmäßig Seiten aus dem KI-Index, die nicht mehr veröffentlicht, von der Suche ausgeschlossen oder über das Start-/Stop-Datum abgelaufen sind (ein zeitgesteuertes Ablaufen passiert ohne Speichern des Datensatzes und wird sonst nicht erkannt). Läuft über Contaos eigenes Cron-System, dafür ist kein Server-Cronjob nötig - es reicht, wenn die Website regelmäßig aufgerufen wird.'];
$GLOBALS['TL_LANG']['tl_settings']['chatbot_purge_interval'] = ['Intervall', 'Wie oft der Bereinigungslauf höchstens ausgelöst wird.'];
$GLOBALS['TL_LANG']['tl_settings']['chatbot_purge_interval_options'] = [
    60 => 'Stündlich',
    360 => 'Alle 6 Stunden',
    1440 => 'Täglich',
    10080 => 'Wöchentlich',
];

$GLOBALS['TL_LANG']['tl_settings']['chatbot_debug_legend'] = 'Fehlersuche';
$GLOBALS['TL_LANG']['tl_settings']['chatbot_debug'] = ['Debug-Dateien schreiben', 'Legt zu jedem Trainings- und Löschvorgang eine JSON-Datei unter var/chatbot/ ab. Nur zur Fehlersuche einschalten: Beim Crawler-Lauf entsteht eine Datei je Seite samt vollständigem Seiteninhalt, und das Verzeichnis wächst unbegrenzt.'];

$GLOBALS['TL_LANG']['tl_settings']['chatbot_log_legend'] = 'Fragen, Antworten und Feedback';
$GLOBALS['TL_LANG']['tl_settings']['chatbot_log_enabled'] = ['Fragen und Antworten speichern', 'Speichert jede Frage mit der Antwort für spätere Auswertungen (Backend: „Fragen & Feedback“). Ohne IP-Adresse, Browserdaten oder Sitzungskennung; E-Mail-Adressen und Telefonnummern in Fragen werden maskiert. Auch ohne diesen Schalter wird eine Antwort gespeichert, wenn ein Besucher sie bewertet (Feedback im Modul aktivieren).'];
$GLOBALS['TL_LANG']['tl_settings']['chatbot_log_retention'] = ['Aufbewahrung (Tage)', 'Ältere Einträge werden täglich gelöscht. 0 = unbegrenzt.'];
$GLOBALS['TL_LANG']['tl_settings']['chatbot_feedback_default'] = ['Feedback zu Antworten abfragen', 'Daumen hoch/runter unter jeder Antwort; je Modul abweichend einstellbar.'];
$GLOBALS['TL_LANG']['tl_settings']['chatbot_voice_default'] = ['Spracheingabe (Mikrofon) anbieten', 'Vorgabe für alle Module; keine Speicherung. Bitte in der Datenschutzerklärung nennen.'];
$GLOBALS['TL_LANG']['tl_settings']['chatbot_global_widget'] = ['Chat auf allen Seiten anzeigen', 'Erscheint ohne Layout-Eintrag auf jeder Seite. Leer = aus.'];
$GLOBALS['TL_LANG']['tl_settings']['chatbot_look_legend'] = 'Chatbot – Aussehen';
$GLOBALS['TL_LANG']['tl_settings']['chatbot_text_legend'] = 'Chatbot – Texte und Hinweise';
