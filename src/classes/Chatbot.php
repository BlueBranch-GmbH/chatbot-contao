<?php

namespace Bluebranch\Chatbot\classes;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class Chatbot
{

    /** @var HttpClientInterface */
    private $httpClient;

    public function __construct(HttpClientInterface $httpClient)
    {
        $this->httpClient = $httpClient;
    }
}
