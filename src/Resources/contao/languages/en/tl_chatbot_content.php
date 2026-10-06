<?php

$GLOBALS['TL_LANG']['tl_chatbot_content']['title_legend'] = 'Title and kind';
$GLOBALS['TL_LANG']['tl_chatbot_content']['content_legend'] = 'Content';
$GLOBALS['TL_LANG']['tl_chatbot_content']['config_legend'] = 'Assignment';
$GLOBALS['TL_LANG']['tl_chatbot_content']['publish_legend'] = 'Publishing';

$GLOBALS['TL_LANG']['tl_chatbot_content']['title'] = ['Title', 'Internal title; the AI sees it as the heading of the content. It is never shown to visitors as a source.'];
$GLOBALS['TL_LANG']['tl_chatbot_content']['type'] = ['Kind', 'Text for meta information (e.g. opening hours, contacts, notes) or a file.'];
$GLOBALS['TL_LANG']['tl_chatbot_content']['type_options'] = ['note' => 'Text', 'file' => 'File'];
$GLOBALS['TL_LANG']['tl_chatbot_content']['text'] = ['Text', 'Plain text. Transferred to the AI as is.'];
$GLOBALS['TL_LANG']['tl_chatbot_content']['file'] = ['File', 'TXT, MD, CSV, PDF, DOCX, ODT or HTML (max. 20 MB). The text is extracted on this server – only text is sent to the AI, never the file. Scanned PDFs without a text layer do not work.'];
$GLOBALS['TL_LANG']['tl_chatbot_content']['root'] = ['Website', 'Website root whose API key is used. Empty = global key.'];
$GLOBALS['TL_LANG']['tl_chatbot_content']['language'] = ['Language', 'Language code, e.g. en.'];
$GLOBALS['TL_LANG']['tl_chatbot_content']['published'] = ['Active for the chatbot', 'Only active entries are known to the chatbot. Deactivating removes the content from the AI index.'];

$GLOBALS['TL_LANG']['tl_chatbot_content']['status_options'] = [
    'trained' => 'transferred',
    'truncated' => 'transferred (truncated)',
    'error' => 'error',
    'removed' => 'not in index',
];

$GLOBALS['TL_LANG']['tl_chatbot_content']['new'] = ['New additional content', 'Create new additional content'];
$GLOBALS['TL_LANG']['tl_chatbot_content']['sync'] = ['Transfer all again', 'Transfer all active additional content to the AI again'];
$GLOBALS['TL_LANG']['tl_chatbot_content']['edit'] = 'Edit additional content ID %s';
$GLOBALS['TL_LANG']['tl_chatbot_content']['delete'] = 'Delete additional content ID %s';
$GLOBALS['TL_LANG']['tl_chatbot_content']['toggle'] = 'Activate/deactivate additional content ID %s';
$GLOBALS['TL_LANG']['tl_chatbot_content']['show'] = 'Show details of additional content ID %s';
