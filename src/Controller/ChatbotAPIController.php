<?php

namespace Bluebranch\Chatbot\Controller;

use Bluebranch\Chatbot\classes\ChatbotAPI;
use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Contao\PageModel;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Contracts\HttpClient\HttpClientInterface;


class ChatbotAPIController extends AbstractController
{
    private ChatbotAPI $chatbotApi;
    private LoggerInterface $logger;
    private HttpClientInterface $httpClient;

    public function __construct(ChatbotAPI $chatbotApi, LoggerInterface $logger, HttpClientInterface $httpClient)
    {
        $this->chatbotApi = $chatbotApi;
        $this->logger = $logger;
        $this->httpClient = $httpClient;
    }

    public static function getSubscribedServices(): array
    {
        return array_merge(parent::getSubscribedServices(), [
            'contao.csrf.token_manager' => ContaoCsrfTokenManager::class,
        ]);
    }

    #[Route('/bluebranch/chatbot/api/v1/generate/search', name: 'bluebranch_chatbot_generate_seach', methods: ['POST'], defaults: ['_scope' => 'frontend', '_token_check' => true])]
    #[Route('/bluebranch/chatbot/api/v1/be/generate/search', name: 'bluebranch_chatbot_generate_search_be', methods: ['POST'], defaults: ['_scope' => 'backend', '_token_check' => true])]
    public function generateSearch(Request $request): JsonResponse
    {
        $tokenManager = $this->container->get('contao.csrf.token_manager');
        $tokenValue = $request->headers->get('X-CSRF-Token');
        $tokenId = $this->getParameter('contao.csrf_token_name');

        if (!$tokenManager->isTokenValid(new CsrfToken($tokenId, $tokenValue))) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid CSRF token'], 403);
        }

        $content = $request->getContent();

        $payload = json_decode($content, true) ?: [];

        // Pass current page model to API for correct API key selection
        $pageModel = $request->attributes->get('pageModel');

        if (!$pageModel instanceof PageModel && !empty($payload['pageId'])) {
            $pageModel = PageModel::findById($payload['pageId']);
            unset($payload['pageId']);
        }

        $result = $this->chatbotApi->generateSearch($payload, $pageModel);

        $this->logger->info('Chatbot Search Request', [
            'payload' => $payload,
            'ip' => $request->getClientIp(),
            'ua' => $request->headers->get('User-Agent')
        ]);

        return new JsonResponse($result);
    }

    #[Route('/%contao.backend.route_prefix%/chatbot/api/v1/content', name: 'bluebranch_chatbot_delete_all_content_be', methods: ['DELETE'], defaults: ['_scope' => 'backend', '_token_check' => false])]
    public function deleteAllContent(Request $request): JsonResponse
    {
        if (!$this->isAdmin()) {
            return new JsonResponse(['success' => false, 'message' => 'Access denied'], 403);
        }

        $session = $request->getSession();
        $expectedToken = $session->get('_chatbot_stream_token');
        $tokenValue = $request->headers->get('X-Stream-Token');

        if (!$expectedToken || !hash_equals($expectedToken, (string) $tokenValue)) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid token'], 403);
        }

        $pageId = $request->query->get('pageId');
        $pageModel = $pageId ? \Contao\PageModel::findById($pageId) : null;

        $result = $this->chatbotApi->deleteAllContent($pageModel);

        return new JsonResponse($result);
    }

    #[Route('/%contao.backend.route_prefix%/chatbot/api/v1/content/{externalId}', name: 'bluebranch_chatbot_delete_content_be', methods: ['DELETE'], defaults: ['_scope' => 'backend', '_token_check' => false])]
    public function deleteContent(Request $request, string $externalId): JsonResponse
    {
        if (!$this->isAdmin()) {
            return new JsonResponse(['success' => false, 'message' => 'Access denied'], 403);
        }

        $session = $request->getSession();
        $expectedToken = $session->get('_chatbot_stream_token');
        $tokenValue = $request->headers->get('X-Stream-Token');

        if (!$expectedToken || !hash_equals($expectedToken, (string) $tokenValue)) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid token'], 403);
        }

        $pageId = $request->query->get('pageId');
        $pageModel = $pageId ? \Contao\PageModel::findById($pageId) : null;

        $result = $this->chatbotApi->deleteContent($externalId, $pageModel);

        return new JsonResponse($result);
    }

    private function isAdmin(): bool
    {
        $user = \Contao\BackendUser::getInstance();
        return $user instanceof \Contao\BackendUser && $user->isAdmin;
    }

    #[Route('/bluebranch/chatbot/api/v1/generate/stream', name: 'bluebranch_chatbot_generate_stream', methods: ['GET', 'POST'], defaults: ['_scope' => 'frontend', '_token_check' => false])]
    #[Route('/%contao.backend.route_prefix%/bluebranch/chatbot/generate/stream', name: 'bluebranch_chatbot_generate_stream_be', methods: ['GET', 'POST'], defaults: ['_scope' => 'backend', '_token_check' => false])]
    public function generateStream(Request $request): StreamedResponse
    {
        if (!$this->hasValidStreamToken($request)) {
            $this->logger->error('Invalid stream token in generateStream');

            return $this->invalidStreamTokenResponse();
        }

        [$payload, $pageModel] = $this->resolveGenerationPayload($request);

        return $this->buildStreamedResponse($this->chatbotApi->streamSearch($payload, $pageModel));
    }

    #[Route('/bluebranch/chatbot/api/v1/chat/stream', name: 'bluebranch_chatbot_chat_stream', methods: ['GET', 'POST'], defaults: ['_scope' => 'frontend', '_token_check' => false])]
    #[Route('/%contao.backend.route_prefix%/bluebranch/chatbot/chat/stream', name: 'bluebranch_chatbot_chat_stream_be', methods: ['GET', 'POST'], defaults: ['_scope' => 'backend', '_token_check' => false])]
    public function chatStream(Request $request): StreamedResponse
    {
        if (!$this->hasValidStreamToken($request)) {
            $this->logger->error('Invalid stream token in chatStream');

            return $this->invalidStreamTokenResponse();
        }

        [$payload, $pageModel] = $this->resolveGenerationPayload($request);

        return $this->buildStreamedResponse($this->chatbotApi->streamChat($payload, $pageModel));
    }

    private function hasValidStreamToken(Request $request): bool
    {
        $tokenValue = $request->headers->get('X-CSRF-Token') ?: $request->query->get('token');
        $expectedToken = $request->getSession()->get('_chatbot_stream_token');

        return $expectedToken && hash_equals($expectedToken, (string) $tokenValue);
    }

    private function invalidStreamTokenResponse(): StreamedResponse
    {
        return new StreamedResponse(function () {
            echo "event: error\n";
            echo 'data: {"message": "Invalid stream token"}' . "\n\n";
            ob_flush();
            flush();
        }, 403, ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache', 'Connection' => 'keep-alive']);
    }

    /**
     * @return array{0: array, 1: ?PageModel}
     */
    private function resolveGenerationPayload(Request $request): array
    {
        $payload = [];
        if ($request->isMethod('POST')) {
            $content = $request->getContent();
            $payload = json_decode($content, true) ?: [];
        } else {
            $payload['prompt'] = $request->query->get('prompt');
            $payload['language'] = $request->query->get('language', 'de');
            $payload['pageId'] = $request->query->get('pageId');
            $payload['chat_context'] = $request->query->get('chat_context');
        }

        $pageModel = null;
        if (!empty($payload['pageId'])) {
            $pageModel = PageModel::findById($payload['pageId']);
            unset($payload['pageId']);
        }

        return [$payload, $pageModel];
    }

    private function buildStreamedResponse($apiResponse): StreamedResponse
    {
        return new StreamedResponse(function () use ($apiResponse) {
            // Clear all active PHP output buffers so chunks are sent immediately
            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            if ($apiResponse === null) {
                echo "event: error\n";
                echo 'data: {"message": "Chatbot API not configured"}' . "\n\n";
                flush();
                return;
            }

            foreach ($this->httpClient->stream($apiResponse) as $chunk) {
                if (!$chunk->isLast()) {
                    echo $chunk->getContent();
                    flush();
                }
            }

            // Send end of stream event
            echo "event: end\n";
            echo 'data: {"status": "completed"}' . "\n\n";
            flush();
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no', // Disable buffering in Nginx
        ]);
    }
}
