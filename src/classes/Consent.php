<?php

namespace Bluebranch\Chatbot\classes;

use Contao\System;

/**
 * Hinweis „Sie sprechen mit einer KI“, den Besucher bestaetigen muessen, bevor sie fragen koennen.
 *
 * Je Modul abschaltbar (Feld `chatbot_consent`, Vorgabe: aus). Hier steht nur, ob und mit welchen
 * Texten - das Einblenden und Merken der Bestaetigung uebernimmt ChatbotConsent in chatbot-common.js.
 */
class Consent
{
    /**
     * @param object $module ModuleModel oder Datensatz mit `chatbot_consent`
     *
     * @return array{text: string, button: string}|false false = keine Bestaetigung noetig
     */
    public static function forModule(object $module): array|false
    {
        if (empty($module->chatbot_consent)) {
            return false;
        }

        System::loadLanguageFile('chatbot_widget');
        $lang = $GLOBALS['TL_LANG']['chatbot_widget'] ?? [];

        return [
            'text' => (string) ($lang['consentText'] ?? 'Dieser Chat wird von einer künstlichen Intelligenz (KI) betrieben. Die Antworten werden automatisch erzeugt und können Fehler enthalten.'),
            'button' => (string) ($lang['consentButton'] ?? 'Verstanden'),
        ];
    }
}
