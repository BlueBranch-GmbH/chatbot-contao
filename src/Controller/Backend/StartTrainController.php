<?php

namespace Bluebranch\Chatbot\Controller\Backend;

use Bluebranch\Chatbot\classes\PageEligibility;
use Bluebranch\Chatbot\classes\PageFetcher;
use Bluebranch\Chatbot\classes\TrainingState;
use Contao\BackendUser;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\Database;
use Contao\PageModel;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Annotation\Route;

/**
 * „Jetzt trainieren“ im Assistenten: ruft die Seiten der Website der Reihe nach ab, damit Contao sie indexiert
 * und der Hook sie an den Chatbot uebergibt.
 *
 * Arbeitet in Portionen: Jeder Aufruf holt, was in rund zehn Sekunden schafft, und meldet den Stand. Die Liste
 * der Seiten entsteht einmal und liegt unter einer zufaelligen Lauf-ID im Cache, damit nicht jeder Aufruf alle
 * Seiten erneut prueft. Zugriff nur fuer Administratoren mit dem Sitzungs-Token (wie die Loesch-Routen).
 */
#[AsController]
class StartTrainController extends AbstractController
{
    /** Spielraum je Aufruf: Sekunden Rechenzeit und Hoechstzahl an Seiten. */
    private const BUDGET_SECONDS = 10;
    private const MAX_PER_CALL = 8;
    private const RUN_TTL = 3600;

    private ContaoFramework $framework;
    private CacheItemPoolInterface $cache;
    private PageFetcher $fetcher;
    private PageEligibility $eligibility;

    public function __construct(ContaoFramework $framework, CacheItemPoolInterface $cache, PageFetcher $fetcher, PageEligibility $eligibility)
    {
        $this->framework = $framework;
        $this->cache = $cache;
        $this->fetcher = $fetcher;
        $this->eligibility = $eligibility;
    }

    #[Route('/%contao.backend.route_prefix%/chatbot/start/train', name: 'bluebranch_chatbot_start_train', methods: ['POST'], defaults: ['_scope' => 'backend', '_token_check' => false])]
    public function train(Request $request): JsonResponse
    {
        $this->framework->initialize();

        $user = BackendUser::getInstance();

        if (!$user instanceof BackendUser || !$user->isAdmin) {
            return new JsonResponse(['success' => false, 'message' => 'Access denied'], 403);
        }

        $expected = $request->getSession()->get('_chatbot_stream_token');
        $given = (string) $request->headers->get('X-Stream-Token');

        if (!$expected || !hash_equals((string) $expected, $given)) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid token'], 403);
        }

        $payload = json_decode((string) $request->getContent(), true);
        $payload = \is_array($payload) ? $payload : [];

        $runId = \is_string($payload['runId'] ?? null) ? $payload['runId'] : '';

        if (1 !== preg_match('/^[a-f0-9]{16}$/', $runId)) {
            $root = PageModel::findById((int) ($payload['pageId'] ?? 0));

            if (!$root instanceof PageModel || 'root' !== $root->type) {
                return new JsonResponse(['success' => false, 'message' => 'Unknown website'], 400);
            }

            $runId = bin2hex(random_bytes(8));
            $ids = $this->pageIds((int) $root->id);

            // „Alles neu trainieren“: Den gemerkten Stand verwerfen, sonst uebertraegt der Hook nur Geaendertes.
            if (!empty($payload['reset']) && [] !== $ids) {
                (new TrainingState())->markUnknown(...$ids);
            }

            $run = ['ids' => $ids, 'pos' => 0, 'ok' => 0, 'failed' => 0, 'lastError' => ''];
        } else {
            $item = $this->cache->getItem('bluebranch_chatbot_train_' . $runId);

            if (!$item->isHit()) {
                return new JsonResponse(['success' => false, 'message' => 'Run expired'], 410);
            }

            $run = $item->get();
        }

        $started = microtime(true);
        $handled = 0;

        while ($run['pos'] < \count($run['ids']) && $handled < self::MAX_PER_CALL && (microtime(true) - $started) < self::BUDGET_SECONDS) {
            $result = $this->fetcher->fetch((int) $run['ids'][$run['pos']]);
            $run[$result['ok'] ? 'ok' : 'failed']++;

            if (!$result['ok']) {
                $run['lastError'] = $result['message'];
            }

            ++$run['pos'];
            ++$handled;
        }

        $item = $this->cache->getItem('bluebranch_chatbot_train_' . $runId);
        $item->set($run);
        $item->expiresAfter(self::RUN_TTL);
        $this->cache->save($item);

        $response = new JsonResponse([
            'success' => true,
            'runId' => $runId,
            'total' => \count($run['ids']),
            'pos' => $run['pos'],
            'ok' => $run['ok'],
            'failed' => $run['failed'],
            'lastError' => $run['lastError'],
            'done' => $run['pos'] >= \count($run['ids']),
        ]);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    /**
     * Die Seiten der Website, die in den KI-Index gehoeren (veroeffentlicht, nicht ausgeschlossen).
     *
     * @return int[]
     */
    private function pageIds(int $rootId): array
    {
        $children = array_map('intval', Database::getInstance()->getChildRecords([$rootId], 'tl_page'));
        $ids = [];

        foreach ($children as $id) {
            $page = PageModel::findById($id);

            if ($page instanceof PageModel && \in_array($page->type, ['regular', 'forward'], true) && $this->eligibility->isEligible($id)) {
                $ids[] = $id;
            }
        }

        sort($ids);

        return $ids;
    }
}
