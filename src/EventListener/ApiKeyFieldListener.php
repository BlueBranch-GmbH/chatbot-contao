<?php

namespace Bluebranch\Chatbot\EventListener;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;

/**
 * Haelt den API-Schluessel aus dem Backend-Formular heraus.
 *
 * `hideInput` allein zeigt nur Punkte statt Zeichen — den Wert selbst gibt Contao weiterhin im
 * `value`-Attribut aus. Er steht damit im Quelltext jeder Backend-Seite, im Verlauf des Browsers
 * und in jedem Zwischenspeicher, der den Seiteninhalt mitschreibt.
 *
 * Hier wird er beim Laden durch einen Platzhalter ersetzt und beim Speichern wieder
 * zurueckgetauscht. Im Formular steht der echte Schluessel damit an keiner Stelle mehr.
 *
 * **Die Gefahr dabei ist, den Schluessel eines Kunden zu loeschen** — dann antwortet dessen
 * Chatbot nicht mehr, und niemand weiss warum. Deshalb liegt die Entscheidung in
 * `entscheideWert()`: eine reine Funktion ohne Contao, die sich einzeln pruefen laesst.
 */
class ApiKeyFieldListener
{
    /**
     * Was im Formular steht, wenn ein Schluessel hinterlegt ist.
     *
     * Kein echter Schluessel kann so aussehen: Schluessel bestehen aus Buchstaben, Ziffern,
     * Unterstrich und Punkt.
     */
    public const PLATZHALTER = '••••••••••••••••';

    #[AsCallback(table: 'tl_page', target: 'fields.chatbot_api_key.load')]
    public function onLoad(mixed $wert, DataContainer $dc): string
    {
        return $this->maskiere(\is_string($wert) ? $wert : '');
    }

    #[AsCallback(table: 'tl_page', target: 'fields.chatbot_api_key.save')]
    public function onSave(mixed $wert, DataContainer $dc): string
    {
        $bisher = '';

        if ($dc->activeRecord && isset($dc->activeRecord->chatbot_api_key)) {
            $bisher = (string) $dc->activeRecord->chatbot_api_key;
        }

        return self::entscheideWert(\is_string($wert) ? $wert : '', $bisher);
    }

    /** Zeigt den Platzhalter, sobald ein Schluessel hinterlegt ist. */
    public function maskiere(string $wert): string
    {
        return '' === trim($wert) ? '' : self::PLATZHALTER;
    }

    /**
     * Entscheidet, was gespeichert wird.
     *
     * | Eingabe | Ergebnis |
     * |---|---|
     * | Platzhalter unveraendert | der bisherige Schluessel bleibt |
     * | leer | der Schluessel wird geloescht — eine bewusste Handlung |
     * | etwas anderes | wird als neuer Schluessel uebernommen |
     *
     * Der Platzhalter wird nicht nur zeichengenau erkannt: Traefe ihn eine Umwandlung von
     * Sonderzeichen oder ein anderer Browser anders, entstuende sonst ein Schluessel aus
     * Aufzaehlungspunkten — und der Chatbot des Kunden schwiege ab dem naechsten Speichern.
     * Deshalb gilt jede Eingabe als Platzhalter, die ausschliesslich aus Maskierungszeichen
     * besteht.
     */
    public static function entscheideWert(string $eingabe, string $bisher): string
    {
        $eingabe = trim($eingabe);

        if ('' === $eingabe) {
            return '';
        }

        if (self::istMaskierung($eingabe)) {
            return $bisher;
        }

        return $eingabe;
    }

    /** Ob eine Eingabe nur aus Maskierungszeichen besteht — Punkt, Sternchen, Bullet. */
    public static function istMaskierung(string $eingabe): bool
    {
        return 1 === preg_match('/^[\x{2022}\x{00B7}\x{2219}*.]+$/u', $eingabe);
    }
}
