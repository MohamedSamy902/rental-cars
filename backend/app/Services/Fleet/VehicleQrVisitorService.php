<?php

namespace App\Services\Fleet;

use App\Models\Contract;
use App\Models\TenantSetting;
use App\Models\Vehicle;
use App\Support\PublicAppUrl;

/**
 * بطاقة الرمز السريع للزائر — بيانات مركز فقط (+ مستأجر حالي إن وُجد).
 * بلا أرقام قومية ولا حسابات ولا مستندات حسّاسة (RSD ٦).
 */
final class VehicleQrVisitorService
{
    /**
     * @return array{
     *   vehicle: array{id:int,plate:?string,brand:?string,model:?string,year:?int},
     *   center: array{name:string,phone:?string,address:?string,logo_url:?string,social:array<string,string>},
     *   current_renter: null|array{full_name:string,phone:?string},
     *   is_rented: bool,
     *   credit: array{name:string,whatsapp_url:string}
     * }
     */
    public function card(Vehicle $vehicle): array
    {
        $tenant = TenantSetting::query()->first();
        $ops = is_array($tenant?->operational) ? $tenant->operational : [];

        $active = Contract::query()
            ->with(['customer:id,full_name,phone'])
            ->where('vehicle_id', $vehicle->id)
            ->where('status', 'active')
            ->orderByDesc('id')
            ->first();

        $renter = null;
        if ($active?->customer) {
            $renter = [
                'full_name' => (string) $active->customer->full_name,
                'phone' => $active->customer->phone ? (string) $active->customer->phone : null,
            ];
        }

        return [
            'vehicle' => [
                'id' => (int) $vehicle->id,
                'plate' => $vehicle->plate,
                'brand' => $vehicle->brand,
                'model' => $vehicle->model,
                'year' => $vehicle->year !== null ? (int) $vehicle->year : null,
            ],
            'center' => [
                'name' => (string) ($tenant?->center_name ?: 'مركز التأجير'),
                'phone' => isset($ops['print_phone']) && is_string($ops['print_phone']) && $ops['print_phone'] !== ''
                    ? $ops['print_phone']
                    : null,
                'address' => isset($ops['print_address']) && is_string($ops['print_address']) && $ops['print_address'] !== ''
                    ? $ops['print_address']
                    : null,
                'logo_url' => $this->publicLogoUrl($tenant?->logo_path),
                'social' => $this->socialLinks($ops),
            ],
            'current_renter' => $renter,
            'is_rented' => $renter !== null,
            'credit' => [
                'name' => (string) config('rpm.platform_author_name', 'محمد سامي'),
                'whatsapp_url' => (string) config('rpm.platform_whatsapp_url', 'https://wa.me/201150100104'),
            ],
        ];
    }

    public function payloadUrl(Vehicle $vehicle): string
    {
        return PublicAppUrl::to('/qr/vehicles/'.$vehicle->id);
    }

    private function publicLogoUrl(?string $logoPath): ?string
    {
        $logoPath = is_string($logoPath) ? trim($logoPath) : '';
        if ($logoPath === '') {
            return null;
        }

        if (str_starts_with($logoPath, 'http://') || str_starts_with($logoPath, 'https://')) {
            return $logoPath;
        }

        // شعارات /brand عامة؛ مسارات /media تتطلب مصادقة فلا تُعرض للزائر.
        if (str_contains($logoPath, '/brand/')) {
            return str_starts_with($logoPath, '/')
                ? PublicAppUrl::to(ltrim($logoPath, '/'))
                : PublicAppUrl::to($logoPath);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $ops
     * @return array<string, string>
     */
    private function socialLinks(array $ops): array
    {
        $out = [];
        foreach (['facebook', 'instagram', 'tiktok', 'twitter', 'youtube', 'snapchat'] as $key) {
            $val = $ops['social_'.$key] ?? $ops[$key] ?? null;
            if (is_string($val) && trim($val) !== '') {
                $out[$key] = trim($val);
            }
        }

        return $out;
    }
}
