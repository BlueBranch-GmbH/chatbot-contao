<?php

namespace Bluebranch\Chatbot\Module;

use Bluebranch\Chatbot\classes\Appearance;
use Bluebranch\Chatbot\classes\ChatbotAPI;
use Bluebranch\Chatbot\classes\ChatLog;
use Bluebranch\Chatbot\classes\RequestSignature;
use Bluebranch\Chatbot\classes\FeedbackStrings;
use Bluebranch\Chatbot\classes\TypedQuestions;
use Bluebranch\Chatbot\classes\VoiceInput;
use Contao\Config;
use Contao\CoreBundle\Controller\FrontendModule\AbstractFrontendModuleController;
use Contao\CoreBundle\DependencyInjection\Attribute\AsFrontendModule;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\Database;
use Contao\ModuleModel;
use Contao\PageModel;
use Contao\Search;
use Contao\StringUtil;
use Contao\System;
use Contao\Template;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

#[AsFrontendModule(category: 'bluebranch_chatbot', template: 'mod_chatbot_generate_search')]
class ChatbotGenerateSearchController extends AbstractFrontendModuleController
{
    private ChatbotAPI $chatbotApi;

    private ?VoiceInput $voiceInput;

    public function __construct(?ChatbotAPI $chatbotApi = null, ?VoiceInput $voiceInput = null)
    {
        $container = \Contao\System::getContainer();
        $this->chatbotApi = $chatbotApi ?? $container->get(ChatbotAPI::class);
        $this->voiceInput = $voiceInput ?? ($container->has(VoiceInput::class) ? $container->get(VoiceInput::class) : null);
    }

    public function generate()
    {
        $request = \Contao\System::getContainer()->get('request_stack')->getCurrentRequest() ?? new Request();
        $model = new ModuleModel();
        
        // Try to get the model from the current instance if it was set by legacy Contao
        if (isset($this->objModel) && $this->objModel instanceof ModuleModel) {
            $model = $this->objModel;
        }

        return $this->__invoke($request, $model, 'main')->getContent();
    }

    protected function getResponse(Template $template, ModuleModel $model, Request $request): Response
    {
        $queryParam = $model->chatbot_query_param ?: 'keywords';
        $query = $request->query->get($queryParam, '');

        $template->query = $query;
        $template->queryParam = $queryParam;
        $template->typedQuestions = TypedQuestions::fromModel($model);

        // Get the current page language
        $pageModel = $this->getPageModel();
        $languageCode = 'de';

        if ($pageModel instanceof PageModel) {
            $languageCode = $pageModel->language;
        }

        // Convert language code to full name
        $languageName = $languageCode;
        $locales = \Contao\System::getContainer()->get('contao.intl.locales');
        
        if ($locales) {
            $languages = $locales->getLanguages($languageCode);
            $languageName = $languages[$languageCode] ?? $languageCode;
        }

        $template->language = strtolower($languageName);

        // Pass the current page ID to the template
        if ($pageModel instanceof PageModel) {
            $template->pageId = $pageModel->id;
        }

        // Check if API key is present
        $template->hasApiKey = !empty($this->chatbotApi->getApiKey($pageModel));
        // Standard-Design der Erweiterung; abschaltbar je Modul (wie beim Chat-Widget).
        $template->unstyled = (bool) $model->chatbot_widget_unstyled;
        $template->accentStyle = Appearance::accentStyle();
        // Den Hinweis auf einen fehlenden API-Schluessel sehen nur eingeloggte Redakteure;
        // Besuchern bleibt das Modul dann einfach leer.
        $template->showConfigHint = System::getContainer()->get('contao.security.token_checker')->hasBackendUser();

        // Native Contao search results (used by the "full" template variant, which
        // shows the search field, the chat answer and the regular search results)
        $nativeResults = $query !== '' ? $this->performNativeSearch($query, $pageModel) : ['count' => 0, 'results' => []];
        $template->searchResultsCount = $nativeResults['count'];
        $template->searchResults = $nativeResults['results'];
        $template->moduleId = (int) $model->id;
        $template->feedback = ChatLog::feedbackEnabled($model);
        // Bindet Seite und Modul an diese Ausgabe (siehe RequestSignature).
        $template->sig = RequestSignature::sign((int) ($this->getPageModel() instanceof PageModel ? $this->getPageModel()->id : 0), (int) $model->id);
        $template->feedbackStrings = FeedbackStrings::get();

        System::loadLanguageFile('chatbot_ask');
        $template->labelSources = $GLOBALS['TL_LANG']['chatbot_ask']['sources'] ?? 'Quellen:';

        // Mikrofon (nur die Variante mit eigenem Suchfeld, „_full“): vom Modul erlaubt und von der API eingerichtet.
        System::loadLanguageFile('chatbot_widget');
        $voice = null !== $this->voiceInput ? $this->voiceInput->forModule($model, $pageModel instanceof PageModel ? $pageModel : null) : ['enabled' => false, 'maxSeconds' => 0, 'language' => 'de'];
        $template->voice = $voice['enabled'];
        $template->voiceMaxSeconds = $voice['maxSeconds'];
        $template->voiceLanguage = $voice['language'];
        $template->labelVoice = $GLOBALS['TL_LANG']['chatbot_widget']['voice'] ?? 'Spracheingabe starten';

        return $template->getResponse();
    }

    /**
     * Runs a regular Contao site search (as used by the core "search" module)
     * for the "full" template variant, which shows the chat answer together
     * with the normal search results.
     */
    private function performNativeSearch(string $keywords, ?PageModel $pageModel, int $limit = 20): array
    {
        if (!$pageModel instanceof PageModel) {
            return ['count' => 0, 'results' => []];
        }

        $pageModel->loadDetails();
        $pageIds = Database::getInstance()->getChildRecords($pageModel->rootId, 'tl_page');

        if (empty($pageIds)) {
            return ['count' => 0, 'results' => []];
        }

        try {
            $result = Search::query($keywords, true, $pageIds, false, 0);
        } catch (\Exception $e) {
            return ['count' => 0, 'results' => []];
        }

        if (Config::get('indexProtected')) {
            $result->applyFilter(static function (array $row): bool {
                return empty($row['protected']) || System::getContainer()->get('security.helper')->isGranted(
                    ContaoCorePermissions::MEMBER_IN_GROUPS,
                    StringUtil::deserialize($row['groups'] ?? null, true)
                );
            });
        }

        $count = $result->getCount();
        $rows = $count > 0 ? $result->getResults(min($limit, $count), 0) : [];
        $results = [];

        foreach ($rows as $row) {
            $results[] = [
                'title'   => StringUtil::specialchars(StringUtil::stripInsertTags((string) $row['title'])),
                'href'    => StringUtil::specialchars((string) $row['url']),
                'url'     => StringUtil::specialchars(urldecode((string) $row['url'])),
                'context' => $this->buildContextSnippet((string) ($row['text'] ?? '')),
            ];
        }

        return ['count' => $count, 'results' => $results];
    }

    /**
     * Builds a short, plain-text snippet from the indexed page text for a search result.
     */
    private function buildContextSnippet(string $text): string
    {
        $text = trim(preg_replace('/\s+/', ' ', StringUtil::stripInsertTags($text)));

        if ($text === '') {
            return '';
        }

        return StringUtil::specialchars(StringUtil::substrHtml($text, 300));
    }
}
