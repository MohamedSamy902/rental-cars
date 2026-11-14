<?php

namespace App\Support;

use App\Models\User;
use DomainException;

/**
 * صرف العهدة: الموظف يصرف على عهدته المرتبطة بحسابه فقط.
 * إدارة العهدة (`petty_cash.manage`) وحدها تخصم عهدة موظف آخر.
 */
final class PettyCashSpendGuard
{
    public static function assertMayCharge(?User $actor, int $custodyEmployeeId): void
    {
        if ($custodyEmployeeId < 1) {
            throw new DomainException('موظف العهدة مطلوب عند الصرف من العهدة.');
        }

        if (! $actor) {
            throw new DomainException('تعذّر التحقق من صلاحية الصرف من العهدة.');
        }

        if ($actor->hasPermission('petty_cash.manage')) {
            return;
        }

        $own = (int) ($actor->employee_id ?? 0);
        if ($own < 1) {
            throw new DomainException('حسابك غير مرتبط بموظف — الصرف من عهدة موظف آخر يحتاج صلاحية إدارة العهدة.');
        }

        if ($own !== $custodyEmployeeId) {
            throw new DomainException('لا يجوز صرف عهدة موظف آخر — الإدارة فقط تملك هذه الصلاحية.');
        }
    }

    public static function assertMayChargeByUserId(?int $userId, int $custodyEmployeeId): void
    {
        $actor = $userId ? User::query()->find($userId) : null;
        self::assertMayCharge($actor, $custodyEmployeeId);
    }
}
