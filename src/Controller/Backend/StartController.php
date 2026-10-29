<?php

namespace Bluebranch\Chatbot\Controller\Backend;

use Bluebranch\Chatbot\classes\Asset;
use Bluebranch\Chatbot\classes\ChatbotAPI;
use Bluebranch\Chatbot\classes\GlobalWidget;
use Bluebranch\Chatbot\classes\StreamToken;
use Contao\BackendUser;
use Contao\Config;
use Contao\CoreBundle\Controller\AbstractBackendController;
use Contao\CoreBundle\Exception\RedirectResponseException;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\Database;
use Contao\ModuleModel;
use Contao\PageModel;
use Contao\System;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Csrf\CsrfToken;

/**
 * „Erste Schritte“: fuehrt Schritt fuer Schritt vom frisch installierten Paket zum laufenden Chat.
 *
 *  1. Zugang verbinden   - API-Key eintragen, Verbindung pruefen
 *  2. Inhalte trainieren - Suchindex neu aufbauen, Stand ansehen
 *  3. Chat anzeigen      - auf allen Seiten (ein Klick) oder selbst platziert
 *  4. Ausprobieren       - Testfrage direkt im Backend
 *  5. Feinschliff        - Voreinstellungen fuer Sprache und Feedback
 *
 * Pflicht sind die Schritte 1 bis 3; die Seite zeigt, welcher als Naechstes dran ist. Der Key wird an
 * der Startseite des Seitenbaums gespeichert - wie bisher, nur ohne dass man ihn dort suchen muss.
 */
#[AsController]
class StartController extends AbstractBackendController
{
    private const SESSION_PAGE = 'chatbot_selected_page_id';

    private ChatbotAPI $chatbotApi;
    private ContaoFramework $framework;

    public function __construct(?ChatbotAPI $chatbotApi = null, ?ContaoFramework $framework = null)
    {
        $container = System::getContainer();

        $this->chatbotApi = $chatbotApi ?? $container->get(ChatbotAPI::class);
        $this->framework = $framework ?? $container->get('contao.framework');

        // isset() statt null-Pruefung: Ab Symfony 6 ist die Eigenschaft typisiert, und ein Lesen
        // vor setContainer() waere ein fataler Fehler (siehe TrainedContentController).
        if (!isset($this->container)) {
            $this->setContainer($container);
        }
    }

    #[Route('/%contao.backend.route_prefix%/chatbot/start', name: self::class, defaults: ['_scope' => 'backend', '_token_check' => true])]
    public function generate(?Request $request = null)
    {
        $isLegacy = null === $request;

        if (null === $request) {
            $request = $this->container->get('request_stack')->getCurrentRequest() ?? new Request();
        }

        $this->framework->initialize();

        $user = BackendUser::getInstance();

        if (!$user instanceof BackendUser || !$user->isAdmin) {
            $response = new Response('<div style="padding: 20px;"><p style="color: #d9534f; font-weight: bold;">Zugriff verweigert.</p><p>Diese Seite ist nur für Administratoren zugänglich.</p></div>', 403);

            return $isLegacy ? $response->getContent() : $response;
        }

        $roots = $this->rootPages();

        if ([] === $roots) {
            $response = new Response('<div style="padding: 20px;"><p style="color: #d9534f; font-weight: bold;">Es gibt noch keine Startseite.</p><p>Legen Sie zuerst im Seitenbaum eine Website-Startseite an. Danach geht es hier weiter.</p></div>');

            return $isLegacy ? $response->getContent() : $response;
        }

        $session = $request->getSession();
        $wanted = $request->query->get('pageId') ?? $request->request->get('pageId') ?? $session->get(self::SESSION_PAGE);
        $root = $roots[0];

        foreach ($roots as $candidate) {
            if ((string) $candidate->id === (string) $wanted) {
                $root = $candidate;
                break;
            }
        }

        $session->set(self::SESSION_PAGE, $root->id);

        if ($request->isMethod('POST')) {
            $notice = $this->handlePost($request, $root);

            throw new RedirectResponseException($this->selfUrl((int) $root->id, $notice), 303);
        }

        $response = $this->render('@Chatbot/Backend/start.html.twig', $this->buildView($request, $roots, $root));

        return $isLegacy ? $response->getContent() : $response;
    }

    /**
     * @param list<PageModel> $roots
     */
    private function buildView(Request $request, array $roots, PageModel $root): array
    {
        $rootId = (int) $root->id;
        $hasKey = '' !== (string) $root->chatbot_api_key;

        // Verbindung: ein Aufruf bei jedem Laden der Seite - der Besucher soll sehen, ob es gerade geht.
        $tier = null;
        $connectionError = '';

        if ($hasKey) {
            $result = $this->chatbotApi->getTier($root);

            if (!empty($result['success']) && isset($result['tier'])) {
                $tier = $result;
            } else {
                $connectionError = 401 === ($result['statusCode'] ?? 0)
                    ? 'Die API hat den Key abgelehnt.'
                    : 'Die API ist gerade nicht erreichbar oder antwortet nicht.';
            }
        }

        $db = Database::getInstance();
        $ids = array_map('intval', $db->getChildRecords([$rootId], 'tl_page'));
        $trainedPages = 0;

        if ([] !== $ids) {
            $trainedPages = (int) $db->query(
                "SELECT COUNT(*) AS c FROM tl_page WHERE id IN (" . implode(',', $ids) . ") AND chatbot_checksum IS NOT NULL AND chatbot_checksum != ''"
            )->c;
        }

        $extraContent = (int) $db->query("SELECT COUNT(*) AS c FROM tl_chatbot_content WHERE published='1' AND status IN ('trained','truncated')")->c;

        $globalId = GlobalWidget::moduleId();
        $globalModule = $globalId > 0 ? ModuleModel::findByPk($globalId) : null;

        $modules = [
            'widget' => (int) $db->query("SELECT COUNT(*) AS c FROM tl_module WHERE type='chatbot_widget'")->c,
            'ask' => (int) $db->query("SELECT COUNT(*) AS c FROM tl_module WHERE type='chatbot_ask'")->c,
            'search' => (int) $db->query("SELECT COUNT(*) AS c FROM tl_module WHERE type='chatbot_generate_search'")->c,
        ];

        $connected = null !== $tier;
        $steps = [
            'connect' => $connected,
            // Ohne Verbindung zaehlen die weiteren Schritte nicht: Ein Stand von frueher waere sonst
            // ein „2 von 3“ fuer jemanden, der noch gar keinen Key hat.
            'train' => $connected && ($trainedPages > 0 || $extraContent > 0),
            'show' => $connected && ($globalId > 0 || array_sum($modules) > 0),
        ];

        $container = System::getContainer();
        $router = $container->get('router');

        $siteUrl = '' !== (string) $root->dns
            ? ($root->useSSL ? 'https://' : 'http://') . $root->dns . '/'
            : $request->getSchemeAndHttpHost() . '/';

        return [
            'roots' => array_map(static fn (PageModel $r): array => ['id' => (int) $r->id, 'title' => $r->title . ($r->dns ? ' (' . $r->dns . ')' : '')], $roots),
            'root' => ['id' => $rootId, 'title' => $root->title, 'language' => strtolower((string) $root->language) ?: 'de'],
            'hasKey' => $hasKey,
            'tier' => $tier,
            'connected' => $connected,
            'connectionError' => $connectionError,
            'trainedPages' => $trainedPages,
            'extraContent' => $extraContent,
            'globalOn' => $globalId > 0,
            'globalModule' => $globalModule instanceof ModuleModel ? ['id' => (int) $globalModule->id, 'name' => $globalModule->name] : null,
            'modules' => $modules,
            'steps' => $steps,
            'doneCount' => \count(array_filter($steps)),
            'voiceDefault' => (bool) Config::get('chatbot_voice_default'),
            'feedbackDefault' => (bool) Config::get('chatbot_feedback_default'),
            'notice' => $this->noticeFor((string) $request->query->get('done'), $tier),
            'requestToken' => $this->requestToken(),
            'streamToken' => StreamToken::forSession($request),
            'streamUrl' => $router->generate('bluebranch_chatbot_chat_stream_be'),
            'siteUrl' => $siteUrl,
            'assets' => [
                'marked' => Asset::url('js/marked.min.js'),
                'common' => Asset::url('js/chatbot-common.js'),
            ],
        ];
    }

    /**
     * Fuehrt die Aktion eines der Formulare aus.
     *
     * @return string Kennung fuer die Rueckmeldung nach der Weiterleitung
     */
    private function handlePost(Request $request, PageModel $root): string
    {
        $container = System::getContainer();
        $token = new CsrfToken($container->getParameter('contao.csrf_token_name'), (string) $request->request->get('REQUEST_TOKEN'));

        if (!$container->get('contao.csrf.token_manager')->isTokenValid($token)) {
            return 'token';
        }

        switch ((string) $request->request->get('action')) {
            case 'save_key':
                return $this->saveKey($root, trim((string) $request->request->get('api_key')));

            case 'widget_on':
                GlobalWidget::enable();

                return 'widget_on';

            case 'widget_off':
                GlobalWidget::disable();

                return 'widget_off';

            case 'defaults':
                GlobalWidget::store($request->request->get('voice') ? '1' : '', 'chatbot_voice_default');
                GlobalWidget::store($request->request->get('feedback') ? '1' : '', 'chatbot_feedback_default');

                return 'defaults';
        }

        return '';
    }

    /**
     * Speichert den Key an der Startseite und prueft ihn sofort. Lehnt die API ihn ab, bleibt der
     * bisherige Key bestehen.
     */
    private function saveKey(PageModel $root, string $key): string
    {
        if (1 !== preg_match('/^[A-Za-z0-9._\-]{16,200}$/', $key)) {
            return 'key_format';
        }

        $old = (string) $root->chatbot_api_key;
        $rootId = (int) $root->id;
        $accepted = false;

        // Erst speichern, dann pruefen: Die API-Klasse liest den Key aus der Startseite.
        $root->chatbot_api_key = $key;
        $root->save();

        try {
            $result = $this->chatbotApi->getTier($root);
            $accepted = !empty($result['success']) && isset($result['tier']);
        } finally {
            if (!$accepted) {
                // Direkt in die Datenbank: Nach getApiKey()/loadDetails() sperrt Contao das Speichern
                // dieses Modells ("detached"), ein save() wuerfe hier eine Ausnahme.
                Database::getInstance()
                    ->prepare('UPDATE tl_page SET chatbot_api_key=? WHERE id=?')
                    ->execute($old, $rootId);
                $root->chatbot_api_key = $old;
            }
        }

        return $accepted ? 'key_ok' : 'key_rejected';
    }

    private function noticeFor(string $code, ?array $tier): ?array
    {
        $texts = [
            'key_ok' => ['ok', 'Verbunden. Der Key ist gespeichert.'],
            'key_rejected' => ['error', 'Die API hat den Key abgelehnt. Bitte prüfen Sie, ob er vollständig kopiert ist. Ein bisheriger Key bleibt erhalten.'],
            'key_format' => ['error', 'Der Key sieht nicht richtig aus. Erlaubt sind Buchstaben, Ziffern sowie Punkt, Unterstrich und Bindestrich.'],
            'widget_on' => ['ok', 'Der Chat erscheint jetzt auf allen Seiten. Falls er auf der Website noch fehlt: Seitencache leeren (System → Wartung).'],
            'widget_off' => ['ok', 'Der Chat wird nicht mehr automatisch eingesetzt. Module, die Sie selbst platziert haben, bleiben unberührt.'],
            'defaults' => ['ok', 'Die Voreinstellungen sind gespeichert.'],
            'token' => ['error', 'Die Anfrage war abgelaufen. Bitte versuchen Sie es noch einmal.'],
        ];

        if (!isset($texts[$code])) {
            return null;
        }

        return ['type' => $texts[$code][0], 'text' => $texts[$code][1]];
    }

    /**
     * @return list<PageModel>
     */
    private function rootPages(): array
    {
        $pages = PageModel::findBy(['type=?'], ['root'], ['order' => 'sorting']);

        return null === $pages ? [] : array_values(iterator_to_array($pages));
    }

    private function selfUrl(int $rootId, string $notice): string
    {
        $router = System::getContainer()->get('router');
        $params = ['do' => 'chatbot_start', 'pageId' => $rootId];

        if ('' !== $notice) {
            $params['done'] = $notice;
        }

        return $router->generate('contao_backend', $params);
    }

    private function requestToken(): string
    {
        $container = System::getContainer();

        return $container->get('contao.csrf.token_manager')
            ->getToken($container->getParameter('contao.csrf_token_name'))
            ->getValue();
    }

    public static function getSubscribedServices(): array
    {
        return array_merge(parent::getSubscribedServices(), [
            'contao.csrf.token_manager' => \Contao\CoreBundle\Csrf\ContaoCsrfTokenManager::class,
            'parameter_bag' => \Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface::class,
            'request_stack' => \Symfony\Component\HttpFoundation\RequestStack::class,
        ]);
    }
}
