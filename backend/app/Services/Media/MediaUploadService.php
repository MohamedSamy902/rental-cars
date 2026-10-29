<?php

namespace App\Services\Media;

use App\Models\MediaFile;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MediaUploadService
{
    /**
     * @param  array{collection?: string, purpose?: string|null, attachable_type?: string|null, attachable_id?: int|null, allow_document?: bool}  $meta
     */
    public function store(UploadedFile $file, array $meta = [], ?int $userId = null): MediaFile
    {
        if (! $file->isValid()) {
            $err = (int) $file->getError();
            if (in_array($err, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                throw new DomainException(__('http.payload_too_large'));
            }
            throw new DomainException(__('validation.uploaded', ['attribute' => __('validation.attributes.file')]));
        }

        $mime = strtolower((string) ($file->getMimeType() ?: $file->getClientMimeType() ?: ''));
        // بعض كاميرات أندرويد ترسل octet-stream — نحاول من الامتداد
        if ($mime === '' || $mime === 'application/octet-stream') {
            $mime = $this->mimeFromExtension(strtolower((string) $file->getClientOriginalExtension())) ?? $mime;
        }

        $kind = $this->detectKind($mime, (bool) ($meta['allow_document'] ?? false));
        $max = match ($kind) {
            'video' => (int) config('uploads.video.max_bytes'),
            'document' => (int) config('uploads.document.max_bytes'),
            default => (int) config('uploads.image.max_bytes'),
        };

        if ($file->getSize() > $max) {
            $mb = (int) round($max / 1024 / 1024);
            throw new DomainException("حجم الملف أكبر من المسموح (الحد الأقصى {$mb} ميجا).");
        }

        $collection = preg_replace('/[^a-z0-9_\-]/i', '', (string) ($meta['collection'] ?? 'general')) ?: 'general';
        $ext = strtolower($file->getClientOriginalExtension() ?: $this->defaultExtension($kind, $mime));
        $ext = preg_replace('/[^a-z0-9]/i', '', $ext) ?: $this->defaultExtension($kind, $mime);
        $name = now()->format('YmdHis').'_'.Str::lower(Str::random(12)).'.'.$ext;
        $relative = 'media/'.$collection.'/'.$name;

        Storage::disk((string) config('uploads.disk', 'local'))->putFileAs('media/'.$collection, $file, $name);

        return MediaFile::query()->create([
            'disk' => (string) config('uploads.disk', 'local'),
            'path' => $relative,
            'original_name' => Str::limit($file->getClientOriginalName(), 180, ''),
            'mime_type' => $mime,
            'kind' => $kind === 'document' ? 'document' : $kind,
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

    /**
     * @return array{image_max_mb: int, video_max_mb: int, document_max_mb: int, hint: string, accept_image: string, accept_video: string, accept_both: string, accept_document: string}
     */
    public function clientHints(): array
    {
        return [
            'image_max_mb' => (int) round(((int) config('uploads.image.max_bytes')) / 1024 / 1024),
            'video_max_mb' => (int) round(((int) config('uploads.video.max_bytes')) / 1024 / 1024),
            'document_max_mb' => (int) round(((int) config('uploads.document.max_bytes')) / 1024 / 1024),
            'hint' => (string) config('uploads.hint_ar'),
            'accept_image' => implode(',', array_merge(
                config('uploads.image.mimes'),
                ['.heic', '.heif', 'image/*'],
            )),
            'accept_video' => implode(',', config('uploads.video.mimes')),
            'accept_both' => implode(',', array_merge(
                config('uploads.image.mimes'),
                config('uploads.video.mimes'),
                ['.heic', '.heif'],
            )),
            'accept_document' => implode(',', array_merge(
                config('uploads.image.mimes'),
                config('uploads.document.mimes'),
                ['.heic', '.heif'],
            )),
        ];
    }

    private function detectKind(string $mime, bool $allowDocument): string
    {
        if (in_array($mime, config('uploads.image.mimes'), true)) {
            return 'image';
        }
        if (in_array($mime, config('uploads.video.mimes'), true)) {
            return 'video';
        }
        if ($allowDocument && in_array($mime, config('uploads.document.mimes'), true)) {
            return 'document';
        }

        throw new DomainException('نوع الملف غير مسموح، المسموح: صور وفيديوهات وPDF');
    }

    private function defaultExtension(string $kind, string $mime): string
    {
        return match (true) {
            str_contains($mime, 'png') => 'png',
            str_contains($mime, 'webp') => 'webp',
            str_contains($mime, 'gif') => 'gif',
            str_contains($mime, 'heic') => 'heic',
            str_contains($mime, 'heif') => 'heif',
            str_contains($mime, 'webm') => 'webm',
            str_contains($mime, 'quicktime') => 'mov',
            str_contains($mime, '3gpp') => '3gp',
            str_contains($mime, 'pdf') => 'pdf',
            $kind === 'video' => 'mp4',
            $kind === 'document' => 'pdf',
            default => 'jpg',
        };
    }

    private function mimeFromExtension(string $ext): ?string
    {
        return match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            'heic' => 'image/heic',
            'heif' => 'image/heif',
            'mp4', 'm4v' => 'video/mp4',
            'webm' => 'video/webm',
            'mov' => 'video/quicktime',
            '3gp' => 'video/3gpp',
            'pdf' => 'application/pdf',
            default => null,
        };
    }
}
