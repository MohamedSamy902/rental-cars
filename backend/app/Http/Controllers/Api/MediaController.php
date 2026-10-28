<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MediaFile;
use App\Services\Media\MediaUploadService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MediaController extends Controller
{
    public function __construct(private readonly MediaUploadService $media) {}

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file'],
            'collection' => ['nullable', 'string', 'max:40'],
            'purpose' => ['nullable', 'string', 'max:60'],
            'attachable_type' => ['nullable', 'string', 'max:120'],
            'attachable_id' => ['nullable', 'integer', 'min:1'],
        ], [], [
            'file' => 'الملف',
        ]);

        try {
            $row = $this->media->store(
                $request->file('file'),
                [
                    'collection' => $request->input('collection', 'general'),
                    'purpose' => $request->input('purpose'),
                    'attachable_type' => $this->mapAttachable($request->input('attachable_type')),
                    'attachable_id' => $request->integer('attachable_id') ?: null,
                ],
                $request->user()?->id,
            );
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => $this->present($row),
            'message' => 'تم رفع الملف.',
        ], 201);
    }

    public function show(Request $request, MediaFile $medium): JsonResponse
    {
        $this->authorizeView($request, $medium);

        return response()->json(['data' => $this->present($medium)]);
    }

    public function file(Request $request, MediaFile $medium): StreamedResponse|JsonResponse
    {
        $this->authorizeView($request, $medium);

        if (! Storage::disk($medium->disk)->exists($medium->path)) {
            return response()->json(['message' => 'الملف غير موجود على التخزين.'], 404);
        }

        return Storage::disk($medium->disk)->response(
            $medium->path,
            $medium->original_name,
            [
                'Content-Type' => $medium->mime_type,
                'Content-Disposition' => 'inline; filename="'.$medium->original_name.'"',
                'Cache-Control' => 'private, max-age=3600',
            ],
        );
    }

    public function destroy(Request $request, MediaFile $medium): JsonResponse
    {
        if ($medium->uploaded_by && $medium->uploaded_by !== $request->user()?->id && ! $request->user()?->hasPermission('settings.update')) {
            if (! $request->user()?->hasPermission('vehicles.update') && ! $request->user()?->hasPermission('customers.update')) {
                return response()->json(['message' => 'ليست لديك صلاحية حذف هذا الملف.'], 403);
            }
        }

        $this->media->delete($medium);

        return response()->json(['message' => 'تم حذف الملف.']);
    }

    private function authorizeView(Request $request, MediaFile $medium): void
    {
        if (! $medium->isSensitive()) {
            return;
        }

        $user = $request->user();
        if (
            $user?->hasPermission('sensitive.view_id_images')
            || $user?->hasPermission('customers.view')
            || $user?->hasPermission('customers.update')
        ) {
            return;
        }

        abort(403, 'عرض صور الهوية يتطلب صلاحية حسّاسة.');
    }

    private function mapAttachable(?string $type): ?string
    {
        return match ($type) {
            'customer', 'customers', \App\Models\Customer::class => \App\Models\Customer::class,
            'vehicle', 'vehicles', \App\Models\Vehicle::class => \App\Models\Vehicle::class,
            'contract', 'contracts', \App\Models\Contract::class => \App\Models\Contract::class,
            'inspection', 'inspections', \App\Models\Inspection::class => \App\Models\Inspection::class,
            null, '' => null,
            default => str_contains((string) $type, '\\') ? $type : null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function present(MediaFile $m): array
    {
        return [
            'id' => $m->id,
            'kind' => $m->kind,
            'mime_type' => $m->mime_type,
            'original_name' => $m->original_name,
            'collection' => $m->collection,
            'purpose' => $m->purpose,
            'size_bytes' => $m->size_bytes,
            'path' => $m->path,
            'url' => url('/api/media/'.$m->id.'/file'),
            'attachable_type' => $m->attachable_type,
            'attachable_id' => $m->attachable_id,
            'created_at' => $m->created_at,
        ];
    }
}
