<?php

namespace Bluebranch\Chatbot\Module;

use Bluebranch\Chatbot\classes\ChatbotAPI;
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

    public function __construct(ChatbotAPI $chatbotApi = null)
    {
        $this->chatbotApi = $chatbotApi ?? \Contao\System::getContainer()->get(ChatbotAPI::class);
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

        // Native Contao search results (used by the "full" template variant, which
        // shows the search field, the chat answer and the regular search results)
        $nativeResults = $query !== '' ? $this->performNativeSearch($query, $pageModel) : ['count' => 0, 'results' => []];
        $template->searchResultsCount = $nativeResults['count'];
        $template->searchResults = $nativeResults['results'];

        // Generate a secure stream token, store in session so the API controller can validate it
        $session = $request->getSession();
        $streamToken = $session->get('_chatbot_stream_token');
        if (!$streamToken) {
            $streamToken = bin2hex(random_bytes(32));
            $session->set('_chatbot_stream_token', $streamToken);
        }
        $template->requestToken = $streamToken;

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
