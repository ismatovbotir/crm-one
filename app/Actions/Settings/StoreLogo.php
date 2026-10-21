<?php

namespace App\Actions\Settings;

use App\Models\Setting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Stores an uploaded company logo safely (security audit, CWE-79 / CWE-434).
 *
 * The type is decided from the file content (finfo), never from the client name or extension.
 * Only PNG, JPEG and WebP raster images are accepted (no SVG: it can carry script). The image is
 * decoded and re-encoded with GD, which drops metadata and any trailing payload, scaled down to
 * at most MAX_EDGE px, and written as PNG under a random name on the public disk.
 */
class StoreLogo
{
    /** Content MIME types accepted as a logo. */
    public const ALLOWED_MIME_TYPES = ['image/png', 'image/jpeg', 'image/webp'];

    /** Largest edge of the stored logo in pixels (shown at <= 88 px and as a favicon). */
    public const MAX_EDGE = 512;

    /** Largest accepted source dimension; refuses decompression bombs before GD allocates. */
    public const MAX_SOURCE_EDGE = 4096;

    /**
     * True when the file content is a decodable PNG/JPEG/WebP within the size limits.
     */
    public static function isAcceptableImage(UploadedFile $file): bool
    {
        $path = $file->getRealPath();

        if ($path === false || ! is_readable($path)) {
            return false;
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);

        if (! in_array($mime, self::ALLOWED_MIME_TYPES, true)) {
            return false;
        }

        $size = @getimagesize($path);

        return is_array($size)
            && in_array($size['mime'] ?? null, self::ALLOWED_MIME_TYPES, true)
            && $size[0] > 0 && $size[1] > 0
            && $size[0] <= self::MAX_SOURCE_EDGE && $size[1] <= self::MAX_SOURCE_EDGE;
    }

    /**
     * Re-encodes the image and returns its path on the public disk.
     */
    public function handle(UploadedFile $file): string
    {
        if (! self::isAcceptableImage($file)) {
            throw new RuntimeException('Logo is not an acceptable raster image.');
        }

        $source = @imagecreatefromstring((string) file_get_contents((string) $file->getRealPath()));

        if ($source === false) {
            throw new RuntimeException('Logo could not be decoded.');
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1, self::MAX_EDGE / max($width, $height));
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        $target = imagecreatetruecolor($targetWidth, $targetHeight);
        imagealphablending($target, false);
        imagesavealpha($target, true);
        imagefill($target, 0, 0, imagecolorallocatealpha($target, 0, 0, 0, 127));
        imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        ob_start();
        imagepng($target, null, 9);
        $png = (string) ob_get_clean();

        $path = Setting::LOGO_DIRECTORY.'/'.Str::random(40).'.png';
        Storage::disk('public')->put($path, $png);

        return $path;
    }
}
