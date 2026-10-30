<?php

namespace Bluebranch\Chatbot\classes;

use Contao\Config;
use Contao\ModuleModel;
use Contao\ThemeModel;

/**
 * „Chat auf allen Seiten anzeigen“: Ein Widget-Modul wird ohne Eintrag im Layout in jede Seite eingesetzt.
 *
 * Die Einstellung `chatbot_global_widget` (System -> Einstellungen) nennt das Modul. Das Einsetzen
 * erledigt GlobalWidgetListener; hier steht, welches Modul gemeint ist und wie es entsteht.
 */
class GlobalWidget
{
    /**
     * @return int ID des gewaehlten Widget-Moduls; 0 = aus (oder das Modul gibt es nicht mehr)
     */
    public static function moduleId(): int
    {
        $id = (int) Config::get('chatbot_global_widget');

        if ($id <= 0) {
            return 0;
        }

        $module = ModuleModel::findByPk($id);

        return $module instanceof ModuleModel && 'chatbot_widget' === $module->type ? $id : 0;
    }

    /**
     * Die Auswahl fuer das Feld in den Einstellungen.
     *
     * @return array<int|string, string>
     */
    public function options(): array
    {
        $options = [];
        $modules = ModuleModel::findBy('type', 'chatbot_widget');

        if (null !== $modules) {
            foreach ($modules as $module) {
                $options[$module->id] = $module->name . ' (ID ' . $module->id . ')';
            }
        }

        return $options;
    }

    /**
     * Schaltet „auf allen Seiten“ ein. Gibt es noch kein Widget-Modul, entsteht eines - samt Theme,
     * falls die Installation noch keines hat.
     *
     * @return int ID des verwendeten Moduls
     */
    public static function enable(): int
    {
        $id = self::moduleId();

        if ($id <= 0) {
            $existing = ModuleModel::findOneBy('type', 'chatbot_widget');
            $id = $existing instanceof ModuleModel ? (int) $existing->id : self::createModule();
        }

        self::store((string) $id);

        return $id;
    }

    public static function disable(): void
    {
        self::store('');
    }

    /**
     * Schreibt die Einstellung sofort in die localconfig.php.
     *
     * Config::persist() merkt sich die Aenderung nur und schreibt erst beim Beenden der Anfrage - nach
     * der Weiterleitung. Die naechste Seite las dann noch den alten Wert.
     */
    public static function store(string $value, string $key = 'chatbot_global_widget'): void
    {
        Config::persist($key, $value);
        Config::getInstance()->save();
    }

    private static function createModule(): int
    {
        $theme = ThemeModel::findAll(['limit' => 1, 'order' => 'id'])?->current();

        if (!$theme instanceof ThemeModel) {
            $theme = new ThemeModel();
            $theme->tstamp = time();
            $theme->name = 'Chatbot';
            $theme->save();
        }

        $module = new ModuleModel();
        $module->pid = (int) $theme->id;
        $module->tstamp = time();
        $module->name = 'Chatbot Widget';
        $module->type = 'chatbot_widget';
        $module->save();

        return (int) $module->id;
    }
}
