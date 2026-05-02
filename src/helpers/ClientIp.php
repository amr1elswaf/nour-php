<?php

namespace Nour\helpers;

use Swoole\Http\Request;

/**
 * Resolve a real client IP from a Swoole HTTP/WS request, honouring
 * X-Forwarded-For / CF-Connecting-IP **only** when the immediate peer
 * (`remote_addr`) is one of the configured trusted proxies. Otherwise
 * those headers are ignored — a remote attacker connecting directly
 * to the server cannot spoof IP-based rate limits / blacklists.
 *
 * Trusted proxy list comes from `$GLOBALS['setting']['trusted_proxies']`
 * (array of CIDR ranges, IPv4 or IPv6). When not configured, the default
 * is Cloudflare's published IP ranges (https://www.cloudflare.com/ips/),
 * which is the production setup. For local dev, override in sitting.json:
 *
 *     "trusted_proxies": ["127.0.0.1/32", "::1/128"]
 *
 * Or set to empty array `[]` to disable proxy trust entirely.
 */
final class ClientIp
{
    /** Default trusted proxies = Cloudflare IPv4 + IPv6 ranges. */
    private const DEFAULT_TRUSTED = [
        // IPv4
        '173.245.48.0/20',
        '103.21.244.0/22',
        '103.22.200.0/22',
        '103.31.4.0/22',
        '141.101.64.0/18',
        '108.162.192.0/18',
        '190.93.240.0/20',
        '188.114.96.0/20',
        '197.234.240.0/22',
        '198.41.128.0/17',
        '162.158.0.0/15',
        '104.16.0.0/13',
        '104.24.0.0/14',
        '172.64.0.0/13',
        '131.0.72.0/22',
        // IPv6
        '2400:cb00::/32',
        '2606:4700::/32',
        '2803:f800::/32',
        '2405:b500::/32',
        '2405:8100::/32',
        '2a06:98c0::/29',
        '2c0f:f248::/32',
    ];

    /**
     * Return the real client IP for the request.
     *
     * @param Request|object $request Swoole request (or any object exposing
     *                                ->header and ->server arrays).
     */
    public static function fromRequest(object $request): string
    {
        $remoteAddr = $request->server['remote_addr'] ?? '0.0.0.0';

        $trusted = $GLOBALS['setting']['trusted_proxies'] ?? self::DEFAULT_TRUSTED;
        if (!is_array($trusted)) {
            $trusted = self::DEFAULT_TRUSTED;
        }

        // Peer is not a trusted proxy → ignore client-supplied forward headers.
        if (!self::ipInCidrRanges($remoteAddr, $trusted)) {
            return $remoteAddr;
        }

        $headers = $request->header ?? [];
        if (!empty($headers['cf-connecting-ip'])) {
            return trim((string) $headers['cf-connecting-ip']);
        }
        if (!empty($headers['x-forwarded-for'])) {
            $ips = explode(',', (string) $headers['x-forwarded-for']);
            return trim($ips[0]);
        }
        return $remoteAddr;
    }

    /**
     * Check whether $ip falls inside any of the given CIDR ranges.
     * Supports IPv4 and IPv6, plus single-host entries (no slash).
     */
    public static function ipInCidrRanges(string $ip, array $ranges): bool
    {
        $ipBin = @inet_pton($ip);
        if ($ipBin === false) {
            return false;
        }

        foreach ($ranges as $range) {
            if (!is_string($range) || $range === '') {
                continue;
            }
            if (strpos($range, '/') === false) {
                if ($ip === $range) {
                    return true;
                }
                continue;
            }

            [$subnet, $bitsStr] = explode('/', $range, 2);
            $subnetBin = @inet_pton($subnet);
            if ($subnetBin === false || strlen($subnetBin) !== strlen($ipBin)) {
                continue;
            }
            $bits      = (int) $bitsStr;
            $fullBytes = intdiv($bits, 8);
            $remainder = $bits % 8;

            if ($fullBytes > 0
                && substr($ipBin, 0, $fullBytes) !== substr($subnetBin, 0, $fullBytes)
            ) {
                continue;
            }
            if ($remainder > 0) {
                $mask       = (~((1 << (8 - $remainder)) - 1)) & 0xFF;
                $ipByte     = ord($ipBin[$fullBytes] ?? "\0");
                $subnetByte = ord($subnetBin[$fullBytes] ?? "\0");
                if (($ipByte & $mask) !== ($subnetByte & $mask)) {
                    continue;
                }
            }
            return true;
        }
        return false;
    }
}
