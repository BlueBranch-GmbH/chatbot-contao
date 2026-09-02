<?php

namespace Bluebranch\Chatbot\Module;

use Bluebranch\Chatbot\classes\ChatbotAPI;
use Bluebranch\Chatbot\classes\StreamToken;
use Bluebranch\Chatbot\classes\TypedQuestions;
use Contao\CoreBundle\Controller\FrontendModule\AbstractFrontendModuleController;
use Contao\CoreBundle\DependencyInjection\Attribute\AsFrontendModule;
use Contao\ModuleModel;
use Contao\PageModel;
use Contao\System;
use Contao\Template;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ein Eingabefeld, das die hinterlegten Fragen der Reihe nach als Platzhalter tippt
 * und die eingegebene Frage ohne Seitenwechsel beantworten laesst.
 *
 * Gegenstueck zu ChatbotGenerateSearchController: dort haengt die Antwort am
 * Query-Parameter der Contao-Suche und steht ueber deren Trefferliste, hier gibt es
 * keine Trefferliste und keinen Seitenaufruf -- gefragt wird direkt im Feld.
 */
#[AsFrontendModule(category: 'bluebranch_chatbot', template: 'mod_chatbot_ask')]
class ChatbotAskController extends AbstractFrontendModuleController
{
    private ChatbotAPI $chatbotApi;

    public function __construct(?ChatbotAPI $chatbotApi = null)
    {
        $this->chatbotApi = $chatbotApi ?? System::getContainer()->get(ChatbotAPI::class);
    }

    public function generate()
    {
        $request = System::getContainer()->get('request_stack')->getCurrentRequest() ?? new Request();
        $model = new ModuleModel();

        // Try to get the model from the current instance if it was set by legacy Contao
        if (isset($this->objModel) && $this->objModel instanceof ModuleModel) {
            $model = $this->objModel;
        }

        return $this->__invoke($request, $model, 'main')->getContent();
    }

    protected function getResponse(Template $template, ModuleModel $model, Request $request): Response
    {
        System::loadLanguageFile('chatbot_ask');
        $lang = &$GLOBALS['TL_LANG']['chatbot_ask'];

        $pageModel = $this->getPageModel();

        $template->hasApiKey = !empty($this->chatbotApi->getApiKey($pageModel));
        $template->typedQuestions = TypedQuestions::fromModel($model);

        $template->language = $this->resolveLanguageName($pageModel);
        $template->pageId = $pageModel instanceof PageModel ? $pageModel->id : '';

        $template->labelQuestion = $lang['question'] ?? 'Ihre Frage';
        $template->labelSubmit = $lang['submit'] ?? 'Fragen';
        $template->labelPlaceholder = $lang['placeholder'] ?? 'Stellen Sie Ihre Frage …';
        $template->labelSources = $lang['sources'] ?? 'Quellen:';

        $template->requestToken = StreamToken::forSession($request);

        return $template->getResponse();
    }

    /**
     * Die API erwartet den ausgeschriebenen Sprachnamen, nicht den Sprachcode --
     * gleiche Aufbereitung wie im Such-Modul.
     */
    private function resolveLanguageName(?PageModel $pageModel): string
    {
        $languageCode = $pageModel instanceof PageModel ? $pageModel->language : 'de';
        $locales = System::getContainer()->get('contao.intl.locales');

        if (!$locales) {
            return strtolower($languageCode);
        }

        $languages = $locales->getLanguages($languageCode);

        return strtolower($languages[$languageCode] ?? $languageCode);
    }
}
