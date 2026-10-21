<?php

namespace App\Services\Attachments;

use App\Models\Attachment;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Stores an uploaded document privately (Spec 06 section 13 "File uploads", AC-42): only pdf, jpg
 * and png, size limit, the MIME type sniffed from the bytes must match the extension, a random
 * file name on the private disk, sha256 recorded. Served only by AttachmentController after the
 * owning record's policy check.
 */
class StoreAttachment
{
    /** @var array<string, list<string>> extension => accepted sniffed MIME types */
    public const ALLOWED = [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
    ];

    public const DISK = 'local';

    public function handle(UploadedFile $file, Model $attachable, string $purpose, ?User $user, int $maxKilobytes, string $field = 'file'): Attachment
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $sniffed = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());

        if (! $file->isValid() || ! array_key_exists($extension, self::ALLOWED) || ! in_array($sniffed, self::ALLOWED[$extension], true)) {
            throw ValidationException::withMessages([$field => __('agreements.errors.file_type')]);
        }

        if ($file->getSize() > $maxKilobytes * 1024) {
            throw ValidationException::withMessages([$field => __('agreements.errors.file_size', ['max' => intdiv($maxKilobytes, 1024)])]);
        }

        $path = $file->storeAs('attachments/'.now()->format('Y/m'), Str::random(40).'.'.($extension === 'jpeg' ? 'jpg' : $extension), self::DISK);

        return Attachment::query()->create([
            'attachable_type' => $attachable->getMorphClass(),
            'attachable_id' => $attachable->getKey(),
            'purpose' => $purpose,
            'disk' => self::DISK,
            'path' => $path,
            'original_name' => Str::limit(basename($file->getClientOriginalName()), 250, ''),
            'mime' => $sniffed,
            'size' => (int) $file->getSize(),
            'sha256' => hash_file('sha256', $file->getRealPath()),
            'uploaded_by' => $user?->id,
        ]);
    }
}
