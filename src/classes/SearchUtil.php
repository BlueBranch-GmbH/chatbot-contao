<?php

namespace Bluebranch\Chatbot\classes;

use Contao\StringUtil;
use League\HTMLToMarkdown\HtmlConverter;

class SearchUtil
{
    /**
     * Konvertiert HTML zu Markdown.
     */
    public function convertToMarkdown(string $html, bool $ignoreIndexerMarkers = false): string
    {
        // Script und Style Tags vorher entfernen
        $html = $this->stripScriptAndStyle($html);
        
        // Bilder entfernen
        $html = $this->stripImages($html);
        
        // Indexer stop/continue beachten (optional ignorieren)
        if (!$ignoreIndexerMarkers) {
            $html = $this->filterIndexableSections($html);
        }

        $converter = new HtmlConverter([
            'strip_tags' => true,
            'hard_break' => true,
        ]);

        return $converter->convert($html);
    }

    /**
     * Extrahiert und bereinigt den Text einer Seite ähnlich wie Contao\Search::indexPage.
     */
    public function prepareIndexText(string $content, array $pageData, bool $ignoreIndexerMarkers = false): string
    {
        $strContent = $this->stripScriptAndStyle($content);
        $strContent = $this->stripImages($strContent);
        
        if (!$ignoreIndexerMarkers) {
            $strContent = $this->filterIndexableSections($strContent);
        }

        $arrMatches = [];
        if (preg_match('/<\/head>/', $strContent, $arrMatches, PREG_OFFSET_CAPTURE)) {
            $intOffset = strlen($arrMatches[0][0]) + $arrMatches[0][1];
            $strHead = substr($strContent, 0, $intOffset);
            $strBody = substr($strContent, $intOffset);
        } else {
            $strHead = '';
            $strBody = $strContent;
        }

        $description = '';
        $tags = [];
        if (preg_match('/<meta[^>]+name="description"[^>]+content="([^"]*)"[^>]*>/i', $strHead, $tags)) {
            $description = trim(preg_replace('/ +/', ' ', StringUtil::decodeEntities($tags[1])));
        }

        $keywordsMeta = '';
        if (preg_match('/<meta[^>]+name="keywords"[^>]+content="([^"]*)"[^>]*>/i', $strHead, $tags)) {
            $keywordsMeta = trim(preg_replace('/ +/', ' ', StringUtil::decodeEntities($tags[1])));
        }

        if (preg_match_all('/<* (title|alt)="([^"]*)"[^>]*>/i', $strBody, $tags)) {
            $keywordsMeta .= ' ' . implode(', ', array_unique($tags[2]));
        }

        $strBody = str_ireplace(['<br', '><'], [' <br', '> <'], $strBody);
        $strBody = strip_tags($strBody);

        $title = ($pageData['pageTitle'] ?? '') ?: ($pageData['title'] ?? '');

        $text = $strBody . ' ' . $description . "\n" . $title . "\n" . $keywordsMeta;
        return trim(preg_replace('/ +/', ' ', StringUtil::decodeEntities($text)));
    }

    /**
     * Zerlegt Text in Wörter und berechnet die Relevanz (Häufigkeit).
     */
    public function getKeywordsWithRelevance(string $text, string $language, int $limit = 15): array
    {
        $words = $this->splitIntoWords($text, $language);
        $index = [];

        foreach ($words as $word) {
            // Ignoriere zu kurze Wörter (optional, Contao macht das im splitIntoWords nicht explizit, aber oft sinnvoll)
            if (mb_strlen($word) < 3) {
                continue;
            }

            if (isset($index[$word])) {
                $index[$word]++;
            } else {
                $index[$word] = 1;
            }
        }

        arsort($index);
        return array_slice($index, 0, $limit, true);
    }

    /**
     * Nutzt Contao's Wort-Splitter.
     */
    protected function splitIntoWords(string $text, string $language): array
    {
        // Wir versuchen die statische Methode von Contao\Search zu nutzen, falls verfügbar
        if (class_exists('Contao\Search') && method_exists('Contao\Search', 'splitIntoWords')) {
            return \Contao\Search::splitIntoWords($text, $language);
        }

        // Fallback falls Contao\Search nicht geladen werden kann (eher unwahrscheinlich im Contao Context)
        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text);
        return array_filter(explode(' ', $text));
    }

    /**
     * Entfernt Script- und Style-Tags.
     */
    protected function stripScriptAndStyle(string $strContent): string
    {
        // Script-Tags entfernen
        while (($intStart = strpos($strContent, '<script')) !== false) {
            if (($intEnd = strpos($strContent, '</script>', $intStart)) !== false) {
                $strContent = substr($strContent, 0, $intStart) . substr($strContent, $intEnd + 9);
            } else {
                break;
            }
        }

        // Style-Tags entfernen
        while (($intStart = strpos($strContent, '<style')) !== false) {
            if (($intEnd = strpos($strContent, '</style>', $intStart)) !== false) {
                $strContent = substr($strContent, 0, $intStart) . substr($strContent, $intEnd + 8);
            } else {
                break;
            }
        }

        return $strContent;
    }

    /**
     * Entfernt Bilder (img und picture Tags).
     */
    protected function stripImages(string $strContent): string
    {
        // img Tags entfernen
        $strContent = preg_replace('/<img[^>]*>/i', '', $strContent);

        // picture Tags inkl. Inhalt entfernen
        while (($intStart = strpos($strContent, '<picture')) !== false) {
            if (($intEnd = strpos($strContent, '</picture>', $intStart)) !== false) {
                $strContent = substr($strContent, 0, $intStart) . substr($strContent, $intEnd + 10);
            } else {
                break;
            }
        }

        return $strContent;
    }

    /**
     * Entfernt nicht-indizierbare Bereiche (indexer::stop/continue).
     */
    protected function filterIndexableSections(string $strContent): string
    {
        while (($intStart = strpos($strContent, '<!-- indexer::stop -->')) !== false) {
            if (($intEnd = strpos($strContent, '<!-- indexer::continue -->', $intStart)) !== false) {
                $intCurrent = $intStart;
                while (($intNested = strpos($strContent, '<!-- indexer::stop -->', $intCurrent + 22)) !== false && $intNested < $intEnd) {
                    if (($intNewEnd = strpos($strContent, '<!-- indexer::continue -->', $intEnd + 26)) !== false) {
                        $intEnd = $intNewEnd;
                        $intCurrent = $intNested;
                    } else {
                        break;
                    }
                }
                $strContent = substr($strContent, 0, $intStart) . substr($strContent, $intEnd + 26);
            } else {
                break;
            }
        }

        return $strContent;
    }
}
