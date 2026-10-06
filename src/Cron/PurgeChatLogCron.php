<?php

namespace Bluebranch\Chatbot\Cron;

use Bluebranch\Chatbot\classes\ChatLog;
use Contao\CoreBundle\DependencyInjection\Attribute\AsCronJob;
use Psr\Log\LoggerInterface;

/**
 * Loescht Fragen und Antworten nach Ablauf der eingestellten Aufbewahrungsdauer.
 */
#[AsCronJob('daily')]
class PurgeChatLogCron
{
    private ChatLog $chatLog;
    private LoggerInterface $logger;

    public function __construct(ChatLog $chatLog, LoggerInterface $logger)
    {
        $this->chatLog = $chatLog;
        $this->logger = $logger;
    }

    public function __invoke(): void
    {
        try {
            $deleted = $this->chatLog->purgeExpired();

            if ($deleted > 0) {
                $this->logger->info(sprintf('Chatbot: %d gespeicherte Frage(n) nach Ablauf der Aufbewahrung gelöscht.', $deleted));
            }
        } catch (\Throwable $e) {
            $this->logger->error('Chatbot: Bereinigung der gespeicherten Fragen fehlgeschlagen: ' . $e->getMessage());
        }
    }
}
