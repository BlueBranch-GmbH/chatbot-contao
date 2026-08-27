<?php

namespace Bluebranch\Chatbot\classes;

use Contao\Config;
use Contao\PageModel;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class ChatbotAPI
{
    private HttpClientInterface $httpClient;
    private LoggerInterface $logger;
    private string $apiUrl = 'https://api.chatbot.bluebranch.de';

    public function __construct(HttpClientInterface $httpClient, LoggerInterface $logger)
    {
        $this->httpClient = $httpClient;
        $this->logger = $logger;
    }

    /**
     * Train new content for the chatbot.
     */
    public function trainContent(array $payload, $page = null): array
    {
        return $this->sendRequest('POST', '/api/v1/chatbot-ai/content', $page, ['json' => $payload]);
    }

    /**
     * Fragt die Nutzungsstufe des hinterlegten API-Keys ab.
     *
     * Die Antwort enthaelt neben Stufe und Kontingent auch den fertigen Hinweistext unter
     * `notice`. Beides wird hier bewusst **nicht** nachgebildet: Aendern sich Kontingent,
     * Wortlaut oder Anschrift, genuegt ein Deployment der API - diese Erweiterung muss dafuer
     * nicht neu ausgeliefert werden.
     *
     * @return array Stufe, Kontingent und Hinweis; bei Fehlern success=false
     */
    public function getTier($page = null): array
    {
        return $this->sendRequest("GET", "/api/v1/user/me/tier", $page);
    }

    /**
     * Lists all trained contents for the current user.
     */
    public function listContent(int $limit = 100, int $offset = 0, $page = null, array $options = []): array
    {
        return $this->sendRequest('GET', '/api/v1/chatbot-ai/content', $page, array_merge($options, [
            'query' => [
                'limit' => $limit,
                'offset' => $offset
            ]
        ]));
    }

    /**
     * Deletes specific trained content.
     */
    public function deleteContent(string $id, $page = null, array $options = []): array
    {
        return $this->sendRequest('DELETE', '/api/v1/chatbot-ai/content/' . $id, $page, $options);
    }

    /**
     * Generates a response from the chatbot using RAG.
     */
    public function generateSearch(array $payload, $page = null): array
    {
        return $this->sendRequest('POST', '/api/v1/chatbot-ai/generate/search', $page, ['json' => $payload]);
    }

    /**
     * Streams a response from the chatbot (search/summary mode).
     */
    public function streamSearch(array $payload, $page = null)
    {
        return $this->streamGenerate('/api/v1/chatbot-ai/generate/chatbot/stream', $payload, $page);
    }

    /**
     * Streams a response from the chatbot (fast dialogue mode, for the chat widget).
     */
    public function streamChat(array $payload, $page = null)
    {
        return $this->streamGenerate('/api/v1/chatbot-ai/generate/chat/stream', $payload, $page);
    }

    /**
     * Opens a streamed request against one of the "generate" SSE endpoints.
     */
    private function streamGenerate(string $endpoint, array $payload, $page = null)
    {
        $pageModel = $this->getPageModel($page);
        $apiToken = $this->getApiKey($pageModel);

        if (empty($apiToken)) {
            $this->logger->error('Chatbot API Token ist nicht konfiguriert.');
            return null;
        }

        $options = [
            'query' => [
                'prompt' => $payload['prompt'] ?? '',
                'language' => $payload['language'] ?? 'de',
            ],
            'headers' => [
                'x-api-key' => $apiToken,
                'Accept' => 'text/event-stream',
            ],
            'buffer' => false,
        ];

        if (!empty($payload['chat_context'])) {
            $options['query']['chat_context'] = $payload['chat_context'];
        }

        return $this->httpClient->request('GET', $this->apiUrl . $endpoint, $options);
    }

    /**
     * Deletes all trained contents for the current user.
     */
    public function deleteAllContent($page = null): array
    {
        return $this->sendRequest('DELETE', '/api/v1/chatbot-ai/content', $page);
    }

    /**
     * Sends a request to the Chatbot API.
     */
    private function sendRequest(string $method, string $endpoint, $page = null, array $options = []): array
    {
        $pageModel = $this->getPageModel($page);
        $apiToken = $this->getApiKey($pageModel);

        if (empty($apiToken)) {
            $this->logger->error('Chatbot API Token ist nicht konfiguriert.');
            return ['success' => false, 'message' => 'Chatbot API Token ist nicht konfiguriert.'];
        }

        try {
            $options['headers'] = array_merge($options['headers'] ?? [], [
                'x-api-key' => $apiToken, // Einige Endpunkte nutzen eventuell x-api-key
                'Accept' => 'application/json',
            ]);

            $response = $this->httpClient->request($method, $this->apiUrl . $endpoint, $options);
            $statusCode = $response->getStatusCode();
            $content = $response->getContent(false);
            $data = json_decode($content, true) ?: [];

            if ($statusCode >= 400) {
                $this->logger->error(sprintf('Chatbot API Fehler (%s %s): %s', $method, $endpoint, $content), [
                    'status_code' => $statusCode
                ]);
                return array_merge(['success' => false, 'statusCode' => $statusCode], $data);
            }

            return array_merge(['success' => true, 'statusCode' => $statusCode], $data);
        } catch (\Exception $e) {
            $this->logger->error(sprintf('Fehler bei der Kommunikation mit der Chatbot API (%s %s): %s', $method, $endpoint, $e->getMessage()));
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Resolves a PageModel from ID, object or global context.
     *
     * @param mixed $page Page ID or PageModel
     * @return PageModel|null
     */
    public function getPageModel($page = null): ?PageModel
    {
        if ($page instanceof PageModel) {
            return $page;
        }

        if (is_numeric($page)) {
            return PageModel::findById($page);
        }

        global $objPage;
        if ($objPage instanceof PageModel) {
            return $objPage;
        }

        return null;
    }

    /**
     * Gets the API Key from the given page model's root page.
     */
    public function getApiKey(?PageModel $pageModel = null): string
    {
        if ($pageModel instanceof PageModel) {
            // loadDetails() stellt sicher, dass rootId (und andere Details) geladen werden
            $pageModel->loadDetails();
            $objRootPage = PageModel::findById($pageModel->rootId);

            if ($objRootPage instanceof PageModel && !empty($objRootPage->chatbot_api_key)) {
                return (string)$objRootPage->chatbot_api_key;
            }
        }

        // Fallback to config for backward compatibility or backend use where no page might be available
        return (string)Config::get('chatbot_api_key');
    }
}
