<?php

namespace App\Services\Media;

use App\Models\MediaFile;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MediaUploadService
{
    public const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    public const VIDEO_MIMES = ['video/mp4', 'video/webm', 'video/quicktime'];

    public const MAX_IMAGE_BYTES = 5 * 1024 * 1024;

    public const MAX_VIDEO_BYTES = 50 * 1024 * 1024;

    /**
     * @param  array{collection?: string, purpose?: string|null, attachable_type?: string|null, attachable_id?: int|null}  $meta
     */
    public function store(UploadedFile $file, array $meta = [], ?int $userId = null): MediaFile
    {
        if (! $file->isValid()) {
            throw new DomainException('فشل رفع الملف.');
        }

        $mime = $file->getMimeType() ?: $file->getClientMimeType() ?: '';
        $kind = $this->detectKind($mime);
        $max = $kind === 'video' ? self::MAX_VIDEO_BYTES : self::MAX_IMAGE_BYTES;
        if ($file->getSize() > $max) {
            $mb = (int) round($max / 1024 / 1024);
            throw new DomainException($kind === 'video'
                ? "حجم الفيديو يتجاوز الحد المسموح ({$mb} ميجا)."
                : "حجم الصورة يتجاوز الحد المسموح ({$mb} ميجا).");
        }

        $collection = preg_replace('/[^a-z0-9_\-]/i', '', (string) ($meta['collection'] ?? 'general')) ?: 'general';
        $ext = strtolower($file->getClientOriginalExtension() ?: ($kind === 'video' ? 'mp4' : 'jpg'));
        $ext = preg_replace('/[^a-z0-9]/i', '', $ext) ?: ($kind === 'video' ? 'mp4' : 'jpg');
        $name = now()->format('YmdHis').'_'.Str::lower(Str::random(12)).'.'.$ext;
        $relative = 'media/'.$collection.'/'.$name;

        Storage::disk('local')->putFileAs('media/'.$collection, $file, $name);

        return MediaFile::query()->create([
            'disk' => 'local',
            'path' => $relative,
            'original_name' => Str::limit($file->getClientOriginalName(), 180, ''),
            'mime_type' => $mime,
            'kind' => $kind,
            'collection' => $collection,
            'purpose' => $meta['purpose'] ?? null,
            'size_bytes' => (int) $file->getSize(),
            'attachable_type' => $meta['attachable_type'] ?? null,
            'attachable_id' => $meta['attachable_id'] ?? null,
            'uploaded_by' => $userId,
        ]);
    }

    public function delete(MediaFile $media): void
    {
        if (Storage::disk($media->disk)->exists($media->path)) {
            Storage::disk($media->disk)->delete($media->path);
        }
        $media->delete();
    }

    public function absolutePath(MediaFile $media): string
    {
        return Storage::disk($media->disk)->path($media->path);
    }

    private function detectKind(string $mime): string
    {
        if (in_array($mime, self::IMAGE_MIMES, true)) {
            return 'image';
        }
        if (in_array($mime, self::VIDEO_MIMES, true)) {
            return 'video';
        }

        throw new DomainException('نوع الملف غير مدعوم. المسموح: صور (jpg/png/webp/gif) وفيديو (mp4/webm/mov).');
    }
}
