<?php

namespace Bluebranch\Chatbot\classes;

use Contao\BackendUser;

/**
 * Zugriff auf die Backend-Seiten des Chatbots.
 *
 * Die Seiten stehen in den Benutzer- und Gruppenrechten unter "Backend-Module" zur Auswahl. Wer
 * eine Seite dort freigegeben bekommt (oder Administrator ist), darf sie und die dazugehoerigen
 * Hintergrund-Aufrufe nutzen.
 */
class BackendAccess
{
    /**
     * @param string ...$modules Kurznamen der Backend-Module (z. B. `chatbot_start`); ein Treffer genuegt
     */
    public static function module(string ...$modules): bool
    {
        $user = BackendUser::getInstance();

        if (!$user instanceof BackendUser) {
            return false;
        }

        if ($user->isAdmin) {
            return true;
        }

        foreach ($modules as $module) {
            if ($user->hasAccess($module, 'modules')) {
                return true;
            }
        }

        return false;
    }
}
