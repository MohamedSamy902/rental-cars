<?php

namespace App\Services\Dashboard;

use App\Models\Expense;
use App\Models\Payment;
use App\Models\User;
use Carbon\Carbon;

/**
 * سجل حركة الفلوس ليوم واحد على لوحة اليوم — مقبوضات/مدفوعات + مصروفات.
 * مفلتر بصلاحيات المستخدم (payments/accounting · expenses).
 */
class HomeDailyCashService
{
    public const LIMIT = 40;

    /**
     * @return array{
     *   date: string,
     *   totals: array{in:int,out:int,net:int,count:int},
     *   entries: list<array<string,mixed>>
     * }|null
     */
    public function forUser(?User $user, ?Carbon $day = null): ?array
    {
        if (! $user) {
            return null;
        }

        $canPayments = $this->can($user, 'payments.view') || $this->can($user, 'accounting.view');
        $canExpenses = $this->can($user, 'expenses.view');
        if (! $canPayments && ! $canExpenses) {
            return null;
        }

        $day = ($day ? $day->copy() : now())->timezone(config('app.timezone', 'Africa/Cairo'))->startOfDay();
        $dateKey = $day->toDateString();
        $entries = [];

        if ($canPayments) {
            $payments = Payment::query()
                ->whereDate('paid_at', $dateKey)
                ->orderByDesc('paid_at')
                ->orderByDesc('id')
                ->limit(self::LIMIT)
                ->get();

            foreach ($payments as $p) {
                $direction = $p->direction === 'in' ? 'in' : 'out';
                $entries[] = [
                    'id' => 'payment:'.$p->id,
                    'source' => 'payment',
                    'direction' => $direction,
                    'amount' => (int) $p->amount,
                    'title' => $this->paymentTitle($p),
                    'subtitle' => $this->paymentSubtitle($p),
                    'method' => $p->method,
                    'at' => $p->paid_at?->toIso8601String(),
                    'href' => $this->paymentHref($p),
                ];
            }
        }

        if ($canExpenses) {
            $expenses = Expense::query()
                ->with(['vehicle:id,brand,model,plate'])
                ->whereDate('spent_on', $dateKey)
                ->orderByDesc('id')
                ->limit(self::LIMIT)
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

        usort($entries, function (array $a, array $b) {
            $ta = $a['at'] ?? '';
            $tb = $b['at'] ?? '';
            if ($ta !== $tb) {
                return $tb <=> $ta;
            }

            return ($b['id'] ?? '') <=> ($a['id'] ?? '');
        });

        $entries = array_slice($entries, 0, self::LIMIT);

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

    private function paymentSubtitle(Payment $p): string
    {
        $parts = [];
        if ($p->reference) {
            $parts[] = (string) $p->reference;
        }
        if ($p->link_type) {
            $parts[] = $this->linkTypeLabel($p->link_type).($p->link_id ? ' #'.$p->link_id : '');
        }
        if ($p->method) {
            $parts[] = (string) $p->method;
        }
        if ($p->notes) {
            $parts[] = (string) $p->notes;
        }

        return $parts !== [] ? implode(' · ', $parts) : '—';
    }

    private function paymentHref(Payment $p): string
    {
        return match ($p->link_type) {
            'contract' => $p->link_id ? '/contracts/'.$p->link_id : '/accounts',
            'deposit' => $p->link_id ? '/contracts/'.$p->link_id : '/accounts',
            'expense' => '/expenses',
            default => '/accounts',
        };
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
