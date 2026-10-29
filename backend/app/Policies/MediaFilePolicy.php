<?php

namespace App\Policies;

use App\Models\MediaFile;
use App\Models\User;

class MediaFilePolicy
{
    public function view(User $user, MediaFile $medium): bool
    {
        if ($medium->isSensitive()) {
            return $user->hasPermission('sensitive.view_id_images');
        }

        return $user->hasPermission('media.view')
            || $user->hasPermission('customers.view')
            || $user->hasPermission('vehicles.view')
            || $user->hasPermission('contracts.view');
    }

    public function delete(User $user, MediaFile $medium): bool
    {
        if ($medium->uploaded_by && (int) $medium->uploaded_by === (int) $user->id) {
            return true;
        }

        return $user->hasPermission('media.delete')
            || $user->hasPermission('customers.update')
            || $user->hasPermission('vehicles.update')
            || $user->hasPermission('settings.update');
    }
}
