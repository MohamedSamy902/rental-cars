<?php

namespace App\Services\Contracts;

use App\Models\Inspection;
use App\Models\InspectionDamage;
use DomainException;
use Illuminate\Support\Facades\Storage;

class InspectionService
{
    /**
     * @param  array{
     *   type: string,
     *   odometer: int,
     *   fuel_level?: int,
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

        $opsRequireSignature = true;
        $settings = \App\Models\TenantSetting::query()->first();
        $ops = $settings?->operational ?? [];
        if ($payload['type'] === 'handover') {
            $opsRequireSignature = (bool) ($ops['require_handover_signature'] ?? true);
        } else {
            $hasDamage = ! empty($payload['damages']);
            $missingAccessory = collect($payload['accessories'] ?? [])->contains(fn ($a) => ($a['ok'] ?? true) === false);
            $opsRequireSignature = (bool) ($ops['require_return_signature_always'] ?? false)
                || $hasDamage
                || $missingAccessory
                || ((int) ($payload['damage_fee'] ?? 0) > 0);
        }

        $signatureData = $payload['signature_data'] ?? null;
        if ($opsRequireSignature && (! is_string($signatureData) || strlen($signatureData) < 40)) {
            throw new DomainException('توقيع العميل على الشاشة إلزامي لهذا الفحص.');
        }

        $path = null;
        if (is_string($signatureData) && str_starts_with($signatureData, 'data:image')) {
            $path = $this->storeSignature($contractId, $payload['type'], $signatureData);
        }

        $inspection = Inspection::query()->create([
            'contract_id' => $contractId,
            'type' => $payload['type'],
            'inspected_at' => now(),
            'odometer' => (int) $payload['odometer'],
            'fuel_level' => (int) ($payload['fuel_level'] ?? 50),
            'notes' => $payload['notes'] ?? null,
            'inspector_id' => $inspectorId,
            'signature_path' => $path,
            'signature_data' => $signatureData,
            'accessories_json' => $payload['accessories'] ?? [],
        ]);

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
