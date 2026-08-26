<?php

namespace Bluebranch\Chatbot\EventListener;

use Contao\System;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class ChatbotListener
{
    /** @var HttpClientInterface */
    private $httpClient;

    public function __construct(HttpClientInterface $httpClient)
    {
        System::loadLanguageFile('tl_settings');
        $this->httpClient = $httpClient;
    }

    public function __invoke(array $files): void
    {

    }

    private function printError(string $message): void
    {
        echo '<p class="tl_error">' . htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
    }

    private function isFailedResponse(array $response): bool
    {
        return !isset($response['success']) || $response['success'] === false;
    }
}
