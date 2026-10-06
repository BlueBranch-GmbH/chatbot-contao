<?php

namespace Bluebranch\Chatbot\EventListener;

use Bluebranch\Chatbot\classes\ExtraContent;
use Contao\Backend;
use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\CoreBundle\Exception\RedirectResponseException;
use Contao\DataContainer;
use Contao\Input;
use Contao\Message;
use Contao\PageModel;
use Contao\StringUtil;
use Contao\System;

/**
 * Verbindet die Backend-Maske der Zusatzinhalte mit dem KI-Index.
 */
class ExtraContentListener
{
    private ExtraContent $extraContent;

    public function __construct(ExtraContent $extraContent)
    {
        $this->extraContent = $extraContent;
    }

    /**
     * Speichern und Sichtbarkeit umschalten laufen beide hier durch (DC_Table::toggle() ruft
     * submit() auf).
     */
    #[AsCallback(table: 'tl_chatbot_content', target: 'config.onsubmit')]
    public function onSubmit(DataContainer $dc): void
    {
        if (!$dc->id) {
            return;
        }

        /*
         * Ein Wechsel der Art (submitOnChange) schickt das Formular automatisch ab, um die
         * Palette neu aufzubauen. Das ist kein Speichern im Sinne des Redakteurs - die Datei ist
         * da noch gar nicht gewaehlt, und uebertragen wuerde ein halb ausgefuellter Eintrag.
         */
        if ('auto' === Input::post('SUBMIT_TYPE')) {
            return;
        }

        $status = $this->extraContent->sync((int) $dc->id);

        if ('übertragen' === $status || 'unverändert' === $status || 'nicht veröffentlicht' === $status) {
            Message::addConfirmation('Chatbot: ' . $status . '.');
        } elseif ('' !== $status) {
            Message::addError('Chatbot: ' . StringUtil::specialchars($status));
        }
    }

    #[AsCallback(table: 'tl_chatbot_content', target: 'config.ondelete')]
    public function onDelete(DataContainer $dc): void
    {
        if ($dc->id) {
            $this->extraContent->removeById((int) $dc->id);
        }
    }

    /**
     * Contao schreibt den Undo-Datensatz vor dem Loeschen - samt dem Trainingsstand von damals.
     * Nach dem Wiederherstellen stimmt der nicht mehr.
     */
    #[AsCallback(table: 'tl_chatbot_content', target: 'config.onundo')]
    public function onUndo(string $table, array $row): void
    {
        if (!empty($row['id'])) {
            $this->extraContent->resync((int) $row['id']);
        }
    }

    /**
     * Eine alte Version zurueckgespielt: Text oder Datei haben sich geaendert, ohne dass
     * gespeichert wurde.
     */
    #[AsCallback(table: 'tl_chatbot_content', target: 'config.onrestore_version')]
    public function onRestoreVersion(string $table, $id): void
    {
        $this->extraContent->resync((int) $id);
    }

    #[AsCallback(table: 'tl_chatbot_content', target: 'fields.root.options')]
    public function rootOptions(): array
    {
        $options = [];
        $roots = PageModel::findBy('type', 'root', ['order' => 'sorting']);

        foreach ($roots ?? [] as $root) {
            $options[$root->id] = $root->title . ($root->dns ? ' (' . $root->dns . ')' : '') . ($root->chatbot_api_key ? '' : ' – ohne API-Key');
        }

        return $options;
    }

    #[AsCallback(table: 'tl_chatbot_content', target: 'list.label.label')]
    public function label(array $row): string
    {
        System::loadLanguageFile('tl_chatbot_content');
        $lang = $GLOBALS['TL_LANG']['tl_chatbot_content'] ?? [];

        $type = $lang['type_options'][$row['type']] ?? $row['type'];
        $status = $lang['status_options'][$row['status']] ?? $row['status'];

        $label = '<strong>' . $row['title'] . '</strong> <span style="color:#999">[' . StringUtil::specialchars($type) . ']</span>';

        if ('' !== $row['status']) {
            $color = \in_array($row['status'], ['trained', 'truncated'], true) ? '#2e7d32' : ('error' === $row['status'] ? '#c62828' : '#999');
            $label .= ' <span style="color:' . $color . '">' . StringUtil::specialchars($status);

            if ($row['chars'] > 0 && 'removed' !== $row['status']) {
                $label .= ' · ' . number_format((int) $row['chars'], 0, ',', '.') . ' Zeichen';
            }

            if ('' !== $row['error']) {
                $label .= ' · ' . StringUtil::specialchars($row['error']);
            }

            $label .= '</span>';
        }

        return $label;
    }

    /**
     * Globale Operation „Alle neu übertragen“ (BE_MOD key=sync).
     */
    public function syncAll(): void
    {
        // Ein GET mit Wirkung (Uebertragung aller Eintraege, API-Kosten): nur mit dem Token, das
        // Contao an die globale Operation haengt - nicht ueber einen untergeschobenen Link.
        $container = System::getContainer();
        $token = new \Symfony\Component\Security\Csrf\CsrfToken(
            (string) $container->getParameter('contao.csrf_token_name'),
            (string) Input::get('rt')
        );

        if (!$container->get('contao.csrf.token_manager')->isTokenValid($token)) {
            Message::addError('Chatbot: Ungültiges Anfrage-Token. Bitte erneut auf „Alle neu übertragen“ klicken.');

            throw new RedirectResponseException(Backend::getReferer(true));
        }

        $result = $this->extraContent->syncAll(true);

        Message::addConfirmation(sprintf('Chatbot: %d Zusatzinhalt(e) übertragen.', $result['ok']));

        if ($result['failed'] > 0) {
            Message::addError(sprintf('Chatbot: %d Zusatzinhalt(e) mit Fehler – siehe Liste.', $result['failed']));
        }

        // Zurueck zur Liste, ohne key=sync - sonst liefe die Uebertragung beim Neuladen erneut.
        $request = System::getContainer()->get('request_stack')->getCurrentRequest();
        $url = $request ? preg_replace('/([?&])key=sync(&|$)/', '$1', $request->getUri()) : '';

        throw new RedirectResponseException(rtrim((string) $url, '?&') ?: Backend::getReferer(true));
    }
}
