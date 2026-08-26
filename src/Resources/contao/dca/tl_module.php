<?php

use Contao\BackendUser;
use Contao\CoreBundle\Exception\AccessDeniedException;
use Contao\DataContainer;

$GLOBALS['TL_DCA']['tl_module']['palettes']['chatbot_generate_search'] = '{title_legend},name,headline,type;{config_legend},chatbot_query_param;{template_legend:hide},customTpl;{protected_legend:hide},protected;{expert_legend:hide},guests,cssID';

$GLOBALS['TL_DCA']['tl_module']['fields']['chatbot_query_param'] = [
    'label'                   => &$GLOBALS['TL_LANG']['tl_module']['chatbot_query_param'],
    'exclude'                 => true,
    'inputType'               => 'text',
    'eval'                    => ['mandatory'=>true, 'maxlength'=>64, 'tl_class'=>'w50', 'nospace'=>true],
    'sql'                     => "varchar(64) NOT NULL default 'keywords'"
];

$GLOBALS['TL_DCA']['tl_module']['palettes']['chatbot_widget'] = '{title_legend},name,headline,type;{config_legend},chatbot_widget_position,chatbot_widget_color,chatbot_widget_icon,chatbot_widget_unstyled;{chatbot_legend},chatbot_widget_name,chatbot_widget_greeting,chatbot_widget_suggestions,chatbot_widget_hide_summarize,chatbot_widget_hide_disclaimer;{protected_legend:hide},protected;{expert_legend:hide},guests,cssID';

$GLOBALS['TL_DCA']['tl_module']['fields']['chatbot_widget_name'] = [
    'label'     => &$GLOBALS['TL_LANG']['tl_module']['chatbot_widget_name'],
    'exclude'   => true,
    'inputType' => 'text',
    'eval'      => ['tl_class' => 'w50', 'decodeEntities' => true],
    'sql'       => "varchar(255) NOT NULL default ''",
];

$GLOBALS['TL_DCA']['tl_module']['fields']['chatbot_widget_position'] = [
    'label'     => &$GLOBALS['TL_LANG']['tl_module']['chatbot_widget_position'],
    'exclude'   => true,
    'inputType' => 'select',
    'options'   => ['bottom-right', 'bottom-left', 'top-right', 'top-left'],
    'reference' => &$GLOBALS['TL_LANG']['tl_module']['chatbot_widget_position_options'],
    'eval'      => ['tl_class' => 'w50', 'includeBlankOption' => false],
    'sql'       => "varchar(32) NOT NULL default 'bottom-right'",
];

$GLOBALS['TL_DCA']['tl_module']['fields']['chatbot_widget_color'] = [
    'label'     => &$GLOBALS['TL_LANG']['tl_module']['chatbot_widget_color'],
    'exclude'   => true,
    'inputType' => 'text',
    'eval'      => ['tl_class' => 'w50', 'colorpicker' => true, 'isHexColor' => true, 'decodeEntities' => true, 'maxlength' => 6],
    'sql'       => "varchar(6) NOT NULL default ''",
];

$GLOBALS['TL_DCA']['tl_module']['fields']['chatbot_widget_icon'] = [
    'label'     => &$GLOBALS['TL_LANG']['tl_module']['chatbot_widget_icon'],
    'exclude'   => true,
    'inputType' => 'fileTree',
    'eval'      => ['filesOnly' => true, 'fieldType' => 'radio', 'extensions' => 'svg', 'mandatory' => false, 'tl_class' => 'w50'],
    'sql'       => "binary(16) NULL",
];

$GLOBALS['TL_DCA']['tl_module']['fields']['chatbot_widget_greeting'] = [
    'label'     => &$GLOBALS['TL_LANG']['tl_module']['chatbot_widget_greeting'],
    'exclude'   => true,
    'inputType' => 'text',
    'eval'      => ['tl_class' => 'w50', 'decodeEntities' => true],
    'sql'       => "varchar(255) NOT NULL default ''",
];

$GLOBALS['TL_DCA']['tl_module']['fields']['chatbot_widget_suggestions'] = [
    'label'     => &$GLOBALS['TL_LANG']['tl_module']['chatbot_widget_suggestions'],
    'exclude'   => true,
    'inputType' => 'listWizard',
    'eval'      => ['tl_class' => 'clr', 'allowHtml' => false],
    'sql'       => "blob NULL",
];

$GLOBALS['TL_DCA']['tl_module']['fields']['chatbot_widget_hide_summarize'] = [
    'label'     => &$GLOBALS['TL_LANG']['tl_module']['chatbot_widget_hide_summarize'],
    'exclude'   => true,
    'inputType' => 'checkbox',
    'eval'      => ['tl_class' => 'w50 m12'],
    'sql'       => "char(1) NOT NULL default ''",
];

$GLOBALS['TL_DCA']['tl_module']['fields']['chatbot_widget_hide_disclaimer'] = [
    'label'     => &$GLOBALS['TL_LANG']['tl_module']['chatbot_widget_hide_disclaimer'],
    'exclude'   => true,
    'inputType' => 'checkbox',
    'eval'      => ['tl_class' => 'w50 m12'],
    'sql'       => "char(1) NOT NULL default ''",
];

$GLOBALS['TL_DCA']['tl_module']['fields']['chatbot_widget_unstyled'] = [
    'label'     => &$GLOBALS['TL_LANG']['tl_module']['chatbot_widget_unstyled'],
    'exclude'   => true,
    'inputType' => 'checkbox',
    'eval'      => ['tl_class' => 'w50 m12'],
    'sql'       => "char(1) NOT NULL default ''",
];
