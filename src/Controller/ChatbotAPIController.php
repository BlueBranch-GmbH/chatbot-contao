<?php

namespace Bluebranch\Chatbot\Controller;

use Bluebranch\Chatbot\classes\ChatbotAPI;
use Bluebranch\Chatbot\classes\ChatLog;
use Bluebranch\Chatbot\classes\ExtraContent;
use Bluebranch\Chatbot\classes\RateLimit;
use Bluebranch\Chatbot\classes\RequestSignature;
use Bluebranch\Chatbot\classes\StreamRecorder;
use Bluebranch\Chatbot\classes\StreamToken;
use Bluebranch\Chatbot\classes\TrainingState;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\ModuleModel;
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
    private ?ChatLog $chatLog;
    private ?ExtraContent $extraContent;
    private ?RateLimit $rateLimit;

    /** Laengen: der laengste regulaere Prompt ist „Seite zusammenfassen“ mit 2.500 Zeichen Seitentext. */
    private const MAX_PROMPT = 4000;
    private const MAX_CHAT_CONTEXT = 8000;

    /** Anfragen je Minute: je Client und fuer die ganze Installation. */
    private const LIMIT_ANSWERS_CLIENT = 20;
    private const LIMIT_ANSWERS_GLOBAL = 300;
    private const LIMIT_TOKEN_CLIENT = 30;
    private const LIMIT_FEEDBACK_CLIENT = 30;

    /** Welche Modultypen welche Quelle im Protokoll ergeben. */
    private const MODULE_SOURCES = [
        'chatbot_widget' => 'widget',
        'chatbot_ask' => 'ask',
        'chatbot_generate_search' => 'search',
    ];


    /**
     * Das Framework ist bewusst optional: Nach einem Update laeuft die neue Klasse
     * zunaechst gegen den alten, noch kompilierten Dienst-Container, der nur drei
     * Argumente uebergibt. Ein Pflichtargument wuerde dort einen ArgumentCountError
     * ausloesen, bis jemand den Cache leert -- und der Chatbot schwiege solange.
     */
    public function __construct(ChatbotAPI $chatbotApi, LoggerInterface $logger, HttpClientInterface $httpClient, ?ContaoFramework $framework = null, ?ChatLog $chatLog = null, ?ExtraContent $extraContent = null, ?RateLimit $rateLimit = null)
    {
        $this->chatbotApi = $chatbotApi;
        $this->logger = $logger;
        $this->httpClient = $httpClient;
        $this->framework = $framework ?? System::getContainer()->get('contao.framework');
        // Wie das Framework optional: ohne geleerten Cache fehlt das Argument, dann bleibt das
        // Protokoll aus, statt dass der Chatbot verstummt.
        $this->chatLog = $chatLog;
        $this->extraContent = $extraContent;
        $this->rateLimit = $rateLimit;
    }

    /**
     * Nimmt die Bewertung einer Antwort entgegen.
     *
     * Die Antwort ist ueber ihre zufaellige Kennung `ref` adressiert, die nur der Browser kennt,
     * der die Frage gestellt hat. Der Kommentar wird bereinigt und gekuerzt; angezeigt wird er
     * ausschliesslich escaped.
     */
    #[Route('/bluebranch/chatbot/api/v1/feedback', name: 'bluebranch_chatbot_feedback', methods: ['POST'], defaults: ['_scope' => 'frontend', '_token_check' => false])]
    public function feedback(Request $request): JsonResponse
    {
        $this->framework->initialize();

        if (!$this->hasValidStreamToken($request)) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid token'], 403);
        }

        if (null === $this->chatLog) {
            return new JsonResponse(['success' => false], 503);
        }

        // Je Client statt je Sitzung: Eine neue Sitzung kostet nur eine Anfrage.
        if (null !== $this->rateLimit && !$this->rateLimit->allowClient($request, 'feedback', self::LIMIT_FEEDBACK_CLIENT)) {
            return new JsonResponse(['success' => false, 'message' => 'Too many requests'], 429);
        }

        $payload = json_decode((string) $request->getContent(), true);
        $payload = \is_array($payload) ? $payload : [];

        $ref = $payload['ref'] ?? '';
        $rating = $payload['rating'] ?? '';
        $comment = \is_string($payload['comment'] ?? null) ? $payload['comment'] : '';

        if (!ChatLog::isValidRef($ref) || !\in_array($rating, ['up', 'down'], true)) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid feedback'], 400);
        }

        if (!$this->chatLog->applyFeedback($ref, $rating, $comment)) {
            return new JsonResponse(['success' => false, 'message' => 'Unknown answer'], 404);
        }

        $response = new JsonResponse(['success' => true]);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    /**
     * Gibt den Sitzungs-Token fuer die Antwort-Routen heraus.
     *
     * Die Frontend-Module holen ihn erst bei der ersten Frage (stream-token.js). Stuende er
     * im Seiten-HTML, braeuchte jede Seite mit Chatbot eine Session, und Contao liefert
     * Antworten mit Session-Cookie nie aus dem HTTP-Cache. POST, damit weder Browser noch
     * Proxy die Antwort zwischenspeichern oder vorab laden.
     */
    #[Route('/bluebranch/chatbot/api/v1/token', name: 'bluebranch_chatbot_token', methods: ['POST'], defaults: ['_scope' => 'frontend', '_token_check' => false])]
    public function token(Request $request): JsonResponse
    {
        if (null !== $this->rateLimit && !$this->rateLimit->allowClient($request, 'token', self::LIMIT_TOKEN_CLIENT)) {
            $response = new JsonResponse(['message' => 'Too many requests'], 429);
            $response->headers->set('Cache-Control', 'no-store, private');

            return $response;
        }

        $response = new JsonResponse(['token' => StreamToken::forSession($request)]);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    #[Route('/bluebranch/chatbot/api/v1/generate/search',name: 'bluebranch_chatbot_generate_seach', methods: ['POST'], defaults: ['_scope' => 'frontend', '_token_check' => true])]
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

        if ('backend' === $request->attributes->get('_scope') && !$this->isAdmin()) {
            return new JsonResponse(['success' => false, 'message' => 'Access denied'], 403);
        }

        [$payload, $pageModel, $context] = $this->resolveGenerationPayload($request, 'search');

        if (null !== $context['error']) {
            return new JsonResponse(['success' => false, 'message' => $context['error'][1]], $context['error'][0]);
        }

        $result = $this->chatbotApi->generateSearch(array_intersect_key($payload, ['prompt' => true, 'language' => true]), $pageModel);

        // Bewusst ohne Frage, IP und User-Agent: Das waeren personenbezogene Daten der
        // Besucher, die im Anwendungs-Log nichts zu suchen haben.
        $this->logger->debug('Chatbot Search Request', [
            'success' => $result['success'] ?? false,
            'statusCode' => $result['statusCode'] ?? null,
        ]);

        // Nach aussen nur Antwort und Quellen: Fehlertexte der API nennen Tarif und Kontakt des
        // Betreibers, Fehler des HTTP-Clients die interne API-Adresse.
        if (empty($result['success'])) {
            return new JsonResponse(['success' => false, 'message' => 'Die Anfrage konnte nicht beantwortet werden.'], 429 === ($result['statusCode'] ?? 0) ? 429 : 502);
        }

        $sources = [];
        foreach (\array_slice((array) ($result['sources'] ?? []), 0, 10) as $source) {
            if (\is_array($source)) {
                $sources[] = ['title' => (string) ($source['title'] ?? ''), 'url' => (string) ($source['url'] ?? '')];
            }
        }

        return new JsonResponse(['success' => true, 'answer' => (string) ($result['answer'] ?? ''), 'sources' => $sources]);
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

        // Ohne diesen Schritt hielte der Trainingsstand die Seiten weiter fuer trainiert,
        // und sie kaemen erst nach Ablauf von TrainingState::REFRESH_AFTER zurueck.
        if (!empty($result['success'])) {
            $this->resetTrainingStateForRoot($pageModel);

            if (null !== $this->extraContent) {
                $rootId = null;
                if ($pageModel instanceof PageModel) {
                    $pageModel->loadDetails();
                    $rootId = (int) $pageModel->rootId;
                }
                $this->extraContent->markRemovedForRoot($rootId);
            }
        }

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

        $deletedPageId = TrainingState::pageIdFromExternalId($externalId);
        if (null !== $deletedPageId && TrainingState::deleteSucceeded($result)) {
            (new TrainingState())->markRemoved($deletedPageId);
        }

        if (null !== $this->extraContent && TrainingState::deleteSucceeded($result)) {
            $this->extraContent->markRemovedByExternalId($externalId);
        }

        return new JsonResponse($result);
    }

    /**
     * Setzt den Trainingsstand aller Seiten unter der Root-Seite zurueck, deren Schluessel
     * gerade geleert wurde. Ohne Root-Seite galt der globale Schluessel - dann alle Seiten.
     */
    private function resetTrainingStateForRoot(?PageModel $pageModel): void
    {
        $state = new TrainingState();

        if (!$pageModel instanceof PageModel) {
            $state->markUnknown();

            return;
        }

        $pageModel->loadDetails();
        $rootId = (int) $pageModel->rootId;
        $ids = array_map('intval', \Contao\Database::getInstance()->getChildRecords($rootId, 'tl_page'));

        $state->markUnknown($rootId, ...$ids);
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

        [$payload, $pageModel, $context] = $this->resolveGenerationPayload($request, 'search');

        if (null !== ($refusal = $this->refuseAnswer($request, $context))) {
            return $refusal;
        }

        return $this->buildStreamedResponse($this->chatbotApi->streamSearch($payload, $pageModel), $context);
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

        [$payload, $pageModel, $context] = $this->resolveGenerationPayload($request, 'widget');

        if (null !== ($refusal = $this->refuseAnswer($request, $context))) {
            return $refusal;
        }

        return $this->buildStreamedResponse($this->chatbotApi->streamChat($payload, $pageModel), $context);
    }

    private function hasValidStreamToken(Request $request): bool
    {
        // Nur aus dem Header: Ein Token in der URL landete in Access-Logs und im Referer.
        $tokenValue = $request->headers->get('X-CSRF-Token');
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
     * Liest Frage und Seite aus der Anfrage und stellt zusammen, was das Protokoll braucht.
     *
     * Ob Feedback abgefragt wird, entscheidet das Modul, nicht der Browser: Er schickt nur die
     * Modul-ID mit. Die Quelle (Widget, Frage, Suche) folgt aus dem Modultyp.
     *
     * @return array{0: array, 1: ?PageModel, 2: array}
     */
    private function resolveGenerationPayload(Request $request, string $defaultSource): array
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

        $isBackend = 'backend' === $request->attributes->get('_scope');
        $error = null;

        $pageId = (int) ($payload['pageId'] ?? 0);
        $moduleId = (int) ($payload['moduleId'] ?? 0);

        // Seite und Modul nur so, wie der Server sie gerendert hat (RequestSignature). Im Backend
        // waehlt ein Administrator die Seite selbst.
        if (!$isBackend && ($pageId > 0 || $moduleId > 0) && !RequestSignature::verify($pageId, $moduleId, $payload['sig'] ?? null)) {
            $error = [403, 'Bitte laden Sie die Seite neu.'];
            $pageId = 0;
            $moduleId = 0;
        }

        $prompt = \is_string($payload['prompt'] ?? null) ? $payload['prompt'] : '';
        $chatContext = \is_string($payload['chat_context'] ?? null) ? $payload['chat_context'] : '';

        if ('' === trim($prompt)) {
            $error = $error ?? [400, 'Bitte geben Sie eine Frage ein.'];
        } elseif (mb_strlen($prompt) > self::MAX_PROMPT || mb_strlen($chatContext) > self::MAX_CHAT_CONTEXT) {
            $error = $error ?? [413, 'Die Frage ist zu lang.'];
        }

        $payload['prompt'] = $prompt;
        $payload['chat_context'] = $chatContext;
        $payload['language'] = \is_string($payload['language'] ?? null) ? mb_substr($payload['language'], 0, 64) : 'de';

        $pageModel = null;
        if ($pageId > 0) {
            $pageModel = PageModel::findById($pageId);
        }

        $context = [
            'error' => $error,
            'source' => $defaultSource,
            'feedback' => false,
            'pageId' => $pageModel instanceof PageModel ? (int) $pageModel->id : 0,
            'rootId' => 0,
            'language' => \is_string($payload['language'] ?? null) ? $payload['language'] : '',
            // Der Platzhalter nur fuer echte Zusammenfassungen - sonst liesse sich mit
            // `summarize: true` jede Frage am Protokoll vorbei stellen.
            'question' => !empty($payload['summarize']) && $this->isSummarizePrompt($prompt)
                ? ChatLog::SUMMARIZE_PLACEHOLDER
                : $prompt,
        ];

        if ($pageModel instanceof PageModel) {
            $pageModel->loadDetails();
            $context['rootId'] = (int) $pageModel->rootId;
        }

        if ($moduleId > 0) {
            $module = ModuleModel::findByPk($moduleId);

            if ($module instanceof ModuleModel && isset(self::MODULE_SOURCES[$module->type])) {
                $context['source'] = self::MODULE_SOURCES[$module->type];
                $context['feedback'] = ChatLog::feedbackEnabled($module);
            }
        }

        // Testfragen aus dem Backend gehoeren nicht in die Auswertung der Besucherfragen.
        if ('backend' === $request->attributes->get('_scope')) {
            $context['question'] = '';
        }

        // Nur durchreichen, was die API kennt.
        // Nur durchreichen, was die API kennt - der Rest des Rumpfs bleibt hier.
        $payload = array_intersect_key($payload, ['prompt' => true, 'language' => true, 'chat_context' => true]);

        return [$payload, $pageModel, $context];
    }

    /**
     * Ob der Prompt mit einer der Zusammenfassen-Anweisungen der Sprachdateien beginnt.
     */
    private function isSummarizePrompt(string $prompt): bool
    {
        foreach (['de', 'en'] as $language) {
            System::loadLanguageFile('chatbot_widget', $language);

            foreach (['summarizePrompt', 'summarizeFallbackPrompt'] as $key) {
                $text = $GLOBALS['TL_LANG']['chatbot_widget'][$key] ?? '';

                if ('' !== $text && 0 === strncmp($prompt, $text, \strlen($text))) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Gruende, eine Antwort-Anfrage abzuweisen: ungueltige Eingaben, Ratenlimit, im Backend
     * fehlende Admin-Rechte. Antwortet im SSE-Format, damit das Widget die Meldung anzeigt.
     */
    private function refuseAnswer(Request $request, array $context): ?StreamedResponse
    {
        if ('backend' === $request->attributes->get('_scope')) {
            return $this->isAdmin() ? null : $this->sseError(403, 'Access denied');
        }

        if (null !== $context['error']) {
            return $this->sseError($context['error'][0], $context['error'][1]);
        }

        if (null !== $this->rateLimit
            && (!$this->rateLimit->allowClient($request, 'answer', self::LIMIT_ANSWERS_CLIENT)
                || !$this->rateLimit->allowGlobal('answer', self::LIMIT_ANSWERS_GLOBAL))
        ) {
            return $this->sseError(429, 'Zurzeit sind zu viele Anfragen offen. Bitte versuchen Sie es in einer Minute erneut.');
        }

        return null;
    }

    private function sseError(int $status, string $message): StreamedResponse
    {
        return new StreamedResponse(function () use ($status, $message) {
            echo "event: error\n";
            echo 'data: ' . json_encode(['message' => $message, 'status' => $status]) . "\n\n";
            flush();
        }, $status, ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-store']);
    }

    /**
     * Haelt die fertige Antwort fest und gibt dem Browser die Kennung fuer Feedback.
     *
     * Ein Fehler hier darf die Antwort nicht verderben - sie ist beim Besucher bereits angekommen.
     */
    private function recordAnswer(StreamRecorder $recorder, array $context): void
    {
        if (null === $this->chatLog || '' === ($context['question'] ?? '')) {
            return;
        }

        try {
            $ref = $this->chatLog->record([
                'rootId' => $context['rootId'] ?? 0,
                'pageId' => $context['pageId'] ?? 0,
                'source' => $context['source'] ?? '',
                'language' => $context['language'] ?? '',
                'question' => $context['question'],
                'answer' => $recorder->answer(),
                'sources' => $recorder->sources(),
            ], !empty($context['feedback']));
        } catch (\Throwable $e) {
            $this->logger->error('Chatbot: Antwort konnte nicht protokolliert werden: ' . $e->getMessage());

            return;
        }

        if (null !== $ref) {
            echo "event: meta\n";
            echo 'data: ' . json_encode(['ref' => $ref]) . "\n\n";
            flush();
        }
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

    private function buildStreamedResponse($apiResponse, array $context = []): StreamedResponse
    {
        return new StreamedResponse(function () use ($apiResponse, $context) {
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

                $recorder = new StreamRecorder();

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
                        $recorder->feed($content);
                    }
                }

                $recorder->finish();
                $this->recordAnswer($recorder, $context);
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
