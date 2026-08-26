<?php

namespace Bluebranch\Chatbot\Module;

use Bluebranch\Chatbot\classes\ChatbotAPI;
use Contao\Config;
use Contao\CoreBundle\Controller\FrontendModule\AbstractFrontendModuleController;
use Contao\CoreBundle\DependencyInjection\Attribute\AsFrontendModule;
use Contao\FilesModel;
use Contao\ModuleModel;
use Contao\PageModel;
use Contao\StringUtil;
use Contao\System;
use Contao\Template;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

#[AsFrontendModule(category: 'bluebranch_chatbot', template: 'mod_chatbot_widget')]
class ChatbotWidgetController extends AbstractFrontendModuleController
{
    private const POSITIONS = ['bottom-right', 'bottom-left', 'top-right', 'top-left'];

    private ChatbotAPI $chatbotApi;

    public function __construct(ChatbotAPI $chatbotApi = null)
    {
        $this->chatbotApi = $chatbotApi ?? \Contao\System::getContainer()->get(ChatbotAPI::class);
    }

    public function generate()
    {
        $request = \Contao\System::getContainer()->get('request_stack')->getCurrentRequest() ?? new Request();
        $model = new ModuleModel();

        // Try to get the model from the current instance if it was set by legacy Contao
        if (isset($this->objModel) && $this->objModel instanceof ModuleModel) {
            $model = $this->objModel;
        }

        return $this->__invoke($request, $model, 'main')->getContent();
    }

    protected function getResponse(Template $template, ModuleModel $model, Request $request): Response
    {
        System::loadLanguageFile('chatbot_widget');
        $lang = &$GLOBALS['TL_LANG']['chatbot_widget'];

        $pageModel = $this->getPageModel();

        $template->hasApiKey = !empty($this->chatbotApi->getApiKey($pageModel));
        $template->position = \in_array($model->chatbot_widget_position, self::POSITIONS, true) ? $model->chatbot_widget_position : 'bottom-right';
        $template->unstyled = (bool) $model->chatbot_widget_unstyled;

        $color = trim((string) $model->chatbot_widget_color, '# ');
        if ($color === '') {
            $color = trim((string) Config::get('chatbot_default_color'), '# ');
        }
        $template->color = $color !== '' ? '#' . $color : '';
        $template->colorContrast = $color !== '' ? $this->contrastColor($color) : '';

        $template->iconUrl = $this->resolveIconUrl($model->chatbot_widget_icon) ?? $this->resolveIconUrl(Config::get('chatbot_default_icon'));

        $botName = trim((string) $model->chatbot_widget_name);
        if ($botName === '') {
            $botName = trim((string) Config::get('chatbot_default_name'));
        }
        $template->botName = $botName !== '' ? $botName : ($lang['name'] ?? 'Chat');

        $greeting = trim((string) $model->chatbot_widget_greeting);
        if ($greeting === '') {
            $greeting = trim((string) Config::get('chatbot_default_greeting'));
        }
        $template->greeting = $greeting !== '' ? $greeting : ($lang['greeting'] ?? 'Wie kann ich heute helfen?');

        $suggestions = array_values(array_filter(array_map('trim', StringUtil::deserialize($model->chatbot_widget_suggestions, true))));
        if (empty($suggestions)) {
            $suggestions = array_values(array_filter(array_map('trim', StringUtil::deserialize(Config::get('chatbot_default_suggestions'), true))));
        }
        $template->suggestions = $suggestions;

        $template->showSummarize = !$model->chatbot_widget_hide_summarize && !Config::get('chatbot_default_hide_summarize');
        $template->showDisclaimer = !$model->chatbot_widget_hide_disclaimer && !Config::get('chatbot_default_hide_disclaimer');

        $template->labelInputPlaceholder = $lang['inputPlaceholder'] ?? 'Ihre Nachricht …';
        $template->labelSend = $lang['send'] ?? 'Nachricht senden';
        $template->labelClear = $lang['clear'] ?? 'Chat leeren';
        $template->labelClose = $lang['close'] ?? 'Chat schließen';
        $template->labelFontDec = $lang['fontDec'] ?? 'Schrift verkleinern';
        $template->labelFontInc = $lang['fontInc'] ?? 'Schrift vergrößern';

        $template->jsStrings = [
            'summarize' => $lang['summarize'] ?? 'Inhalt zusammenfassen',
            'summarizePrompt' => $lang['summarizePrompt'] ?? 'Fasse ausschließlich den folgenden Seiteninhalt kurz und präzise zusammen. Nutze dafür keine anderen Quellen oder Seiten:',
            'summarizeFallbackPrompt' => $lang['summarizeFallbackPrompt'] ?? 'Bitte fasse den Inhalt dieser Seite kurz zusammen.',
            'noAnswer' => $lang['noAnswer'] ?? 'Entschuldigung, es konnte keine Antwort generiert werden.',
            'requestError' => $lang['requestError'] ?? 'Es ist ein Fehler bei der Anfrage aufgetreten.',
            'source' => $lang['source'] ?? 'Quelle',
        ];

        $template->pageId = $pageModel instanceof PageModel ? $pageModel->id : '';
        $template->language = $pageModel instanceof PageModel ? strtolower($pageModel->language) : 'de';

        // Generate a secure stream token, store in session so the API controller can validate it
        $session = $request->getSession();
        $streamToken = $session->get('_chatbot_stream_token');
        if (!$streamToken) {
            $streamToken = bin2hex(random_bytes(32));
            $session->set('_chatbot_stream_token', $streamToken);
        }
        $template->requestToken = $streamToken;

        return $template->getResponse();
    }

    /**
     * Resolves a fileTree UUID (binary or hex-dashed string) to a public URL of the SVG file.
     */
    private function resolveIconUrl($uuid): ?string
    {
        if (empty($uuid)) {
            return null;
        }

        $file = FilesModel::findByUuid($uuid);

        if (!$file instanceof FilesModel || !file_exists(\Contao\System::getContainer()->getParameter('kernel.project_dir') . '/' . $file->path)) {
            return null;
        }

        return '/' . $file->path;
    }

    /**
     * Picks black or white text depending on the perceived brightness of a hex color,
     * so a freely chosen accent color stays readable.
     */
    private function contrastColor(string $hex): string
    {
        if (\strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            return '#ffffff';
        }

        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));

        // Perceived brightness (YIQ), see https://24ways.org/2010/calculating-color-contrast
        $brightness = (($r * 299) + ($g * 587) + ($b * 114)) / 1000;

        return $brightness > 150 ? '#1a1a1a' : '#ffffff';
    }
}
