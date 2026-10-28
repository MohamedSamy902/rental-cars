<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MonthLock extends Model
{
    protected $fillable = [
        'year', 'month', 'locked_by', 'locked_at', 'reopen_reason',
    ];

    protected function casts(): array
    {
        return [
            'locked_at' => 'datetime',
        ];
    }

    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }
}
