<?php

namespace App\Services\Operations;

use App\Models\WaitlistRequest;

class WaitlistService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, int $userId): WaitlistRequest
    {
        return WaitlistRequest::query()->create([
            ...$data,
            'status' => 'waiting',
            'created_by' => $userId,
            'expires_at' => now()->addDays(7),
        ])->load('customer');
    }

    /**
     * @return list<WaitlistRequest>
     */
    public function list(): array
    {
        return WaitlistRequest::query()
            ->with(['customer:id,full_name,phone', 'preferredVehicle:id,brand,model,plate'])
            ->orderByRaw('desired_start is null')
            ->orderBy('desired_start')
            ->orderBy('priority', 'desc')
            ->orderBy('id')
            ->get()
            ->all();
    }
}
