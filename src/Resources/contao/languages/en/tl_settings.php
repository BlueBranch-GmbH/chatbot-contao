<?php

$GLOBALS['TL_LANG']['tl_settings']['chatbot_legend'] = 'Chatbot settings';
$GLOBALS['TL_LANG']['tl_settings']['chatbot_default_name'] = ['Chatbot name', 'Default name shown in the chat header when the module does not define its own name.'];
$GLOBALS['TL_LANG']['tl_settings']['chatbot_default_color'] = ['Accent color', 'Default accent color of the chat widget when the module does not define its own color.'];
$GLOBALS['TL_LANG']['tl_settings']['chatbot_default_icon'] = ['Icon (SVG)', 'Replaces the default AI icon on the chat button, if the module does not define its own icon.'];
$GLOBALS['TL_LANG']['tl_settings']['chatbot_default_greeting'] = ['Default greeting', 'Shown when the chat is opened for the first time, if the module does not define its own greeting.'];
$GLOBALS['TL_LANG']['tl_settings']['chatbot_default_suggestions'] = ['Default suggestions', 'Suggested question pills above the input field, if the module does not define its own suggestions.'];
$GLOBALS['TL_LANG']['tl_settings']['chatbot_default_hide_summarize'] = ['Hide "Summarize content" pill', 'Hides the summarize-page-content pill by default for all chat widgets.'];
$GLOBALS['TL_LANG']['tl_settings']['chatbot_default_hide_disclaimer'] = ['Hide privacy notice', 'Hides the "Private chats & Hosted in Germany" notice by default for all chat widgets.'];

$GLOBALS['TL_LANG']['tl_settings']['chatbot_purge_legend'] = 'Automatic AI index cleanup';
$GLOBALS['TL_LANG']['tl_settings']['chatbot_purge_enabled'] = ['Enable automatic cleanup', 'Periodically removes pages from the AI index that are no longer published, excluded from search, or have expired via their start/stop date (a scheduled expiry happens without saving the record and is otherwise not detected). Runs through Contao\'s own cron system, so no server cronjob is required - it just needs the site to receive regular visits.'];
$GLOBALS['TL_LANG']['tl_settings']['chatbot_purge_interval'] = ['Interval', 'How often the cleanup run is triggered at most.'];
$GLOBALS['TL_LANG']['tl_settings']['chatbot_purge_interval_options'] = [
    60 => 'Hourly',
    360 => 'Every 6 hours',
    1440 => 'Daily',
    10080 => 'Weekly',
];
