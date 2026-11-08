<?php

namespace App\Services\Contracts;

use App\Models\AuditLog;
use App\Models\Contract;
use App\Models\Inspection;
use App\Models\MediaFile;
use App\Models\Payment;
use App\Models\Violation;
use App\Services\Operations\AuditNarrator;

/**
 * Full read model for the contract page: parties, inspections, payment ledger,
 * documents, linked contracts and the lifecycle trail.
 */
class ContractDossierService
{
    public function __construct(
        private readonly RentalProgressService $progress,
        private readonly ContractLedgerMath $ledgerMath,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(Contract $contract): array
    {
        $contract->load(['customer', 'vehicle', 'deposit.deductions', 'inspections.damages', 'salesUser:id,name']);

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
        $narrator = app(AuditNarrator::class);
        $actors = $narrator->resolveActors($logs);
        $timeline = $logs->map(function (AuditLog $log) use ($actors, $narrator) {
            $actor = $log->user_id ? ($actors[(int) $log->user_id] ?? null) : null;
            $name = $actor['name'] ?? null;
            $title = $actor['role_title'] ?? null;

            return [
                'id' => $log->id,
                'action' => $log->action,
                'summary' => $narrator->summarize($log, $name, $title),
                'at' => $log->created_at?->toIso8601String(),
                'user' => $actor['phrase'] ?? $name,
                'user_name' => $name,
                'user_role_title' => $title,
            ];
        })->values();

        $ledgerTotals = $this->ledgerMath->forContract($contract);
        $total = $ledgerTotals['total'];
        $in = $ledgerTotals['paid'];

        $openViolations = Violation::query()
            ->with('creator:id,name')
            ->where('contract_id', $contract->id)
            ->open()
            ->orderByDesc('occurred_at')
            ->get()
            ->map(fn (Violation $v) => [
                'id' => $v->id,
                'amount' => (int) $v->amount,
                'occurred_at' => $v->occurred_at?->toDateString(),
                'status' => $v->status,
                'photo_paths' => $v->resolvedPhotoPaths(),
                'created_by_name' => $v->creator?->name,
            ])->values();
        $openViolationsTotal = (int) $openViolations->sum('amount');

        return [
            ...$contract->toArray(),
            'created_by' => $contract->salesUser?->name,
            'sales_user' => $contract->salesUser
                ? ['id' => $contract->salesUser->id, 'name' => $contract->salesUser->name]
                : null,
            'progress' => $this->progress->forContract($contract),
            'payments' => $payments,
            'documents' => $documents,
            'related' => $related,
            'timeline' => $timeline,
            'open_violations' => $openViolations,
            'open_violations_total' => $openViolationsTotal,
            'ledger' => [
                'contract_total' => $total,
                'paid' => $in,
                'balance' => $ledgerTotals['balance'],
                'deposit_held' => $contract->deposit && in_array($contract->deposit->status, ['received', 'partial'], true)
                    ? $contract->deposit->remaining()
                    : 0,
                // متابعة تشغيلية فقط — لا يدخل إجمالي العقد المحاسبي
                'violations_due' => $openViolationsTotal,
            ],
        ];
    }
}
