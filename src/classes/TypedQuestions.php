<?php

namespace Bluebranch\Chatbot\classes;

use Contao\ModuleModel;
use Contao\StringUtil;

/**
 * Die Fragen, die im Frontend als Platzhalter ins Eingabefeld getippt werden.
 *
 * Sie stehen an zwei Modulen (chatbot_ask und chatbot_generate_search) im selben
 * Feld, deshalb liegt das Auslesen hier und nicht in den Controllern.
 */
class TypedQuestions
{
    /**
     * Liefert die gepflegten Fragen ohne Leereintraege. Ein leeres Feld ergibt ein
     * leeres Array -- die Templates zeigen dann den statischen Platzhalter.
     *
     * @return list<string>
     */
    public static function fromModel(ModuleModel $model): array
    {
        $questions = StringUtil::deserialize($model->chatbot_typed_questions, true);

        return array_values(array_filter(array_map('trim', $questions), static fn (string $q): bool => $q !== ''));
    }
}
