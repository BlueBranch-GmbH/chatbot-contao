<?php

namespace Bluebranch\Chatbot\classes;

use Contao\PageModel;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Ruft eine Seite einmal ab, damit Contao sie rendert und indexiert; der indexPage-Hook trainiert sie dabei.
 *
 * Gegenstueck zum Abruf im ScheduledContentCron, aber fuer den Assistenten im Backend: Ein Administrator loest
 * es aus, deshalb darf auch eine Seite ohne fest eingetragene Domain abgerufen werden (Contao nimmt dann den Host
 * des Backends). Weiterleitungen werden nur innerhalb desselben Hosts verfolgt.
 */
class PageFetcher
{
    private HttpClientInterface $httpClient;
    private LoggerInterface $logger;

    public function __construct(HttpClientInterface $httpClient, LoggerInterface $logger)
    {
        $this->httpClient = $httpClient;
        $this->logger = $logger;
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function fetch(int $pageId): array
    {
        $page = PageModel::findById($pageId);

        if (!$page instanceof PageModel || !\in_array($page->type, ['regular', 'forward'], true)) {
            return ['ok' => false, 'message' => 'keine abrufbare Seite'];
        }

        $page->loadDetails();

        try {
            $url = $page->getAbsoluteUrl();
            $parts = parse_url($url);

            // Hat die Website eine feste Domain, gilt genau sie - Contao haengt sonst den Port der aktuellen
            // Anfrage an (etwa den eines Entwicklungs-Zugangs), der unter der oeffentlichen Adresse nicht stimmt.
            if ('' !== (string) $page->domain && \is_array($parts)) {
                $url = ($page->rootUseSSL ? 'https' : 'http') . '://' . $page->domain
                    . ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
            }

            $host = (string) parse_url($url, PHP_URL_HOST);

            $response = $this->httpClient->request('GET', $url, [
                'timeout' => 15,
                'max_redirects' => 3,
                'headers' => ['User-Agent' => 'BlueBranch-Chatbot-Training/1.0'],
            ]);

            $status = $response->getStatusCode();
            // Bis zum Ende lesen, damit Contao fertig rendert, bevor die Verbindung endet.
            $response->getContent(false);

            $finalHost = (string) parse_url((string) $response->getInfo('url'), PHP_URL_HOST);

            if ($finalHost !== $host) {
                return ['ok' => false, 'message' => 'Weiterleitung auf einen anderen Host'];
            }

            if ($status >= 400) {
                return ['ok' => false, 'message' => 'HTTP ' . $status];
            }

            return ['ok' => true, 'message' => ''];
        } catch (\Throwable $e) {
            $this->logger->warning(sprintf('Chatbot: Seite %d konnte nicht abgerufen werden: %s', $pageId, $e->getMessage()));

            return ['ok' => false, 'message' => mb_substr($e->getMessage(), 0, 160)];
        }
    }
}
