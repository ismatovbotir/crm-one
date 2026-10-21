<?php

namespace App\Support;

use App\Models\ItemImage;
use Illuminate\Support\Facades\Storage;

/**
 * Pictures embedded into printed documents as data URIs (Spec 05 section 16): the print does not
 * depend on authenticated media URLs and stays inside the CSP (`img-src data:`). Only re-encoded
 * JPEGs from the private disk are embedded; anything else yields null (placeholder shown).
 */
final class ImageEmbed
{
    /** Largest file embedded (bytes); the 320 px snapshots are far below it. */
    public const MAX_BYTES = 300_000;

    public static function dataUri(?string $path): ?string
    {
        if ($path === null || $path === '' || ! str_ends_with($path, '.jpg')) {
            return null;
        }

        $disk = Storage::disk(ItemImage::DISK);

        if (! $disk->exists($path) || $disk->size($path) > self::MAX_BYTES) {
            return null;
        }

        return 'data:image/jpeg;base64,'.base64_encode((string) $disk->get($path));
    }
}
