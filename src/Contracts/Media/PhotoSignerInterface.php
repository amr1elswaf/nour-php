<?php

declare(strict_types=1);

namespace Nour\Contracts\Media;

/**
 * Sign media URLs for a specific client. The framework's WebSocket dispatch
 * layer ({@see \Nour\core\socket\GlobalRegistry::sendOneMessagesToMultiUsers()})
 * needs to rewrite photo URLs into IP-bound signed URLs before pushing them
 * to recipients — BunnyCDN signed URLs are tied to the requesting IP, so
 * each socket on a different IP needs its own signed copy.
 *
 * Implementations should be cheap (no I/O) — they're called inside hot
 * fan-out paths where every photo × socket pair adds up.
 */
interface PhotoSignerInterface
{
    /**
     * Sign a generic photo URL (post photo, message photo, exam photo, …).
     *
     * @param string $photoUrl  The unsigned CDN URL.
     * @param string $clientIp  The recipient socket's IP.
     * @return string|null      Signed URL, or null when the input is
     *                          empty / invalid (so callers can pass
     *                          values straight through without
     *                          null-checking first).
     */
    public function sign(string $photoUrl, string $clientIp): ?string;

    /**
     * Sign a profile photo specifically. Some apps render profile photos
     * with a dedicated thumbnail/sizing strategy, hence the separate
     * method instead of `sign()` with a "type" argument.
     */
    public function signProfilePhoto(string $photoUrl, string $clientIp): ?string;
}
