<?php

namespace Bluebranch\Chatbot\classes;

use Contao\System;

/**
 * Bindet Seite und Modul einer Anfrage an das, was der Server gerendert hat.
 *
 * Der Browser schickt `pageId` und `moduleId` mit. Die Seite waehlt ueber ihre Startseite den
 * API-Key (und damit Wissensbasis und Kontingent), das Modul entscheidet ueber Feedback und
 * Protokoll. Ohne Signatur koennte ein Besucher von Website A mit einer Seiten-ID von Website B
 * fragen - auch einer unveroeffentlichten Staging-Startseite.
 *
 * Die Signatur steht im Seiten-HTML und braucht keine Session; die Seite bleibt cachebar.
 */
class RequestSignature
{
    public static function sign(int $pageId, int $moduleId): string
    {
        return hash_hmac('sha256', 'bluebranch_chatbot|' . $pageId . '|' . $moduleId, self::secret());
    }

    public static function verify(int $pageId, int $moduleId, $signature): bool
    {
        return \is_string($signature) && '' !== $signature && hash_equals(self::sign($pageId, $moduleId), $signature);
    }

    private static function secret(): string
    {
        $container = System::getContainer();

        return $container->hasParameter('kernel.secret') ? (string) $container->getParameter('kernel.secret') : '';
    }
}
