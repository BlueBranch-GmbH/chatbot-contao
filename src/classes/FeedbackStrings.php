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
        // Statuszeile und Fehlertext von Frage- und Suchmodul
        'searchLoading' => 'Antwort wird generiert...',
        'searchDone' => 'Antwort generiert',
        'searchStopped' => 'Antwort abgebrochen',
        'searchSeconds' => 'sekunden',
        'requestError' => 'Es ist ein Fehler bei der Anfrage aufgetreten.',
        // Spracheingabe (Mikrofon-Knopf)
        'voice' => 'Spracheingabe starten',
        'voiceStop' => 'Aufnahme beenden und senden',
        'voiceCancel' => 'Aufnahme verwerfen',
        'voiceWorking' => 'Wird verstanden …',
        'voiceEmpty' => 'Es wurde nichts verstanden. Bitte noch einmal sprechen.',
        'voiceDenied' => 'Kein Zugriff auf das Mikrofon. Bitte in der Adresszeile des Browsers erlauben.',
        'voiceError' => 'Die Spracheingabe hat nicht funktioniert.',
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
