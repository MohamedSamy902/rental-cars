<?php

namespace App\Services\Contracts;

use App\Models\Inspection;
use App\Models\InspectionDamage;
use DomainException;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class InspectionService
{
    /**
     * @param  array{
     *   type: string,
     *   odometer: int,
     *   notes?: string|null,
     *   signature_data?: string|null,
     *   accessories?: array<int, array{accessory_id?: int, name: string, expected_qty?: int, actual_qty?: int, ok?: bool}>,
     *   damages?: array<int, array{position: string, description: string, photo_path?: string|null, present_at_checkout?: bool}>
     * }  $payload
     */
    public function record(int $contractId, array $payload, ?int $inspectorId = null): Inspection
    {
        if (! in_array($payload['type'], ['handover', 'return'], true)) {
            throw new DomainException('نوع الفحص غير صالح.');
        }

        // Signature canvas removed from close/handover modals — keep optional for legacy clients.
        $signatureData = $payload['signature_data'] ?? null;
        $path = null;
        if (is_string($signatureData) && str_starts_with($signatureData, 'data:image')) {
            $path = $this->storeSignature($contractId, $payload['type'], $signatureData);
        }

        $attrs = [
            'contract_id' => $contractId,
            'type' => $payload['type'],
            'inspected_at' => now(),
            'odometer' => (int) $payload['odometer'],
            'notes' => $payload['notes'] ?? null,
            'inspector_id' => $inspectorId,
            'signature_path' => $path,
            'signature_data' => $signatureData,
            'accessories_json' => $payload['accessories'] ?? [],
        ];
        if (Schema::hasColumn('inspections', 'fuel_level')) {
            $attrs['fuel_level'] = null;
        }

        $inspection = Inspection::query()->create($attrs);

        foreach ($payload['damages'] ?? [] as $damage) {
            InspectionDamage::query()->create([
                'inspection_id' => $inspection->id,
                'position' => $damage['position'],
                'description' => $damage['description'],
                'present_at_checkout' => (bool) ($damage['present_at_checkout'] ?? $payload['type'] === 'handover'),
                'photo_path' => $damage['photo_path'] ?? null,
            ]);
        }

        return $inspection->load('damages');
    }

    private function storeSignature(int $contractId, string $type, string $dataUrl): string
    {
        $raw = preg_replace('#^data:image/\w+;base64,#', '', $dataUrl) ?? '';
        $bin = base64_decode($raw, true);
        if ($bin === false) {
            throw new DomainException('توقيع غير صالح.');
        }

        $relative = 'signatures/'.$contractId.'-'.$type.'-'.now()->format('YmdHis').'.png';
        Storage::disk('local')->put($relative, $bin);

        return $relative;
    }
}
