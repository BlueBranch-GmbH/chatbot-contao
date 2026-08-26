<?php

$GLOBALS['TL_DCA']['tl_page']['palettes']['root'] = str_replace(
    '{publish_legend}',
    '{chatbot_legend},chatbot_api_key;{publish_legend}',
    $GLOBALS['TL_DCA']['tl_page']['palettes']['root']
);

$GLOBALS['TL_DCA']['tl_page']['palettes']['rootfallback'] = str_replace(
    '{publish_legend}',
    '{chatbot_legend},chatbot_api_key;{publish_legend}',
    $GLOBALS['TL_DCA']['tl_page']['palettes']['rootfallback']
);

$GLOBALS['TL_DCA']['tl_page']['fields']['chatbot_api_key'] = [
    'label'     => &$GLOBALS['TL_LANG']['tl_page']['chatbot_api_key'],
    'exclude'   => true,
    'inputType' => 'text',
    'eval'      => ['mandatory' => false, 'tl_class' => 'w50', 'decodeEntities' => true],
    'sql'       => "varchar(255) NOT NULL default ''",
];

// Hinweis: onDeletePage/onSubmitPage werden bereits über die #[AsCallback]-Attribute
// in IndexPageListener registriert. Hier NICHT zusätzlich manuell eintragen, sonst
// werden die Callbacks doppelt ausgeführt (siehe Contao\CoreBundle\EventListener\
// DataContainerCallbackListener, das über den loadDataContainer-Hook in dasselbe
// TL_DCA-Array schreibt).
