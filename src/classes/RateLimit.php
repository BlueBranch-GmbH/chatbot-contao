<?php

namespace Bluebranch\Chatbot\classes;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Einfaches Ratenlimit fuer die oeffentlichen Routen, ueber den Anwendungs-Cache.
 *
 * Den Sitzungs-Token bekommt jeder mit einer einzigen Anfrage; ohne Limit liesse sich so das
 * Kontingent der API leeren oder mit vielen offenen Streams die PHP-Worker belegen. Gezaehlt wird
 * je Client-IP (IPv6 je /64, sonst liesse sich das Limit durch Adresswechsel umgehen) und
 * zusaetzlich fuer die ganze Installation.
 *
 * Die Client-IP liefert Symfony; hinter einem Proxy muss dieser unter `trusted_proxies`
 * eingetragen sein, sonst teilen sich alle Besucher eine Adresse.
 *
 * Nicht atomar - fuer den Zweck (Missbrauch bremsen, nicht exakt zaehlen) genuegt das.
 */
class RateLimit
{
    private CacheItemPoolInterface $cache;

    public function __construct(CacheItemPoolInterface $cache)
    {
        $this->cache = $cache;
    }

    public function allowClient(Request $request, string $bucket, int $limit, int $window = 60): bool
    {
        return $this->consume($bucket . '|' . self::clientKey($request), $limit, $window);
    }

    public function allowGlobal(string $bucket, int $limit, int $window = 60): bool
    {
        return $this->consume($bucket . '|global', $limit, $window);
    }

    public static function clientKey(Request $request): string
    {
        $ip = (string) $request->getClientIp();

        if (false !== strpos($ip, ':')) {
            $packed = @inet_pton($ip);

            if (false !== $packed && 16 === \strlen($packed)) {
                return bin2hex(substr($packed, 0, 8)) . '::/64';
            }
        }

        return $ip;
    }

    private function consume(string $key, int $limit, int $window): bool
    {
        $slot = (int) floor(time() / $window);
        $item = $this->cache->getItem('bluebranch_chatbot_rl_' . hash('sha1', $key . '|' . $slot));
        $count = $item->isHit() ? (int) $item->get() : 0;

        if ($count >= $limit) {
            return false;
        }

        $item->set($count + 1);
        $item->expiresAfter($window + 5);
        $this->cache->save($item);

        return true;
    }
}
