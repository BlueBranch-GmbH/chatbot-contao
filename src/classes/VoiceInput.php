<?php

namespace Bluebranch\Chatbot\classes;

use Contao\Config;
use Contao\PageModel;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Spracheingabe (Mikrofon-Knopf): ob sie fuer ein Modul angeboten wird und wie lang eine Aufnahme sein darf.
 *
 * Zwei Bedingungen muessen zusammentreffen: Das Modul erlaubt sie (Einstellung je Modul oder
 * Vorgabe aus den Einstellungen), und die API hat die Spracherkennung eingerichtet
 * (`transcriptionEnabled` in `GET /user/me/tier`). Die Laenge begrenzt die Stufe des Kontos
 * (`maxAudioSeconds`); sie wird hier nur weitergereicht.
 */
class VoiceInput
{
    private const CACHE_PREFIX = 'chatbot_voice_';
    private const TTL = 600;
    /** Nach einem Fehler kurz warten, damit eine nicht erreichbare API nicht jede Seite bremst. */
    private const TTL_FAILED = 60;

    /** Ohne Angabe der API: hoechstens so lang (Free-Stufe). */
    private const DEFAULT_SECONDS = 10;

    private ChatbotAPI $api;
    private CacheItemPoolInterface $cache;

    public function __construct(ChatbotAPI $api, CacheItemPoolInterface $cache)
    {
        $this->api = $api;
        $this->cache = $cache;
    }

    /**
     * '1' = an, '0' = aus, sonst die Vorgabe aus den Einstellungen.
     *
     * @param object $module ModuleModel oder Datensatz mit `chatbot_voice`
     */
    public static function moduleAllows($module): bool
    {
        $value = (string) ($module->chatbot_voice ?? '');

        if ('1' === $value || '0' === $value) {
            return '1' === $value;
        }

        return (bool) Config::get('chatbot_voice_default');
    }

    /**
     * Ob und wie lang das Mikrofon fuer dieses Modul angeboten wird (Modul erlaubt es und die API bietet es an).
     *
     * @param object $module ModuleModel oder Datensatz mit `chatbot_voice`
     *
     * @return array{enabled: bool, maxSeconds: int, language: string}
     */
    public function forModule($module, ?PageModel $page): array
    {
        $language = $page instanceof PageModel ? substr(strtolower((string) $page->language), 0, 2) : 'de';
        $language = 1 === preg_match('/^[a-z]{2}$/', $language) ? $language : 'de';

        if (!self::moduleAllows($module)) {
            return ['enabled' => false, 'maxSeconds' => 0, 'language' => $language];
        }

        $capability = $this->capability($page);

        return ['enabled' => $capability['enabled'], 'maxSeconds' => $capability['maxSeconds'], 'language' => $language];
    }

    /**
     * Was die API fuer den Schluessel der Seite erlaubt.
     *
     * @return array{enabled: bool, maxSeconds: int}
     */
    public function capability(?PageModel $page): array
    {
        $key = $this->api->getApiKey($page);

        if ('' === $key) {
            return ['enabled' => false, 'maxSeconds' => self::DEFAULT_SECONDS];
        }

        $item = $this->cache->getItem(self::CACHE_PREFIX . hash('sha256', $key));

        if ($item->isHit() && \is_array($item->get())) {
            return $item->get();
        }

        $tier = $this->api->getTier($page);
        $enabled = !empty($tier['success']) && !empty($tier['transcriptionEnabled']);
        $seconds = (int) ($tier['maxAudioSeconds'] ?? 0);

        $result = [
            'enabled' => $enabled,
            'maxSeconds' => $seconds > 0 ? $seconds : self::DEFAULT_SECONDS,
        ];

        $item->set($result);
        $item->expiresAfter(!empty($tier['success']) ? self::TTL : self::TTL_FAILED);
        $this->cache->save($item);

        return $result;
    }
}
