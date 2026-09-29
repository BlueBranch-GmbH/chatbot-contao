<?php

namespace Bluebranch\Chatbot\classes;

/**
 * URLs der Bundle-Assets mit Versionsparameter.
 *
 * Contao versioniert Bundle-Assets nur mit einer manifest.json. Ohne Parameter behielten
 * Browser nach einem Update die alten Skripte, waehrend die Templates schon neu sind - und
 * passen beide nicht zusammen, antwortet der Chatbot nicht mehr, bis der Cache ablaeuft.
 */
final class Asset
{
    public static function url(string $path): string
    {
        $file = \dirname(__DIR__) . '/Resources/public/' . ltrim($path, '/');
        $version = is_file($file) ? '?v=' . filemtime($file) : '';

        return '/bundles/chatbot/' . ltrim($path, '/') . $version;
    }
}
