<?php

namespace Bluebranch\Chatbot\Controller;

use Bluebranch\Chatbot\classes\ChatbotAPI;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\PageModel;
use Contao\System;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;


class ChatbotAPIController extends AbstractController
{
    private ChatbotAPI $chatbotApi;
    private LoggerInterface $logger;
    private HttpClientInterface $httpClient;
    private ContaoFramework $framework;

    /**
     * Das Framework ist bewusst optional: Nach einem Update laeuft die neue Klasse
     * zunaechst gegen den alten, noch kompilierten Dienst-Container, der nur drei
     * Argumente uebergibt. Ein Pflichtargument wuerde dort einen ArgumentCountError
     * ausloesen, bis jemand den Cache leert -- und der Chatbot schwiege solange.
     */
    public function __construct(ChatbotAPI $chatbotApi, LoggerInterface $logger, HttpClientInterface $httpClient, ?ContaoFramework $framework = null)
    {
        $this->chatbotApi = $chatbotApi;
        $this->logger = $logger;
        $this->httpClient = $httpClient;
        $this->framework = $framework ?? System::getContainer()->get('contao.framework');
    }

    #[Route('/bluebranch/chatbot/api/v1/generate/search', name: 'bluebranch_chatbot_generate_seach', methods: ['POST'], defaults: ['_scope' => 'frontend', '_token_check' => true])]
    #[Route('/bluebranch/chatbot/api/v1/be/generate/search', name: 'bluebranch_chatbot_generate_search_be', methods: ['POST'], defaults: ['_scope' => 'backend', '_token_check' => true])]
    public function generateSearch(Request $request): JsonResponse
    {
        $this->framework->initialize();

        // Contao überspringt die CSRF-Prüfung bei Anfragen ohne Cookie – es gibt dann
        // keine Session, die zu schützen wäre. Für diesen Endpunkt reicht das nicht:
        // über 'pageId' im Rumpf lässt sich die Root-Seite und damit der API-Schlüssel
        // wählen, sodass ein Fremder ohne jeden Token Anfragen auf Kosten des
        // Kontingents stellen könnte. Deshalb derselbe Session-Token wie bei den
        // Stream-Routen; er setzt eine echte Sitzung voraus.
        if (!$this->hasValidStreamToken($request)) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid token'], 403);
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
        $this->framework->initialize();

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
        $this->framework->initialize();

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
        $this->framework->initialize();

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
        $this->framework->initialize();

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

    /**
     * Liest die Fehlermeldung der API aus einer abgelehnten Antwort - fuer das Log.
     *
     * Bei einer nicht gepufferten Antwort kann der Rumpf bereits verworfen sein; dann bleibt
     * nur der Statuscode. Ein Fehlschlag hier darf den Ablauf nicht stoeren.
     */
    private function upstreamMeldung($apiResponse): string
    {
        try {
            $inhalt = $apiResponse->getContent(false);
            $daten = json_decode($inhalt, true);

            if (is_array($daten) && isset($daten['message'])) {
                return is_array($daten['message']) ? json_encode($daten['message']) : (string) $daten['message'];
            }

            return substr((string) $inhalt, 0, 300);
        } catch (\Throwable $e) {
            return '(Rumpf nicht lesbar: ' . $e->getMessage() . ')';
        }
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

            try {
                // Den Status abfragen, bevor gestreamt wird: Bei 4xx/5xx wirft der
                // HttpClient sonst erst beim Zugriff auf den ersten Chunk – da hat der
                // Browser längst eine 200 mit text/event-stream erhalten und sieht nur
                // noch einen Abriss ohne erkennbaren Grund.
                $statusCode = $apiResponse->getStatusCode();

                if ($statusCode >= 400) {
                    // Den ausfuehrlichen Grund ins Log, nicht ins Chatfenster: Bei 429 nennt die
                    // API die Nutzungsstufe des Betreibers samt Kontaktadresse. Das gehoert in
                    // die Betriebsansicht, nicht vor die Augen der Website-Besucher.
                    $this->logger->error(sprintf(
                        'Chatbot API antwortete auf einen Stream-Aufruf mit HTTP %d: %s',
                        $statusCode,
                        $this->upstreamMeldung($apiResponse)
                    ));

                    echo "event: error\n";
                    echo 'data: ' . json_encode([
                        'message' => 429 === $statusCode
                            ? 'Zurzeit sind zu viele Anfragen offen. Bitte versuchen Sie es in einer Minute erneut.'
                            : 'Die Anfrage konnte nicht beantwortet werden.',
                        'status' => $statusCode,
                    ]) . "\n\n";
                    flush();

                    return;
                }

                foreach ($this->httpClient->stream($apiResponse) as $chunk) {
                    if ($chunk->isTimeout()) {
                        continue;
                    }

                    // Auch der letzte Chunk kann noch Nutzdaten tragen; ihn pauschal zu
                    // überspringen verschluckt im Zweifel das Ende der Antwort.
                    $content = $chunk->getContent();

                    if ('' !== $content) {
                        echo $content;
                        flush();
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->error('Fehler beim Streamen der Chatbot-Antwort: ' . $e->getMessage());

                echo "event: error\n";
                echo 'data: {"message": "Stream aborted"}' . "\n\n";
                flush();

                return;
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
