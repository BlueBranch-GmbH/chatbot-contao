<?php

namespace Bluebranch\Chatbot\classes;

use Contao\System;

/**
 * Die Texte der Feedback-Leiste fuer das JavaScript - gleich fuer Widget, Frage- und Suchmodul.
 */
class FeedbackStrings
{
    private const KEYS = [
        'feedbackQuestion' => 'War die Antwort hilfreich?',
        'feedbackUp' => 'Hilfreich',
        'feedbackDown' => 'Nicht hilfreich',
        'feedbackPlaceholder' => 'Was hat nicht gepasst? (optional)',
        'feedbackHint' => 'Bitte keine persönlichen Daten eingeben.',
        'feedbackSend' => 'Senden',
        'feedbackThanks' => 'Danke für Ihr Feedback!',
        'feedbackError' => 'Feedback konnte nicht gesendet werden.',
    ];

    public static function get(): array
    {
        System::loadLanguageFile('chatbot_widget');

        $strings = [];

        foreach (self::KEYS as $key => $fallback) {
            $strings[$key] = $GLOBALS['TL_LANG']['chatbot_widget'][$key] ?? $fallback;
        }

        return $strings;
    }
}
