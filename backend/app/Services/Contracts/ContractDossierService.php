<?php

namespace App\Services\Contracts;

use App\Models\AuditLog;
use App\Models\Contract;
use App\Models\Inspection;
use App\Models\MediaFile;
use App\Models\Payment;
use App\Models\User;

/**
 * Full read model for the contract page: parties, inspections, payment ledger,
 * documents, linked contracts and the lifecycle trail.
 */
class ContractDossierService
{
    public function __construct(private readonly RentalProgressService $progress) {}

    /**
     * @return array<string, mixed>
     */
    public function build(Contract $contract): array
    {
        $contract->load(['customer', 'vehicle', 'deposit.deductions', 'inspections.damages']);

        $depositId = $contract->deposit?->id;
        $payments = Payment::query()
            ->where(function ($q) use ($contract, $depositId) {
                $q->where(fn ($qq) => $qq->where('link_type', 'contract')->where('link_id', $contract->id));
                if ($depositId) {
                    $q->orWhere(fn ($qq) => $qq->where('link_type', 'deposit')->where('link_id', $depositId));
                }
            })
            ->orderBy('paid_at')
            ->get()
            ->map(fn (Payment $p) => [
                'id' => $p->id,
                'date' => $p->paid_at?->toIso8601String(),
                'direction' => $p->direction,
                'method' => $p->method,
                'reference' => $p->reference,
                'link_type' => $p->link_type,
                'notes' => $p->notes,
                'amount' => (int) $p->amount,
            ])->values();

        $inspectionIds = $contract->inspections->pluck('id')->all();
        $documents = MediaFile::query()
            ->where(function ($q) use ($contract, $inspectionIds) {
                $q->where(fn ($qq) => $qq->where('attachable_type', Contract::class)->where('attachable_id', $contract->id));
                if ($inspectionIds) {
                    $q->orWhere(fn ($qq) => $qq->where('attachable_type', Inspection::class)->whereIn('attachable_id', $inspectionIds));
                }
            })
            ->latest('id')
            ->limit(100)
            ->get()
            ->map(fn (MediaFile $m) => [
                'id' => $m->id,
                'kind' => $m->kind,
                'original_name' => $m->original_name,
                'purpose' => $m->purpose,
                'mime_type' => $m->mime_type,
                'url' => url('/api/media/'.$m->id.'/file'),
                'created_at' => $m->created_at?->toIso8601String(),
            ])->values();

        $related = Contract::query()
            ->select('id', 'number', 'status', 'starts_at', 'planned_ends_at', 'extended_from_contract_id', 'swapped_from_contract_id')
            ->where(function ($q) use ($contract) {
                $q->where('extended_from_contract_id', $contract->id)
                    ->orWhere('swapped_from_contract_id', $contract->id)
                    ->orWhereKey(array_filter([$contract->extended_from_contract_id, $contract->swapped_from_contract_id]));
            })
            ->orderBy('starts_at')
            ->get()
            ->map(fn (Contract $c) => [
                'id' => $c->id,
                'number' => $c->number,
                'status' => $c->status,
                'starts_at' => $c->starts_at?->toIso8601String(),
                'planned_ends_at' => $c->planned_ends_at?->toIso8601String(),
                'relation' => match (true) {
                    $c->id === $contract->extended_from_contract_id => 'extended_from',
                    $c->id === $contract->swapped_from_contract_id => 'swapped_from',
                    $c->extended_from_contract_id === $contract->id => 'extension',
                    default => 'swap',
                },
            ])->values();

        $logs = AuditLog::query()
            ->where('record_type', Contract::class)
            ->where('record_id', $contract->id)
            ->latest('id')
            ->limit(50)
            ->get();
        $users = User::query()->whereKey($logs->pluck('user_id')->filter()->unique()->all())->pluck('name', 'id');
        $timeline = $logs->map(fn (AuditLog $log) => [
            'id' => $log->id,
            'action' => $log->action,
            'at' => $log->created_at?->toIso8601String(),
            'user' => $log->user_id ? $users->get($log->user_id) : null,
        ])->values();

        $in = (int) $payments->where('direction', 'in')->where('link_type', 'contract')->sum('amount');
        $out = (int) $payments->where('direction', 'out')->where('link_type', 'contract')->sum('amount');
        $total = (int) ($contract->final_total ?? 0);

        return [
            ...$contract->toArray(),
            'progress' => $this->progress->forContract($contract),
            'payments' => $payments,
            'documents' => $documents,
            'related' => $related,
            'timeline' => $timeline,
            'ledger' => [
                'contract_total' => $total,
                'paid' => $in - $out,
                'balance' => max(0, $total - ($in - $out)),
                'deposit_held' => $contract->deposit && in_array($contract->deposit->status, ['received', 'partial'], true)
                    ? $contract->deposit->remaining()
                    : 0,
            ],
        ];
    }
}
