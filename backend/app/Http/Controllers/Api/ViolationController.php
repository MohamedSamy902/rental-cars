<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreViolationRequest;
use App\Http\Requests\UpdateViolationRequest;
use App\Models\Contract;
use App\Models\Violation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ViolationController extends Controller
{
    public function index(): JsonResponse
    {
        $rows = Violation::query()->with(['vehicle', 'customer', 'contract'])->latest('id')->paginate(50);
        $data = collect($rows->items())->map(fn (Violation $v) => $this->serialize($v))->all();

        return response()->json(['data' => $data, 'meta' => ['total' => $rows->total()]]);
    }

    public function store(StoreViolationRequest $request): JsonResponse
    {
        $data = $request->validated();
        $photos = $this->normalizePhotos($data);
        unset($data['photo_paths']);

        [$contractId, $customerId] = $this->resolveContractLink($data);

        $violation = Violation::query()->create([
            ...$data,
            'contract_id' => $contractId,
            'customer_id' => $customerId,
            'photo_path' => $photos[0] ?? ($data['photo_path'] ?? null),
            'photo_paths' => $photos === [] ? null : $photos,
            'status' => $contractId ? 'assigned' : 'new',
        ]);

        return response()->json([
            'data' => $this->serialize($violation->load(['vehicle', 'customer', 'contract'])),
            'message' => 'تم تسجيل المخالفة للمتابعة (بلا ترحيل محاسبي).',
        ], 201);
    }

    public function update(UpdateViolationRequest $request, Violation $violation): JsonResponse
    {
        $data = $request->validated();
        $photos = array_key_exists('photo_paths', $data) || array_key_exists('photo_path', $data)
            ? $this->normalizePhotos($data)
            : null;
        unset($data['photo_paths']);

        $vehicleId = (int) ($data['vehicle_id'] ?? $violation->vehicle_id);
        $occurredAt = (string) ($data['occurred_at'] ?? $violation->occurred_at);
        $linkPayload = [
            'vehicle_id' => $vehicleId,
            'occurred_at' => $occurredAt,
            'contract_id' => array_key_exists('contract_id', $data) ? $data['contract_id'] : $violation->contract_id,
        ];

        [$contractId, $customerId] = $this->resolveContractLink($linkPayload);

        $status = $data['status'] ?? $violation->status;
        if (! isset($data['status'])) {
            if ($contractId && in_array($violation->status, ['new', 'assigned'], true)) {
                $status = 'assigned';
            } elseif (! $contractId && $violation->status === 'assigned') {
                $status = 'new';
            }
        }

        $payload = [
            ...$data,
            'vehicle_id' => $vehicleId,
            'contract_id' => $contractId,
            'customer_id' => $customerId,
            'status' => $status,
        ];

        if ($photos !== null) {
            $payload['photo_path'] = $photos[0] ?? null;
            $payload['photo_paths'] = $photos === [] ? null : $photos;
        }

        if (($status === 'confirmed' || $status === 'paid') && ! $violation->confirmed_at) {
            $payload['confirmed_at'] = now();
            $payload['confirmed_by'] = $request->user()?->id;
        }
        if (in_array($status, ['new', 'assigned'], true)) {
            $payload['confirmed_at'] = null;
            $payload['confirmed_by'] = null;
        }

        $violation->update($payload);

        return response()->json([
            'data' => $this->serialize($violation->fresh()->load(['vehicle', 'customer', 'contract'])),
            'message' => 'تم تحديث المخالفة.',
        ]);
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
            'data' => $this->serialize($violation->fresh()->load(['vehicle', 'customer', 'contract'])),
            'message' => 'تم تأكيد السداد دون قيد محاسبي.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: int|null, 1: int|null}
     */
    private function resolveContractLink(array $data): array
    {
        $contractId = isset($data['contract_id']) && $data['contract_id'] ? (int) $data['contract_id'] : null;
        $customerId = null;

        if (! $contractId) {
            $contract = Contract::query()
                ->where('vehicle_id', $data['vehicle_id'])
                ->whereIn('status', ['active', 'completed'])
                ->where('starts_at', '<=', $data['occurred_at'])
                ->where(function ($q) use ($data) {
                    $q->whereNull('actual_ends_at')
                        ->orWhere('actual_ends_at', '>=', $data['occurred_at']);
                })
                ->orderByDesc('starts_at')
                ->first();
            if ($contract) {
                $contractId = $contract->id;
                $customerId = $contract->customer_id;
            }
        } else {
            $customerId = Contract::query()->find($contractId)?->customer_id;
        }

        return [$contractId, $customerId];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function normalizePhotos(array $data): array
    {
        $paths = [];
        if (isset($data['photo_paths']) && is_array($data['photo_paths'])) {
            foreach ($data['photo_paths'] as $p) {
                if (is_string($p) && trim($p) !== '') {
                    $paths[] = trim($p);
                }
            }
        }
        if ($paths === [] && isset($data['photo_path']) && is_string($data['photo_path']) && trim($data['photo_path']) !== '') {
            $paths[] = trim($data['photo_path']);
        }

        return array_values(array_unique($paths));
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(Violation $v): array
    {
        $arr = $v->toArray();
        $arr['photo_paths'] = $v->resolvedPhotoPaths();

        return $arr;
    }
}
