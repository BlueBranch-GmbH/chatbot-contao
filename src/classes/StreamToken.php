<?php

namespace Bluebranch\Chatbot\classes;

use Symfony\Component\HttpFoundation\Request;

/**
 * Der Sitzungs-Token, mit dem sich die Antwort-Routen gegen fremde Aufrufe schuetzen.
 *
 * Jedes Modul, das eine Antwort anfordert, legt ihn beim Rendern in die Session und
 * gibt ihn ins Template; ChatbotAPIController prueft ihn gegen genau diesen Wert.
 */
class StreamToken
{
    private const SESSION_KEY = '_chatbot_stream_token';

    /**
     * Gibt den Token der laufenden Sitzung zurueck und legt ihn an, falls noch keiner
     * existiert. Bewusst je Sitzung und nicht je Modul: mehrere Module auf einer Seite
     * teilen sich denselben Token.
     */
    public static function forSession(Request $request): string
    {
        $session = $request->getSession();
        $token = $session->get(self::SESSION_KEY);

        if (!$token) {
            $token = bin2hex(random_bytes(32));
            $session->set(self::SESSION_KEY, $token);
        }

        return $token;
    }
}
