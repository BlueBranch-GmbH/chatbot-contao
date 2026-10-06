<?php

namespace Bluebranch\Chatbot\classes;

/**
 * Macht aus einer Datei reinen Text - lokal, bevor irgendetwas an die API geht.
 *
 * Die API bekommt nie eine Datei, nur den Text daraus. Damit bleibt sie frei von Parsern fuer
 * fremde Formate, und was das Haus verlaesst, ist genau das, was im Backend als Zeichenzahl steht.
 */
class TextExtractor
{
    public const EXTENSIONS = ['txt', 'md', 'csv', 'pdf', 'docx', 'odt', 'html', 'htm'];

    /** Groesster Text je Eintrag. Mehr bremst das Einbetten und verwaessert die Treffer. */
    public const MAX_CHARS = 200000;

    private const MAX_FILE_BYTES = 20 * 1024 * 1024;

    /** Grenze fuer entpackte XML-Teile von DOCX/ODT - Schutz vor Zip-Bomben. */
    private const MAX_UNPACKED_BYTES = 50 * 1024 * 1024;

    /**
     * @throws \RuntimeException mit einer Meldung fuer das Backend
     */
    public function extract(string $path, ?string $extension = null): string
    {
        $extension = strtolower($extension ?? pathinfo($path, PATHINFO_EXTENSION));

        if (!\in_array($extension, self::EXTENSIONS, true)) {
            throw new \RuntimeException(sprintf('Dateiformat .%s wird nicht unterstützt.', $extension));
        }

        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException('Datei nicht gefunden oder nicht lesbar.');
        }

        if (filesize($path) > self::MAX_FILE_BYTES) {
            throw new \RuntimeException('Datei ist größer als 20 MB.');
        }

        switch ($extension) {
            case 'pdf':
                $text = $this->fromPdf($path);
                break;

            case 'docx':
                $text = $this->fromZipXml($path, 'word/document.xml', [
                    '~</w:p>~' => "\n",
                    '~<w:tab\s*/>~' => "\t",
                    '~<w:(?:br|cr)\b[^>]*/>~' => "\n",
                ]);
                break;

            case 'odt':
                $text = $this->fromZipXml($path, 'content.xml', [
                    '~</text:(?:p|h)>~' => "\n",
                    '~<text:tab\s*/>~' => "\t",
                    '~<text:line-break\s*/>~' => "\n",
                    '~<text:s\b[^>]*/>~' => ' ',
                ]);
                break;

            case 'html':
            case 'htm':
                $text = $this->fromHtml($this->toUtf8((string) file_get_contents($path)));
                break;

            default:
                $text = $this->toUtf8((string) file_get_contents($path));
        }

        return $this->normalize($text);
    }

    /**
     * Kuerzt auf MAX_CHARS. Gibt zurueck, ob gekuerzt wurde.
     */
    public static function limit(string &$text): bool
    {
        if (mb_strlen($text) <= self::MAX_CHARS) {
            return false;
        }

        $text = mb_substr($text, 0, self::MAX_CHARS);

        return true;
    }

    public function normalize(string $text): string
    {
        $text = str_replace(["\r\n", "\r", "\u{00A0}"], ["\n", "\n", ' '], $text);
        // Steuerzeichen ausser Tab und Zeilenumbruch.
        $text = preg_replace('/[^\P{C}\t\n]+/u', '', $text) ?? $text;
        $text = preg_replace('/[ \t]+\n/', "\n", $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }

    private function fromPdf(string $path): string
    {
        if (!class_exists(\Smalot\PdfParser\Parser::class)) {
            throw new \RuntimeException('Für PDF-Dateien fehlt die Bibliothek smalot/pdfparser (composer update).');
        }

        try {
            // Begrenzt den Speicher beim Entpacken der Datenstroeme - ein praepariertes PDF soll mit
            // einer Meldung scheitern, nicht den PHP-Prozess (oft den Web-Cron) mitreissen.
            $config = new \Smalot\PdfParser\Config();
            $config->setDecodeMemoryLimit(64 * 1024 * 1024);
            $config->setRetainImageContent(false);
            $document = (new \Smalot\PdfParser\Parser([], $config))->parseFile($path);
            $text = $document->getText();
        } catch (\Throwable $e) {
            throw new \RuntimeException('PDF konnte nicht gelesen werden: ' . $e->getMessage());
        }

        if ('' === trim($text)) {
            throw new \RuntimeException('Das PDF enthält keinen Text (gescanntes Dokument?).');
        }

        return $this->toUtf8($text);
    }

    /**
     * Liest den Text aus einem Office-Dokument (ZIP mit XML).
     *
     * Bewusst ohne XML-Parser: Ersetzungen und strip_tags reichen fuer reinen Text und schliessen
     * externe Entitaeten (XXE) von vornherein aus.
     */
    private function fromZipXml(string $path, string $entry, array $replacements): string
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('Die PHP-Erweiterung zip fehlt.');
        }

        $zip = new \ZipArchive();

        if (true !== $zip->open($path)) {
            throw new \RuntimeException('Dokument konnte nicht geöffnet werden.');
        }

        $stat = $zip->statName($entry);

        if (false === $stat) {
            $zip->close();
            throw new \RuntimeException('Dokument enthält keinen Text.');
        }

        if ($stat['size'] > self::MAX_UNPACKED_BYTES) {
            $zip->close();
            throw new \RuntimeException('Dokument ist entpackt zu groß.');
        }

        $xml = (string) $zip->getFromName($entry);
        $zip->close();

        $xml = preg_replace(array_keys($replacements), array_values($replacements), $xml) ?? $xml;

        return html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function fromHtml(string $html): string
    {
        $html = preg_replace('~<(script|style|noscript|template)\b[^>]*>.*?</\1>~is', ' ', $html) ?? $html;
        $html = preg_replace('~<(br|/p|/div|/li|/h[1-6]|/tr|/section|/article)\b[^>]*>~i', "\n", $html) ?? $html;

        return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function toUtf8(string $text): string
    {
        if (0 === strncmp($text, "\xEF\xBB\xBF", 3)) {
            $text = substr($text, 3);
        }

        if (mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }

        return (string) mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
    }
}
