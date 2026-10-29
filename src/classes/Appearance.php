<?php

namespace Bluebranch\Chatbot\classes;

use Contao\Config;

/**
 * Gemeinsames Aussehen der Module: Die Akzentfarbe aus den Einstellungen als Inline-Style.
 *
 * Das Chat-Widget kennt je Modul eine eigene Farbe; Frage- und Suchmodul uebernehmen die
 * Vorgabe aus *System -> Einstellungen*, damit alle Teile einer Website zusammenpassen. Ohne
 * Vorgabe bleibt die Standardfarbe aus dem Stylesheet.
 */
class Appearance
{
    /**
     * @return string Inhalt fuer das style-Attribut (leer ohne Vorgabe)
     */
    public static function accentStyle(): string
    {
        $color = trim((string) Config::get('chatbot_default_color'), '# ');

        if (1 !== preg_match('/^([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $color)) {
            return '';
        }

        return sprintf('--chatbot-widget-accent: #%s; --chatbot-widget-accent-contrast: %s;', $color, self::contrastColor($color));
    }

    /**
     * Schwarz oder weiss als Schriftfarbe, je nach Helligkeit der Akzentfarbe (YIQ).
     */
    public static function contrastColor(string $hex): string
    {
        if (3 === \strlen($hex)) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        $brightness = ((hexdec(substr($hex, 0, 2)) * 299) + (hexdec(substr($hex, 2, 2)) * 587) + (hexdec(substr($hex, 4, 2)) * 114)) / 1000;

        return $brightness > 150 ? '#1a1a1a' : '#ffffff';
    }
}
