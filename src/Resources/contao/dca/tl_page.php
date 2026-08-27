<?php

use Contao\CoreBundle\DataContainer\PaletteManipulator;

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

$GLOBALS['TL_DCA']['tl_page']['fields']['chatbot_noAnswers'] = [
    'label'     => &$GLOBALS['TL_LANG']['tl_page']['chatbot_noAnswers'],
    'exclude'   => true,
    'inputType' => 'checkbox',
    'eval'      => ['tl_class' => 'w50 m12'],
    'sql'       => "char(1) NOT NULL default ''",
];

/*
 * Das Feld gehoert neben "Von der Suche ausschliessen" - dort sucht es der Redakteur, weil beide
 * dasselbe entscheiden: ob diese Seite als Quelle dient.
 *
 * Eingefuegt wird ueber alle Paletten, die `noSearch` fuehren, statt ueber eine feste Liste:
 * Welche das sind, unterscheidet sich zwischen den Contao-Versionen, und eine vergessene Palette
 * faellt niemandem auf - das Feld fehlt dann still bei einem Seitentyp.
 */
foreach (array_keys($GLOBALS['TL_DCA']['tl_page']['palettes']) as $palette) {
    if ('__selector__' === $palette || !\is_string($GLOBALS['TL_DCA']['tl_page']['palettes'][$palette])) {
        continue;
    }

    if (!str_contains($GLOBALS['TL_DCA']['tl_page']['palettes'][$palette], 'noSearch')) {
        continue;
    }

    PaletteManipulator::create()
        ->addField('chatbot_noAnswers', 'noSearch', PaletteManipulator::POSITION_AFTER)
        ->applyToPalette($palette, 'tl_page');
}

// Hinweis: onDeletePage/onSubmitPage werden bereits über die #[AsCallback]-Attribute
// in IndexPageListener registriert. Hier NICHT zusätzlich manuell eintragen, sonst
// werden die Callbacks doppelt ausgeführt (siehe Contao\CoreBundle\EventListener\
// DataContainerCallbackListener, das über den loadDataContainer-Hook in dasselbe
// TL_DCA-Array schreibt).
