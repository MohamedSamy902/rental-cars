<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreViolationRequest;
use App\Models\Violation;
use App\Models\ViolationSyncRun;
use App\Services\Violations\OfficialPortalProvider;
use App\Services\Violations\ViolationImportService;
use App\Services\Violations\ViolationMatchService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ViolationController extends Controller
{
    public function __construct(
        private readonly ViolationMatchService $matcher,
        private readonly ViolationImportService $imports,
        private readonly OfficialPortalProvider $portal,
    ) {}

    public function index(): JsonResponse
    {
        $rows = Violation::query()->with(['vehicle', 'customer', 'contract'])->latest('id')->paginate(50);

        return response()->json(['data' => $rows->items(), 'meta' => ['total' => $rows->total()]]);
    }

    public function portalInfo(Request $request): JsonResponse
    {
        $plate = $request->string('plate')->toString() ?: null;

        return response()->json([
            'data' => $this->portal->lookup($plate),
            'message' => 'الاستعلام الآلي غير متاح — استخدم البوابة الرسمية ثم احفظ هنا.',
        ]);
    }

    public function store(StoreViolationRequest $request): JsonResponse
    {
        $data = $request->validated();

        $match = [
            'contract_id' => $data['contract_id'] ?? null,
            'customer_id' => null,
            'needs_review' => false,
        ];

        if (! empty($match['contract_id'])) {
            $match['customer_id'] = \App\Models\Contract::query()->find($match['contract_id'])?->customer_id;
        } else {
            $match = $this->matcher->match((int) $data['vehicle_id'], $data['occurred_at']);
        }

        $violation = Violation::query()->create([
            ...$data,
            'contract_id' => $match['contract_id'],
            'customer_id' => $match['customer_id'],
            'status' => $match['contract_id'] ? 'assigned' : 'new',
            'source' => 'manual',
            'needs_review' => (bool) ($match['needs_review'] ?? false),
        ]);

        return response()->json([
            'data' => $violation->load(['vehicle', 'customer', 'contract']),
            'message' => 'تم تسجيل المخالفة للمتابعة (بلا ترحيل محاسبي).',
        ], 201);
    }

    public function assistedStore(Request $request): JsonResponse
    {
        $data = $request->validate([
            'vehicle_id' => ['required', 'integer', 'exists:vehicles,id'],
            'occurred_at' => ['required', 'date'],
            'amount' => ['required', 'integer', 'min:1'],
            'location' => ['nullable', 'string', 'max:500'],
            'external_number' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string'],
            'photo_path' => ['nullable', 'string', 'max:500'],
        ], [], [
            'vehicle_id' => 'السيارة',
            'occurred_at' => 'وقت المخالفة',
            'amount' => 'المبلغ',
            'external_number' => 'رقم المخالفة',
        ]);

        try {
            $violation = $this->imports->saveAssisted($data, $request->user()?->id);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => $violation->load(['vehicle', 'customer', 'contract']),
            'message' => 'تم حفظ المخالفة بعد الاستعلام اليدوي على البوابة.',
        ], 201);
    }

    public function importCsv(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:2048'],
        ], [
            'file.required' => 'ملف CSV مطلوب.',
            'file.mimes' => 'الملف يجب أن يكون CSV.',
        ], [
            'file' => 'الملف',
        ]);

        try {
            $result = $this->imports->importCsv($request->file('file'), $request->user()?->id);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => [
                'run' => $result['run'],
                'created_count' => count($result['created']),
                'errors' => $result['errors'],
            ],
            'message' => sprintf(
                'استيراد CSV: أُنشئ %d وتُخطّي %d.',
                $result['run']->rows_created,
                $result['run']->rows_skipped,
            ),
        ], 201);
    }

    public function syncRuns(): JsonResponse
    {
        $rows = ViolationSyncRun::query()->latest('id')->limit(20)->get();

        return response()->json(['data' => $rows]);
    }

    public function confirmPayment(Request $request, Violation $violation): JsonResponse
    {
        $data = $request->validate([
            'collection_note' => ['nullable', 'string'],
        ]);

        $violation->update([
            'status' => 'confirmed',
            'collection_note' => $data['collection_note'] ?? $violation->collection_note,
            'confirmed_by' => $request->user()?->id,
            'confirmed_at' => now(),
        ]);

        return response()->json([
            'data' => $violation->fresh(),
            'message' => 'تم تأكيد السداد دون قيد محاسبي.',
        ]);
    }
}
