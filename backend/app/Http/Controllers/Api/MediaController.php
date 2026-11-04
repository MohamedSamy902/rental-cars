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

    public function limits(): JsonResponse
    {
        return response()->json(['data' => $this->media->clientHints()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file'],
            'collection' => ['nullable', 'string', 'max:40'],
            'purpose' => ['nullable', 'string', 'max:60'],
            'attachable_type' => ['nullable', 'string', 'max:120'],
            'attachable_id' => ['nullable', 'integer', 'min:1'],
            'allow_document' => ['nullable', 'boolean'],
        ], [
            'file.required' => 'الملف مطلوب.',
            'file.uploaded' => 'فشل رفع الملف. تحقق من الحجم والنوع وحاول مرة أخرى.',
            'file.file' => 'يجب أن يكون الملف صالحاً.',
        ], [
            'file' => 'الملف',
        ]);

        try {
            $row = $this->media->store(
                $request->file('file'),
                [
                    'collection' => $data['collection'] ?? 'general',
                    'purpose' => $data['purpose'] ?? null,
                    'attachable_type' => $this->mapAttachable($data['attachable_type'] ?? null),
                    'attachable_id' => isset($data['attachable_id']) ? (int) $data['attachable_id'] : null,
                    'allow_document' => (bool) ($data['allow_document'] ?? false),
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
        $this->authorize('view', $medium);

        return response()->json(['data' => $this->present($medium)]);
    }

    public function file(Request $request, MediaFile $medium): StreamedResponse|JsonResponse
    {
        $this->authorize('view', $medium);

        if (! Storage::disk($medium->disk)->exists($medium->path)) {
            return response()->json(['message' => __('http.not_found')], 404);
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
        $this->authorize('delete', $medium);
        $user = $request->user();
        $isOwner = $medium->uploaded_by && $medium->uploaded_by === $user?->id;
        $canManage = $user?->hasPermission('media.delete')
            || $user?->hasPermission('settings.update')
            || $user?->hasPermission('vehicles.update')
            || $user?->hasPermission('customers.update');

        if (! $isOwner && ! $canManage) {
            return response()->json(['message' => __('http.forbidden')], 403);
        }

        $this->media->delete($medium);

        return response()->json(['message' => 'تم حذف الملف.']);
    }

    private function authorizeView(Request $request, MediaFile $medium): void
    {
        $user = $request->user();
        if (! $user) {
            abort(401, __('http.unauthenticated'));
        }

        if (! $user->hasPermission('media.view') && ! $user->hasPermission('media.upload')) {
            // allow if user has any module that uses media
            $ok = $user->hasPermission('customers.view')
                || $user->hasPermission('vehicles.view')
                || $user->hasPermission('contracts.view')
                || $user->hasPermission('maintenance.view')
                || $user->hasPermission('violations.view');
            if (! $ok) {
                abort(403, __('http.forbidden'));
            }
        }

        if (! $medium->isSensitive()) {
            return;
        }

        if ($user->hasPermission('sensitive.view_id_images')) {
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
            'violation', 'violations', \App\Models\Violation::class => \App\Models\Violation::class,
            'work_order', 'work_orders', \App\Models\WorkOrder::class => \App\Models\WorkOrder::class,
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
