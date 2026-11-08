<?php

namespace App\Services\Accounting;

/**
 * Canonical payment-method keys for journals and reports.
 * Tolerates persisted Arabic aliases without migrating historical rows.
 */
class PaymentMethodNormalizer
{
    /** @var array<string, string> */
    private const ALIASES = [
        'cash' => 'cash',
        'نقدي' => 'cash',
        'نقدا' => 'cash',
        'نقداً' => 'cash',
        'transfer' => 'transfer',
        'تحويل' => 'transfer',
        'card' => 'card',
        'بطاقة' => 'card',
        'فيزا' => 'card',
        'petty_cash' => 'petty_cash',
        'عهدة' => 'petty_cash',
        'wallet' => 'wallet',
        'محفظة' => 'wallet',
        'credit' => 'credit',
        'رصيد' => 'credit',
        'رصيد دائن' => 'credit',
    ];

    /** @var array<string, string> */
    private const LABELS = [
        'cash' => 'نقدي',
        'transfer' => 'تحويل',
        'card' => 'بطاقة',
        'petty_cash' => 'عهدة',
        'wallet' => 'محفظة',
        'credit' => 'رصيد دائن',
        'other' => 'أخرى',
    ];

    public function canonical(string $method): string
    {
        $key = trim($method);
        if ($key === '') {
            return 'other';
        }

        $lower = mb_strtolower($key);

        return self::ALIASES[$key] ?? self::ALIASES[$lower] ?? 'other';
    }

    public function label(string $canonical): string
    {
        return self::LABELS[$canonical] ?? self::LABELS['other'];
    }

    /**
     * Chart account for cash-side posting of a receipt/payout.
     */
    public function cashAccountCode(string $method): string
    {
        return match ($this->canonical($method)) {
            'transfer', 'card' => '1100',
            'petty_cash' => '1500',
            default => '1000', // cash, wallet, other
        };
    }

    /**
     * @return list<string>
     */
    public function reportOrder(): array
    {
        return ['cash', 'transfer', 'card', 'petty_cash', 'wallet', 'credit', 'other'];
    }
}
