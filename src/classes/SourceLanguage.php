<?php

namespace Bluebranch\Chatbot\classes;

use Contao\Database;
use Contao\PageModel;

/**
 * Haelt die Quellen einer Antwort in der Sprache der Seite, auf der gefragt wurde.
 *
 * Alle Sprachen einer Domain teilen einen Index; die API sucht ueber alle Treffer und liefert
 * deutsche und englische Seiten gemischt. Der Besucher einer englischen Seite soll aber keine
 * Links auf die deutsche Fassung bekommen. Die Sprache einer Quelle steht nicht in der Antwort
 * der API, sie ergibt sich aus der `externalId`: `page_<id>` ist eine Contao-Seite, `extra_<id>`
 * ein Eintrag unter „Zusaetzliche Inhalte“.
 *
 * Eine einsprachige Installation filtert nie - dort traegt „Zusaetzliche Inhalte“ ohne Sprache
 * den Wert `de`, der auf einer englischen Seite sonst jede Quelle verwerfen wuerde.
 */
class SourceLanguage
{
    private static ?bool $multilingual = null;

    /** @var array<string, string> */
    private static array $cache = [];

    /**
     * @return \Closure(array): array|null Filter fuer die Quellenliste; null, wenn nichts zu filtern ist
     */
    public static function forPage(int $pageId): ?\Closure
    {
        if ($pageId <= 0 || !self::isMultilingual()) {
            return null;
        }

        $page = PageModel::findById($pageId);

        if (!$page instanceof PageModel) {
            return null;
        }

        $page->loadDetails();
        $wanted = self::primary((string) $page->language);

        if ('' === $wanted) {
            return null;
        }

        return static function (array $sources) use ($wanted): array {
            return array_values(array_filter($sources, static function ($source) use ($wanted): bool {
                if (!\is_array($source)) {
                    return false;
                }

                $language = self::languageOf((string) ($source['externalId'] ?? ''));

                // Unbekannt (andere Herkunft, geloeschte Seite): behalten statt raten.
                return '' === $language || $language === $wanted;
            }));
        };
    }

    /**
     * Ob die Installation Startseiten in mindestens zwei Sprachen veroeffentlicht.
     */
    public static function isMultilingual(): bool
    {
        if (null === self::$multilingual) {
            $codes = [];
            $roots = Database::getInstance()->query("SELECT language FROM tl_page WHERE type='root' AND published='1'");

            while ($roots->next()) {
                $codes[self::primary((string) $roots->language)] = true;
            }

            unset($codes['']);
            self::$multilingual = \count($codes) > 1;
        }

        return self::$multilingual;
    }

    private static function languageOf(string $externalId): string
    {
        if (isset(self::$cache[$externalId])) {
            return self::$cache[$externalId];
        }

        $language = '';

        if (1 === preg_match('/^page_(\d+)$/', $externalId, $m)) {
            $page = PageModel::findById((int) $m[1]);

            if ($page instanceof PageModel) {
                $page->loadDetails();
                $language = self::primary((string) $page->language);
            }
        } elseif (1 === preg_match('/^' . preg_quote(ExtraContent::PREFIX, '/') . '(\d+)$/', $externalId, $m)) {
            $row = Database::getInstance()->prepare('SELECT language FROM tl_chatbot_content WHERE id=?')->execute((int) $m[1]);
            $language = $row->numRows ? self::primary((string) $row->language) : '';
        }

        return self::$cache[$externalId] = $language;
    }

    /**
     * `en-US`, `en_GB` und `EN` sind alle Englisch.
     */
    private static function primary(string $language): string
    {
        return substr(strtolower($language), 0, 2);
    }
}
