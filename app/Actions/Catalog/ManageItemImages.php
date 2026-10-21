<?php

namespace App\Actions\Catalog;

use App\Models\Item;
use App\Models\ItemImage;
use App\Models\User;
use App\Services\Catalog\Exceptions\ItemImageException;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Item pictures (Spec 01 section 15), handled like the logo upload (security audit, CWE-434):
 *  - JPEG, PNG and WebP only, decided from the content (finfo and getimagesize agree), never SVG;
 *  - at most 5 MB and 6000 × 6000 px / 36 megapixels before GD allocates (decompression bombs);
 *  - decoded and re-encoded with GD as JPEG (EXIF, ICC and any trailing payload dropped; alpha
 *    flattened on white), full ≤ 1600 px, medium 320 px, thumb 96 px, random names on the private
 *    disk; served only through MediaController;
 *  - one main picture (position 0) plus up to 5 more; replace, remove, make main. Every change is
 *    logged on the item. Files of removed pictures are deleted; sent offers keep their own snapshot.
 */
class ManageItemImages
{
    public const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    public const MAX_BYTES = 5 * 1024 * 1024;

    public const MAX_EDGE = 6000;

    public const MAX_PIXELS = 36_000_000;

    public const MAX_EXTRA = 5;

    public const SIZES = ['path' => 1600, 'medium_path' => 320, 'thumb_path' => 96];

    public function add(Item $item, UploadedFile $file, User $user, bool $asMain = false): ItemImage
    {
        $source = $this->decode($file);

        return DB::transaction(function () use ($item, $file, $user, $asMain, $source): ItemImage {
            $images = $item->images()->lockForUpdate()->get();
            $hasMain = $images->contains(fn (ItemImage $image): bool => $image->isMain());

            if (! ($asMain || ! $hasMain) && $images->where('position', '>', 0)->count() >= self::MAX_EXTRA) {
                throw new ItemImageException('too_many', ['max' => self::MAX_EXTRA]);
            }

            if ($asMain && $hasMain) {
                $this->remove($images->first(fn (ItemImage $image): bool => $image->isMain()), $user, false);
            }

            $position = ($asMain || ! $hasMain) ? 0 : (int) $images->max('position') + 1;
            $paths = $this->store($source);

            $image = $item->images()->create([
                ...$paths,
                'position' => $position,
                'width' => imagesx($source),
                'height' => imagesy($source),
                'size_bytes' => (int) $file->getSize(),
                'sha256' => (string) hash_file('sha256', (string) $file->getRealPath()),
                'created_by' => $user->id,
            ]);
            imagedestroy($source);
            $item->logActivity('image_added', ['main' => $position === 0]);

            return $image;
        });
    }

    public function makeMain(ItemImage $image, User $user): void
    {
        DB::transaction(function () use ($image, $user): void {
            $item = $image->item;
            $current = $item->images()->where('position', 0)->whereKeyNot($image->id)->first();
            $old = $image->position;
            $current?->forceFill(['position' => max(1, $old)])->save();
            $image->forceFill(['position' => 0])->save();
            $item->logActivity('image_main_changed', ['by' => $user->id]);
        });
    }

    public function remove(ItemImage $image, User $user, bool $promoteNext = true): void
    {
        $wasMain = $image->isMain();
        $item = $image->item;
        $paths = [$image->path, $image->medium_path, $image->thumb_path];
        $image->delete();
        Storage::disk(ItemImage::DISK)->delete($paths);

        if ($wasMain && $promoteNext) {
            $item->images()->orderBy('position')->first()?->forceFill(['position' => 0])->save();
        }

        $item->logActivity('image_removed', ['main' => $wasMain]);
    }

    /**
     * Content checks before GD allocates, then decode.
     */
    public function decode(UploadedFile $file): GdImage
    {
        $path = $file->getRealPath();

        if ($path === false || ! is_readable($path) || (int) $file->getSize() > self::MAX_BYTES || (int) $file->getSize() === 0) {
            throw new ItemImageException('too_large', ['mb' => self::MAX_BYTES / 1024 / 1024]);
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $info = @getimagesize($path);

        if (! in_array($mime, self::ALLOWED_MIME_TYPES, true) || ! is_array($info) || ! in_array($info['mime'] ?? null, self::ALLOWED_MIME_TYPES, true)) {
            throw new ItemImageException('type');
        }

        if ($info[0] < 1 || $info[1] < 1 || $info[0] > self::MAX_EDGE || $info[1] > self::MAX_EDGE || $info[0] * $info[1] > self::MAX_PIXELS) {
            throw new ItemImageException('dimensions', ['max' => self::MAX_EDGE]);
        }

        $image = @imagecreatefromstring((string) file_get_contents($path));

        if ($image === false) {
            throw new ItemImageException('type');
        }

        return $image;
    }

    /**
     * Writes the three re-encoded variants and returns their paths.
     *
     * @return array{path: string, medium_path: string, thumb_path: string}
     */
    protected function store(GdImage $source): array
    {
        $base = 'item-images/'.now()->format('Y/m').'/'.Str::random(40);
        $paths = [];

        foreach (self::SIZES as $column => $edge) {
            $suffix = match ($column) {
                'medium_path' => '-m',
                'thumb_path' => '-t',
                default => '',
            };
            $paths[$column] = $base.$suffix.'.jpg';
            Storage::disk(ItemImage::DISK)->put($paths[$column], $this->jpeg($source, $edge));
        }

        return $paths;
    }

    public function jpeg(GdImage $source, int $edge): string
    {
        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1, $edge / max($width, $height));
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        $target = imagecreatetruecolor($targetWidth, $targetHeight);
        imagefill($target, 0, 0, imagecolorallocate($target, 255, 255, 255));
        imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        ob_start();
        imagejpeg($target, null, 85);
        $bytes = (string) ob_get_clean();
        imagedestroy($target);

        return $bytes;
    }
}
