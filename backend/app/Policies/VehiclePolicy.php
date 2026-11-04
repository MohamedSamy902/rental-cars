<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Vehicle;

class VehiclePolicy
{
    public function view(User $user, Vehicle $vehicle): bool
    {
        return $user->hasPermission('vehicles.view');
    }

    public function update(User $user, Vehicle $vehicle): bool
    {
        return $user->hasPermission('vehicles.update');
    }

    public function delete(User $user, Vehicle $vehicle): bool
    {
        return $user->hasPermission('vehicles.archive');
    }
}
