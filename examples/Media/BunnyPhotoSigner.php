<?php

declare(strict_types=1);

namespace App\Framework\Media;

use App\classes\community\profile\ProfilePhoto;
use App\media\show\GetPhoto;
use Nour\Contracts\Media\PhotoSignerInterface;

/**
 * Adapts the existing BunnyCDN signing helpers to
 * {@see PhotoSignerInterface}, so the framework's WebSocket dispatch
 * layer doesn't import `App\…` photo classes.
 *
 * - Profile photos route through {@see ProfilePhoto::smallPhoto()}
 *   (returns the per-IP-signed thumbnail variant).
 * - Everything else goes through {@see GetPhoto::signature_link()}.
 */
final class BunnyPhotoSigner implements PhotoSignerInterface
{
    public function sign(string $photoUrl, string $clientIp): ?string
    {
        if ($photoUrl === '') {
            return null;
        }
        return GetPhoto::signature_link($photoUrl, $clientIp);
    }

    public function signProfilePhoto(string $photoUrl, string $clientIp): ?string
    {
        if ($photoUrl === '') {
            return null;
        }
        return ProfilePhoto::smallPhoto($photoUrl, $clientIp);
    }
}
