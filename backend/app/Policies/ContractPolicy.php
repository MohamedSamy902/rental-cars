<?php

namespace App\Policies;

use App\Models\Contract;
use App\Models\User;

class ContractPolicy
{
    public function view(User $user, Contract $contract): bool
    {
        if (! $user->hasPermission('contracts.view')) {
            return false;
        }

        // مندوب المبيعات يرى عقوده فقط ما لم يملك صلاحية أوسع للتشغيل.
        if ($user->role?->name === 'sales' && ! $user->hasPermission('contracts.approve')) {
            return (int) $contract->sales_user_id === (int) $user->id;
        }

        return true;
    }

    public function update(User $user, Contract $contract): bool
    {
        return $this->view($user, $contract) && $user->hasPermission('contracts.update');
    }
}
