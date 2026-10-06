<?php

namespace Bluebranch\Chatbot\Controller\Backend;

use Bluebranch\Chatbot\classes\ChatLog;
use Contao\BackendUser;
use Contao\CoreBundle\Controller\AbstractBackendController;
use Contao\CoreBundle\Exception\RedirectResponseException;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\System;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Fragen, Antworten und Feedback der Besucher: Liste mit Filtern, Loeschen und CSV-Export.
 *
 * Eine eigene Ansicht statt einer DCA-Liste: In den Spalten steht, was Besucher eingetippt
 * haben. Twig escaped jede Ausgabe; Contaos Detailansicht gaebe die Textfelder ungefiltert aus.
 */
#[AsController]
class ChatLogController extends AbstractBackendController
{
    private const PER_PAGE = 50;
    private const RATINGS = ['up', 'down', 'none', 'any'];

    private ContaoFramework $framework;
    private Connection $connection;

    public function __construct(?ContaoFramework $framework = null, ?Connection $connection = null)
    {
        $container = System::getContainer();

        $this->framework = $framework ?? $container->get('contao.framework');
        $this->connection = $connection ?? $container->get('database_connection');

        if (!isset($this->container)) {
            $this->setContainer($container);
        }
    }

    #[Route('/%contao.backend.route_prefix%/chatbot/log', name: self::class, defaults: ['_scope' => 'backend', '_token_check' => true])]
    public function generate(?Request $request = null)
    {
        $isLegacy = (null === $request);

        if (null === $request) {
            $request = $this->container->get('request_stack')->getCurrentRequest() ?? new Request();
        }

        $this->framework->initialize();

        if (!$this->hasAccess()) {
            $response = new Response('<div style="padding: 20px;"><p style="color: #d9534f; font-weight: bold;">Zugriff verweigert.</p></div>', 403);

            return $isLegacy ? $response->getContent() : $response;
        }

        if ($request->isMethod('POST')) {
            $this->handleDelete($request);

            // Nach dem Loeschen neu laden, damit ein Aktualisieren der Seite nichts erneut abschickt.
            throw new RedirectResponseException($request->getUri(), 303);
        }

        $filter = $this->readFilter($request);
        [$where, $params] = $this->buildWhere($filter);

        $total = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_chatbot_log' . $where, $params);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, $request->query->getInt('page', 1)), $pages);

        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM tl_chatbot_log' . $where . ' ORDER BY tstamp DESC, id DESC LIMIT ' . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE),
            $params
        );

        $stats = $this->connection->fetchAssociative(
            "SELECT COUNT(*) AS total, SUM(rating='up') AS up, SUM(rating='down') AS down FROM tl_chatbot_log"
        ) ?: [];

        $pageTitles = $this->pageTitles(array_merge(array_column($rows, 'page_id'), array_column($rows, 'root_id')));

        foreach ($rows as &$row) {
            $row['sources'] = json_decode((string) $row['sources'], true) ?: [];
            $row['page_title'] = $pageTitles[(int) $row['page_id']] ?? '';
            $row['root_title'] = $pageTitles[(int) $row['root_id']] ?? '';
        }
        unset($row);

        $baseQuery = array_filter([
            'do' => $request->query->get('do'),
            'rating' => 'any' !== $filter['rating'] ? $filter['rating'] : null,
            'source' => $filter['source'] ?: null,
            'root' => $filter['root'] ?: null,
            'q' => '' !== $filter['q'] ? $filter['q'] : null,
        ]);

        $response = $this->render('@Chatbot/Backend/chat_log.html.twig', [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'filter' => $filter,
            'baseQuery' => $baseQuery,
            'stats' => [
                'total' => (int) ($stats['total'] ?? 0),
                'up' => (int) ($stats['up'] ?? 0),
                'down' => (int) ($stats['down'] ?? 0),
            ],
            'roots' => $this->rootPages(),
            'sources' => ChatLog::SOURCES,
            'logEnabled' => ChatLog::isEnabled(),
            'requestToken' => $this->requestToken(),
            'exportQuery' => array_diff_key($baseQuery, ['do' => true]),
        ]);

        return $isLegacy ? $response->getContent() : $response;
    }

    /**
     * CSV mit den aktuell gewaehlten Filtern. Semikolon und BOM, damit Excel die Datei ohne
     * Importdialog richtig oeffnet.
     */
    #[Route('/%contao.backend.route_prefix%/chatbot/log/export', name: 'bluebranch_chatbot_log_export', methods: ['GET'], defaults: ['_scope' => 'backend', '_token_check' => true])]
    public function export(Request $request): Response
    {
        $this->framework->initialize();

        if (!$this->hasAccess()) {
            return new Response('Zugriff verweigert.', 403);
        }

        [$where, $params] = $this->buildWhere($this->readFilter($request));
        $connection = $this->connection;

        $response = new StreamedResponse(function () use ($connection, $where, $params) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['ID', 'Datum', 'Website', 'Seite', 'Quelle', 'Sprache', 'Frage', 'Antwort', 'Quellen', 'Bewertung', 'Kommentar', 'Feedback am'], ';', '"', '');

            $result = $connection->executeQuery('SELECT * FROM tl_chatbot_log' . $where . ' ORDER BY tstamp DESC, id DESC', $params);
            $titles = [];

            while (false !== ($row = $result->fetchAssociative())) {
                foreach (['page_id', 'root_id'] as $key) {
                    $id = (int) $row[$key];
                    if ($id > 0 && !isset($titles[$id])) {
                        $titles[$id] = (string) $connection->fetchOne('SELECT title FROM tl_page WHERE id=?', [$id]);
                    }
                }

                $sources = array_map(
                    static fn ($source) => trim(($source['title'] ?? '') . ' ' . ($source['url'] ?? '')),
                    json_decode((string) $row['sources'], true) ?: []
                );

                fputcsv($out, array_map([self::class, 'csvCell'], [
                    $row['id'],
                    date('Y-m-d H:i:s', (int) $row['tstamp']),
                    $titles[(int) $row['root_id']] ?? '',
                    $titles[(int) $row['page_id']] ?? '',
                    $row['source'],
                    $row['language'],
                    $row['question'],
                    $row['answer'],
                    implode(' | ', $sources),
                    $row['rating'],
                    $row['comment'],
                    $row['feedback_at'] ? date('Y-m-d H:i:s', (int) $row['feedback_at']) : '',
                ]), ';', '"', '');
            }

            fclose($out);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="chatbot-fragen-' . date('Y-m-d') . '.csv"');
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    /**
     * Entschaerft Zellen, die Excel und LibreOffice als Formel lesen wuerden. Die Fragen stammen
     * von Besuchern - eine Frage „=HYPERLINK(…)“ waere sonst ein Link im Tabellenblatt.
     */
    public static function csvCell($value): string
    {
        // Jede Zeile einzeln: Manche Programme werten in mehrzeiligen Zellen auch Folgezeilen aus.
        // Das Escape-Zeichen von fputcsv ist leer (siehe Aufruf) - mit dem Standard `\` liesse sich
        // aus einer Zelle ausbrechen (`\";=1+1`), weil Excel kein Backslash-Escaping kennt.
        return (string) preg_replace('/^(?=[=+\-@\t\r])/m', "'", (string) $value);
    }

    public static function getSubscribedServices(): array
    {
        return array_merge(parent::getSubscribedServices(), [
            'request_stack' => \Symfony\Component\HttpFoundation\RequestStack::class,
        ]);
    }

    private function hasAccess(): bool
    {
        $user = BackendUser::getInstance();

        return $user instanceof BackendUser && ($user->isAdmin || $user->hasAccess('chatbot_log', 'modules'));
    }

    private function handleDelete(Request $request): void
    {
        if ($request->request->get('delete_all')) {
            $this->connection->executeStatement('DELETE FROM tl_chatbot_log');

            return;
        }

        $ids = array_values(array_filter(array_map('intval', (array) ($request->request->all()['ids'] ?? []))));

        if ([] !== $ids) {
            $this->connection->executeStatement(
                'DELETE FROM tl_chatbot_log WHERE id IN (' . implode(',', $ids) . ')'
            );
        }
    }

    /** @return array{rating: string, source: string, root: int, q: string} */
    private function readFilter(Request $request): array
    {
        $rating = (string) $request->query->get('rating', 'any');
        $source = (string) $request->query->get('source', '');

        return [
            'rating' => \in_array($rating, self::RATINGS, true) ? $rating : 'any',
            'source' => \in_array($source, ChatLog::SOURCES, true) ? $source : '',
            'root' => max(0, $request->query->getInt('root')),
            'q' => mb_substr(trim((string) $request->query->get('q', '')), 0, 200),
        ];
    }

    /** @return array{0: string, 1: array} */
    private function buildWhere(array $filter): array
    {
        $conditions = [];
        $params = [];

        if ('up' === $filter['rating'] || 'down' === $filter['rating']) {
            $conditions[] = 'rating=?';
            $params[] = $filter['rating'];
        } elseif ('none' === $filter['rating']) {
            $conditions[] = "rating=''";
        }

        if ('' !== $filter['source']) {
            $conditions[] = 'source=?';
            $params[] = $filter['source'];
        }

        if ($filter['root'] > 0) {
            $conditions[] = 'root_id=?';
            $params[] = $filter['root'];
        }

        if ('' !== $filter['q']) {
            $like = '%' . addcslashes($filter['q'], '%_\\') . '%';
            $conditions[] = '(question LIKE ? OR answer LIKE ? OR comment LIKE ?)';
            array_push($params, $like, $like, $like);
        }

        return [[] === $conditions ? '' : ' WHERE ' . implode(' AND ', $conditions), $params];
    }

    private function pageTitles(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        if ([] === $ids) {
            return [];
        }

        $titles = [];

        foreach ($this->connection->fetchAllAssociative('SELECT id, title FROM tl_page WHERE id IN (' . implode(',', $ids) . ')') as $row) {
            $titles[(int) $row['id']] = (string) $row['title'];
        }

        return $titles;
    }

    private function rootPages(): array
    {
        return $this->connection->fetchAllAssociative("SELECT id, title, dns FROM tl_page WHERE type='root' ORDER BY sorting");
    }

    private function requestToken(): string
    {
        $container = System::getContainer();

        return $container->get('contao.csrf.token_manager')
            ->getToken($container->getParameter('contao.csrf_token_name'))
            ->getValue();
    }
}
