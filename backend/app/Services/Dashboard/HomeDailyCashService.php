<?php

namespace App\Services\Dashboard;

use App\Models\Deposit;
use App\Models\Expense;
use App\Models\OwnerPayout;
use App\Models\Payment;
use App\Models\PayrollPayout;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * سجل حركة الفلوس ليوم واحد — مقبوضات/مدفوعات + مصروفات (+ رواتب/ملاك عند الصلاحية).
 * مفلتر بصلاحيات المستخدم (payments/accounting · expenses · hr · owners).
 */
class HomeDailyCashService
{
    public const LIMIT = 40;

    public const ACCOUNTS_LIMIT = 120;

    /**
     * @return array{
     *   date: string,
     *   totals: array{in:int,out:int,net:int,count:int},
     *   entries: list<array<string,mixed>>
     * }|null
     */
    public function forUser(?User $user, ?Carbon $day = null, int $limit = self::LIMIT): ?array
    {
        if (! $user) {
            return null;
        }

        $canPayments = $this->can($user, 'payments.view') || $this->can($user, 'accounting.view');
        $canExpenses = $this->can($user, 'expenses.view');
        $canContracts = $this->can($user, 'contracts.view');
        $canPayroll = $this->can($user, 'hr.salaries') || $this->can($user, 'hr.payroll_approve') || $this->can($user, 'accounting.view');
        $canOwners = $this->can($user, 'owners.view') || $this->can($user, 'owners.payout') || $this->can($user, 'accounting.view');
        if (! $canPayments && ! $canExpenses && ! $canPayroll && ! $canOwners) {
            return null;
        }

        $day = ($day ? $day->copy() : now())->timezone(config('app.timezone', 'Africa/Cairo'))->startOfDay();
        $dateKey = $day->toDateString();
        $entries = [];
        $fetchLimit = max($limit, self::LIMIT);

        if ($canPayments) {
            $payments = Payment::query()
                ->whereDate('paid_at', $dateKey)
                ->orderByDesc('paid_at')
                ->orderByDesc('id')
                ->limit($fetchLimit)
                ->get();

            $depositIds = $payments->where('link_type', 'deposit')->pluck('link_id')->filter()->map(fn ($id) => (int) $id)->all();
            $contractByDeposit = $depositIds === []
                ? collect()
                : Deposit::query()->whereIn('id', $depositIds)->pluck('contract_id', 'id');

            foreach ($payments as $p) {
                $direction = $p->direction === 'in' ? 'in' : 'out';
                $contractId = $this->contractId($p, $contractByDeposit);
                $entries[] = [
                    'id' => 'payment:'.$p->id,
                    'source' => 'payment',
                    'direction' => $direction,
                    'amount' => (int) $p->amount,
                    'title' => $this->paymentTitle($p),
                    'subtitle' => $this->paymentSubtitle($p, $canContracts),
                    'method' => $p->method,
                    'at' => $p->paid_at?->toIso8601String(),
                    'href' => $this->paymentHref($p, $canContracts, $contractId),
                ];
            }
        }

        if ($canExpenses) {
            $expenses = Expense::query()
                ->with(['vehicle:id,brand,model,plate'])
                ->whereDate('spent_on', $dateKey)
                ->orderByDesc('id')
                ->limit($fetchLimit)
                ->get();

            foreach ($expenses as $e) {
                $entries[] = [
                    'id' => 'expense:'.$e->id,
                    'source' => 'expense',
                    'direction' => 'out',
                    'amount' => (int) $e->amount,
                    'title' => $e->title ?: 'مصروف',
                    'subtitle' => $this->expenseSubtitle($e),
                    'method' => $e->payment_method,
                    'at' => $e->spent_on?->startOfDay()->toIso8601String(),
                    'href' => '/expenses',
                ];
            }
        }

        if ($canPayroll) {
            $payouts = PayrollPayout::query()
                ->with('line.employee:id,name')
                ->whereDate('paid_on', $dateKey)
                ->orderByDesc('id')
                ->limit($fetchLimit)
                ->get();
            foreach ($payouts as $p) {
                $entries[] = [
                    'id' => 'payroll:'.$p->id,
                    'source' => 'payroll',
                    'direction' => 'out',
                    'amount' => (int) $p->amount,
                    'title' => 'صرف راتب',
                    'subtitle' => trim(($p->line?->employee?->name ?: 'موظف').($p->method ? ' · '.$p->method : '')),
                    'method' => $p->method,
                    'at' => $p->paid_on?->startOfDay()->toIso8601String(),
                    'href' => '/hr',
                ];
            }
        }

        if ($canOwners) {
            $ownerPays = OwnerPayout::query()
                ->with(['owner:id,full_name', 'vehicle:id,plate,brand,model'])
                ->whereDate('paid_on', $dateKey)
                ->orderByDesc('id')
                ->limit($fetchLimit)
                ->get();
            foreach ($ownerPays as $p) {
                $plate = $p->vehicle?->plate ?: trim(($p->vehicle?->brand ?? '').' '.($p->vehicle?->model ?? ''));
                $entries[] = [
                    'id' => 'owner_payout:'.$p->id,
                    'source' => 'owner_payout',
                    'direction' => 'out',
                    'amount' => (int) $p->amount,
                    'title' => 'صرف لمالك',
                    'subtitle' => trim(($p->owner?->full_name ?: 'مالك').($plate !== '' ? ' · '.$plate : '').($p->payment_method ? ' · '.$p->payment_method : '')),
                    'method' => $p->payment_method,
                    'at' => $p->paid_on?->startOfDay()->toIso8601String(),
                    'href' => $p->owner_id ? '/owners/'.$p->owner_id : '/owners',
                ];
            }
        }

        usort($entries, function (array $a, array $b) {
            $ta = $a['at'] ?? '';
            $tb = $b['at'] ?? '';
            if ($ta !== $tb) {
                return $tb <=> $ta;
            }

            return ($b['id'] ?? '') <=> ($a['id'] ?? '');
        });

        $entries = array_slice($entries, 0, $limit);

        $in = 0;
        $out = 0;
        foreach ($entries as $row) {
            if (($row['direction'] ?? '') === 'in') {
                $in += (int) $row['amount'];
            } else {
                $out += (int) $row['amount'];
            }
        }

        return [
            'date' => $dateKey,
            'totals' => [
                'in' => $in,
                'out' => $out,
                'net' => $in - $out,
                'count' => count($entries),
            ],
            'entries' => array_values($entries),
        ];
    }

    private function can(User $user, string $permission): bool
    {
        return $user->hasPermission($permission);
    }

    private function paymentTitle(Payment $p): string
    {
        return match ($p->link_type) {
            'contract' => $p->direction === 'in' ? 'قبض إيجار / دفعة عقد' : 'مدفوع مرتبط بعقد',
            'deposit' => $p->direction === 'in' ? 'قبض تأمين' : 'رد تأمين',
            'expense' => 'مدفوع مصروف',
            'customer_credit' => $p->direction === 'in' ? 'رصيد دائن — دخول' : 'استخدام رصيد دائن',
            default => $p->direction === 'in' ? 'مقبوض' : 'مدفوع',
        };
    }

    private function paymentSubtitle(Payment $p, bool $canContracts): string
    {
        $parts = [];
        if ($p->reference) {
            $parts[] = $this->visibleText((string) $p->reference, $canContracts);
        }
        if ($p->link_type) {
            $parts[] = $this->linkLabel($p, $canContracts);
        }
        if ($p->method) {
            $parts[] = (string) $p->method;
        }
        if ($p->notes) {
            $notes = $this->visibleText((string) $p->notes, $canContracts);
            if ($notes !== '') {
                $parts[] = $notes;
            }
        }

        $parts = array_values(array_filter($parts, fn ($part) => $part !== ''));

        return $parts !== [] ? implode(' · ', $parts) : '—';
    }

    /**
     * @param  Collection<int, int|string|null>  $contractByDeposit
     */
    private function contractId(Payment $p, $contractByDeposit): ?int
    {
        if ($p->link_type === 'contract' && $p->link_id) {
            return (int) $p->link_id;
        }
        if ($p->link_type === 'deposit' && $p->link_id) {
            $id = $contractByDeposit[(int) $p->link_id] ?? null;

            return $id ? (int) $id : null;
        }

        return null;
    }

    private function paymentHref(Payment $p, bool $canContracts, ?int $contractId): ?string
    {
        if (in_array($p->link_type, ['contract', 'deposit'], true)) {
            if (! $canContracts || ! $contractId) {
                return null;
            }

            return '/contracts/'.$contractId;
        }

        return match ($p->link_type) {
            'expense' => '/expenses',
            default => '/accounts',
        };
    }

    private function visibleText(string $text, bool $canContracts): string
    {
        if ($canContracts) {
            return $text;
        }

        $text = preg_replace('/\s*(?:للعقد|على العقد|من العقد)\s+CNT-\d+/u', '', $text) ?? $text;
        $text = preg_replace('/CNT-\d+/', '', $text) ?? $text;
        $text = preg_replace('/\s{2,}/u', ' ', $text) ?? $text;

        return trim($text, " \t·");
    }

    private function linkLabel(Payment $p, bool $canContracts): string
    {
        if ($p->link_type === 'contract' && ! $canContracts) {
            return 'دفعة عقد';
        }

        return $this->linkTypeLabel((string) $p->link_type).($p->link_id ? ' #'.$p->link_id : '');
    }

    private function linkTypeLabel(string $type): string
    {
        return match ($type) {
            'contract' => 'عقد',
            'deposit' => 'تأمين',
            'expense' => 'مصروف',
            'customer_credit' => 'رصيد دائن',
            default => $type,
        };
    }

    private function expenseSubtitle(Expense $e): string
    {
        $parts = [];
        if ($e->category) {
            $parts[] = (string) $e->category;
        }
        if ($e->vehicle) {
            $plate = $e->vehicle->plate ?: trim(($e->vehicle->brand ?? '').' '.($e->vehicle->model ?? ''));
            if ($plate !== '') {
                $parts[] = $plate;
            }
        }
        if ($e->from_petty_cash) {
            $parts[] = 'من العهدة';
        }
        if ($e->payment_method) {
            $parts[] = (string) $e->payment_method;
        }

        return $parts !== [] ? implode(' · ', $parts) : 'مصروف';
    }
}
