<?php

$GLOBALS['TL_DCA']['tl_settings']['palettes']['default'] = str_replace(
    ';{chmod_legend}',
    ';{chatbot_legend},chatbot_default_name,chatbot_default_color,chatbot_default_icon,chatbot_default_greeting,chatbot_default_suggestions,chatbot_default_hide_summarize,chatbot_default_hide_disclaimer;{chatbot_purge_legend},chatbot_purge_enabled,chatbot_purge_interval;{chmod_legend}',
    $GLOBALS['TL_DCA']['tl_settings']['palettes']['default']
);

$GLOBALS['TL_DCA']['tl_settings']['fields']['chatbot_default_name'] = [
    'label'     => &$GLOBALS['TL_LANG']['tl_settings']['chatbot_default_name'],
    'inputType' => 'text',
    'eval'      => ['tl_class' => 'w50', 'decodeEntities' => true],
];

$GLOBALS['TL_DCA']['tl_settings']['fields']['chatbot_default_color'] = [
    'label'     => &$GLOBALS['TL_LANG']['tl_settings']['chatbot_default_color'],
    'inputType' => 'text',
    'eval'      => ['tl_class' => 'w50', 'colorpicker' => true, 'isHexColor' => true, 'decodeEntities' => true, 'maxlength' => 6],
];

$GLOBALS['TL_DCA']['tl_settings']['fields']['chatbot_default_icon'] = [
    'label'     => &$GLOBALS['TL_LANG']['tl_settings']['chatbot_default_icon'],
    'inputType' => 'fileTree',
    'eval'      => ['filesOnly' => true, 'fieldType' => 'radio', 'extensions' => 'svg', 'tl_class' => 'clr'],
];

$GLOBALS['TL_DCA']['tl_settings']['fields']['chatbot_default_greeting'] = [
    'label'     => &$GLOBALS['TL_LANG']['tl_settings']['chatbot_default_greeting'],
    'inputType' => 'text',
    'eval'      => ['tl_class' => 'clr', 'decodeEntities' => true],
];

$GLOBALS['TL_DCA']['tl_settings']['fields']['chatbot_default_suggestions'] = [
    'label'     => &$GLOBALS['TL_LANG']['tl_settings']['chatbot_default_suggestions'],
    'inputType' => 'listWizard',
    'eval'      => ['tl_class' => 'clr', 'allowHtml' => false],
];

$GLOBALS['TL_DCA']['tl_settings']['fields']['chatbot_default_hide_summarize'] = [
    'label'     => &$GLOBALS['TL_LANG']['tl_settings']['chatbot_default_hide_summarize'],
    'inputType' => 'checkbox',
    'eval'      => ['tl_class' => 'w50 m12'],
];

$GLOBALS['TL_DCA']['tl_settings']['fields']['chatbot_default_hide_disclaimer'] = [
    'label'     => &$GLOBALS['TL_LANG']['tl_settings']['chatbot_default_hide_disclaimer'],
    'inputType' => 'checkbox',
    'eval'      => ['tl_class' => 'w50 m12'],
];

$GLOBALS['TL_DCA']['tl_settings']['fields']['chatbot_purge_enabled'] = [
    'label'     => &$GLOBALS['TL_LANG']['tl_settings']['chatbot_purge_enabled'],
    'inputType' => 'checkbox',
    'default'   => true,
    'eval'      => ['tl_class' => 'w50 m12', 'submitOnChange' => false],
];

$GLOBALS['TL_DCA']['tl_settings']['fields']['chatbot_purge_interval'] = [
    'label'     => &$GLOBALS['TL_LANG']['tl_settings']['chatbot_purge_interval'],
    'inputType' => 'select',
    'options'   => [60, 360, 1440, 10080],
    'reference' => &$GLOBALS['TL_LANG']['tl_settings']['chatbot_purge_interval_options'],
    'default'   => 1440,
    'eval'      => ['tl_class' => 'w50', 'includeBlankOption' => false],
];
