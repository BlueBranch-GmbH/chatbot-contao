<?php

namespace Bluebranch\Chatbot\Controller\Backend;

use Bluebranch\Chatbot\classes\ChatbotAPI;
use Contao\BackendUser;
use Contao\CoreBundle\Controller\AbstractBackendController;
use Contao\CoreBundle\DependencyInjection\Attribute\AsController;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\PageModel;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

#[AsController]
class TrainedContentController extends AbstractBackendController
{
    private ChatbotAPI $chatbotApi;
    private ContaoFramework $framework;
    private ?ParameterBagInterface $parameterBag;

    public function __construct(?ChatbotAPI $chatbotApi = null, ?ContaoFramework $framework = null, ?ParameterBagInterface $parameterBag = null)
    {
        $container = \Contao\System::getContainer();

        $this->chatbotApi = $chatbotApi ?? $container->get(ChatbotAPI::class);
        $this->framework = $framework ?? $container->get('contao.framework');
        $this->parameterBag = $parameterBag ?? ($container->has('parameter_bag') ? $container->get('parameter_bag') : null);

        if (null === $this->container) {
            $this->setContainer($container);
        }
    }

    #[Route('/%contao.backend.route_prefix%/chatbot/trained-content', name: self::class, defaults: ['_scope' => 'backend', '_token_check' => true])]
    public function generate(?Request $request = null)
    {
        $isLegacy = ($request === null);

        if ($request === null) {
            $request = $this->container->get('request_stack')->getCurrentRequest() ?? new Request();
        }

        $this->framework->initialize();
        $user = BackendUser::getInstance();
        if (!$user instanceof BackendUser || !$user->isAdmin) {
            return new Response('<div style="padding: 20px;"><p style="color: #d9534f; font-weight: bold;">Zugriff verweigert.</p><p>Diese Seite ist nur für Administratoren zugänglich.</p></div>', 403);
        }

        $session = $request->getSession();
        $sessionKey = 'chatbot_selected_page_id';

        $availableRootPages = $this->getAvailableRootPages();

        if (empty($availableRootPages)) {
            return new Response('<div style="padding: 20px;"><p style="color: #d9534f; font-weight: bold;">Fehler:</p><p>Es wurden keine Startpunkte gefunden.</p></div>');
        }

        $allowedLimits = [10, 20, 50, 100, 250];
        $limit = $request->query->getInt('limit', $session->get('chatbot_limit', 10));
        if (!in_array($limit, $allowedLimits, true)) {
            $limit = 20;
        }
        $session->set('chatbot_limit', $limit);


        // Priority: 1. Query, 2. Session
        $pageId = $request->query->get('pageId') ?? $session->get($sessionKey);

        // Validate that selected pageId is in availableRootPages
        $selectedPage = null;
        foreach ($availableRootPages as $rp) {
            if ((string)$rp['id'] === (string)$pageId) {
                $selectedPage = $rp;
                break;
            }
        }

        // If no pageId is provided or it's invalid, default to the first root page
        if ($selectedPage === null) {
            $selectedPage = $availableRootPages[0];
            $pageId = $selectedPage['id'];
        }

        // Save to session
        $session->set($sessionKey, $pageId);

        // Check for API Key
        if (empty($selectedPage['has_api_key'])) {
            $streamToken = bin2hex(random_bytes(32));
            $session->set('_chatbot_stream_token', $streamToken);
            $viewData = [
                'content' => [],
                'limit' => $limit,
                'rootPages' => $availableRootPages,
                'selectedPageId' => $pageId,
                'error' => sprintf('Seite %s hat keinen API Key hinterlegt.', $selectedPage['title']),
                'no_api_key' => true,
                'allowedLimits' => $allowedLimits,
                'requestToken' => $streamToken,
                'tier' => null,
            ];
            $response = $this->render('@Chatbot/Backend/trained_content.html.twig', $viewData);
            return $isLegacy ? $response->getContent() : $response;
        }

        // Stufe des Zugangs. Bewusst vor dem Auflisten: Schlägt die Auskunft fehl, soll die
        // Seite trotzdem erscheinen — der Hinweis entfällt dann einfach.
        $tier = $this->resolveTier($pageId);

        // Fetch all chunks, then group in PHP — pagination/search handled client-side
        $response = $this->chatbotApi->listContent(5000, 0, $pageId);

        if (isset($response['success']) && $response['success'] === false) {
            $streamToken = bin2hex(random_bytes(32));
            $session->set('_chatbot_stream_token', $streamToken);
            $viewData = [
                'content' => [],
                'limit' => $limit,
                'rootPages' => $availableRootPages,
                'selectedPageId' => $pageId,
                'error' => $response['message'] ?? 'Unbekannter API Fehler',
                'allowedLimits' => $allowedLimits,
                'requestToken' => $streamToken,
                'tier' => $tier,
            ];
            $response = $this->render('@Chatbot/Backend/trained_content.html.twig', $viewData);
            return $isLegacy ? $response->getContent() : $response;
        }

        $data = $response['data'] ?? [];

        // Group by externalId across all chunks
        $groupedData = [];
        foreach ($data as $item) {
            $externalId = $item['externalId'] ?? 'unknown';
            if (!isset($groupedData[$externalId])) {
                $groupedData[$externalId] = $item;
                $groupedData[$externalId]['chunkCount'] = 1;
            } else {
                $groupedData[$externalId]['chunkCount']++;
            }
        }

        $groupedData = array_values($groupedData);

        $streamToken = bin2hex(random_bytes(32));
        $session->set('_chatbot_stream_token', $streamToken);

        $viewData = [
            'content' => $groupedData,
            'limit' => $limit,
            'rootPages' => $availableRootPages,
            'selectedPageId' => $pageId,
            'error' => null,
            'allowedLimits' => $allowedLimits,
            'requestToken' => $streamToken,
            'tier' => $tier,
        ];

        $response = $this->render('@Chatbot/Backend/trained_content.html.twig', $viewData);

        return $isLegacy ? $response->getContent() : $response;
    }

    private function getParameterBagValue(string $name): mixed
    {
        if ($this->parameterBag !== null) {
            return $this->parameterBag->get($name);
        }

        if ($this->container->has('parameter_bag')) {
            return $this->container->get('parameter_bag')->get($name);
        }

        // Fallback for contao.csrf_token_name if parameter_bag is not available
        if ($name === 'contao.csrf_token_name') {
            return 'csrf_token';
        }

        return null;
    }

    /**
     * Get all published root pages.
     *
     * @return array
     */
    private function getAvailableRootPages(): array
    {
        $this->framework->initialize();
        $rootPages = PageModel::findPublishedRootPages();
        $availableRootPages = [];

        if ($rootPages !== null) {
            foreach ($rootPages as $rootPage) {
                $availableRootPages[] = [
                    'id' => $rootPage->id,
                    'title' => $rootPage->title . ' (' . ($rootPage->dns ?: $rootPage->id) . ')',
                    'has_api_key' => !empty($rootPage->chatbot_api_key)
                ];
            }
        }

        return $availableRootPages;
    }
    public static function getSubscribedServices(): array
    {
        return array_merge(parent::getSubscribedServices(), [
            'contao.csrf.token_manager' => \Contao\CoreBundle\Csrf\ContaoCsrfTokenManager::class,
            'parameter_bag' => \Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface::class,
            'request_stack' => \Symfony\Component\HttpFoundation\RequestStack::class,
        ]);
    }

    /**
     * Holt die Nutzungsstufe des Zugangs.
     *
     * Faellt die Auskunft aus - alte API-Fassung, Netzproblem, abgelaufener Schluessel -,
     * liefert die Methode null und die Seite erscheint ohne Hinweis. Ein Fehler beim
     * Nachschlagen der Stufe darf die Uebersicht der trainierten Inhalte nicht blockieren.
     */
    private function resolveTier($pageId): ?array
    {
        $tier = $this->chatbotApi->getTier($pageId);

        if (!empty($tier['success']) && isset($tier['tier'])) {
            return $tier;
        }

        return null;
    }
}
