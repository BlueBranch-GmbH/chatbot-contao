<?php

namespace Bluebranch\Chatbot\classes;

/**
 * Prueft angepasste Templates im Projekt (`templates/mod_chatbot_*.html5`) auf Stellen, die neuere
 * Fassungen der Erweiterung brauchen.
 *
 * Ein Projekt-Template ersetzt das der Erweiterung. Es bleibt bei Updates unveraendert - und kann deshalb
 * hinter dem Code zurueckfallen. Die Pruefung liest nur, sie veraendert nichts.
 *
 * Stufen:
 *  - `error`: Der Chat funktioniert damit nicht (ohne Signatur von Seite und Modul, seit 1.3.0 Pflicht)
 *  - `info`:  Der Chat funktioniert, es fehlen neuere Funktionen (Mikrofon, neues Design)
 */
class TemplateCheck
{
    /** Template-Name => [Marker im Quelltext => Hinweis, wenn er fehlt] */
    private const CHECKS = [
        'mod_chatbot_widget' => [
            'error' => ['$this->sig' => 'Signatur von Seite und Modul fehlt (seit 1.3.0 Pflicht): Der Chat antwortet mit „Bitte laden Sie die Seite neu.“'],
            'info' => ['$this->voice' => 'ohne Mikrofon-Knopf (Spracheingabe)', 'indexer::stop' => 'Widget-Text kann im Contao-Suchindex landen'],
        ],
        'mod_chatbot_ask' => [
            'error' => ['$this->sig' => 'Signatur von Seite und Modul fehlt (seit 1.3.0 Pflicht): Der Chat antwortet mit „Bitte laden Sie die Seite neu.“'],
            'info' => ['$this->voice' => 'ohne Mikrofon-Knopf (Spracheingabe)', '$this->accentStyle' => 'ohne neues Standard-Design und Akzentfarbe'],
        ],
        'mod_chatbot_generate_search' => [
            'error' => ['$this->sig' => 'Signatur von Seite und Modul fehlt (seit 1.3.0 Pflicht): Der Chat antwortet mit „Bitte laden Sie die Seite neu.“'],
            'info' => ['$this->accentStyle' => 'ohne neues Standard-Design und Akzentfarbe'],
        ],
        'mod_chatbot_generate_search_full' => [
            'error' => ['$this->sig' => 'Signatur von Seite und Modul fehlt (seit 1.3.0 Pflicht): Der Chat antwortet mit „Bitte laden Sie die Seite neu.“'],
            'info' => ['$this->voice' => 'ohne Mikrofon-Knopf (Spracheingabe)', '$this->accentStyle' => 'ohne neues Standard-Design und Akzentfarbe'],
        ],
    ];

    /**
     * @return list<array{file: string, level: string, messages: list<string>}>
     */
    public static function scan(string $projectDir): array
    {
        $root = rtrim($projectDir, '/') . '/templates';

        if (!is_dir($root)) {
            return [];
        }

        $found = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::LEAVES_ONLY);
        $iterator->setMaxDepth(3);

        foreach ($iterator as $file) {
            if (!$file->isFile() || 'html5' !== $file->getExtension()) {
                continue;
            }

            $name = $file->getBasename('.html5');

            if (!isset(self::CHECKS[$name])) {
                continue;
            }

            $source = (string) @file_get_contents($file->getPathname());
            $errors = [];
            $infos = [];

            foreach (self::CHECKS[$name]['error'] as $marker => $message) {
                if (!str_contains($source, $marker)) {
                    $errors[] = $message;
                }
            }

            foreach (self::CHECKS[$name]['info'] as $marker => $message) {
                if (!str_contains($source, $marker)) {
                    $infos[] = $message;
                }
            }

            if ([] === $errors && [] === $infos) {
                continue;
            }

            $found[] = [
                'file' => ltrim(substr($file->getPathname(), \strlen(rtrim($projectDir, '/'))), '/'),
                'level' => [] !== $errors ? 'error' : 'info',
                'messages' => array_merge($errors, $infos),
            ];
        }

        usort($found, static fn (array $a, array $b): int => [$a['level'], $a['file']] <=> [$b['level'], $b['file']]);

        return $found;
    }
}
