<?php

namespace Bluebranch\Chatbot\EventListener;

use Bluebranch\Chatbot\classes\GlobalWidget;
use Bluebranch\Chatbot\Module\ChatbotWidgetController;
use Contao\CoreBundle\DependencyInjection\Attribute\AsHook;
use Contao\ModuleModel;
use Contao\PageModel;
use Contao\System;
use Psr\Log\LoggerInterface;

/**
 * Setzt das Chat-Widget in jede Seite ein, wenn „Chat auf allen Seiten anzeigen“ gilt.
 *
 * Das Ergebnis steckt wie jedes andere Modul im Seiten-HTML und ist damit cachebar: Das Widget
 * braucht keine Sitzung, die Signatur gilt je Seite und Modul.
 */
#[AsHook('modifyFrontendPage')]
class GlobalWidgetListener
{
    private ChatbotWidgetController $widget;
    private LoggerInterface $logger;

    public function __construct(ChatbotWidgetController $widget, LoggerInterface $logger)
    {
        $this->widget = $widget;
        $this->logger = $logger;
    }

    public function __invoke(string $buffer, string $templateName): string
    {
        if (!str_starts_with($templateName, 'fe_page')) {
            return $buffer;
        }

        $moduleId = GlobalWidget::moduleId();

        if ($moduleId <= 0) {
            return $buffer;
        }

        global $objPage;

        // Nur normale Seiten: Fehlerseiten, Weiterleitungen und Ajax-Schnipsel bleiben ohne Chat.
        if (!$objPage instanceof PageModel || 'regular' !== $objPage->type || '1' === (string) $objPage->chatbot_hide_widget) {
            return $buffer;
        }

        // Steht dasselbe Modul schon im Layout oder in einem Artikel, nicht noch einmal einsetzen.
        if (str_contains($buffer, 'id="chatbot-widget-' . $moduleId . '"')) {
            return $buffer;
        }

        $position = strripos($buffer, '</body>');
        $request = System::getContainer()->get('request_stack')->getCurrentRequest();
        $module = ModuleModel::findByPk($moduleId);

        if (false === $position || null === $request || !$module instanceof ModuleModel) {
            return $buffer;
        }

        try {
            $html = $this->widget->__invoke($request, $module, 'main')->getContent();
        } catch (\Throwable $e) {
            // Ein Fehler im Widget darf die Seite nicht verderben.
            $this->logger->error('Chatbot: Widget fuer „alle Seiten“ konnte nicht erzeugt werden: ' . $e->getMessage());

            return $buffer;
        }

        return substr($buffer, 0, $position) . $html . "\n" . substr($buffer, $position);
    }
}
