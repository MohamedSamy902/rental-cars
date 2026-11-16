<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class DepositDeduction extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'deposit_id', 'reason', 'amount', 'damage_id', 'violation_id', 'user_id', 'deducted_at', 'attachment_path',
    ];

    protected function casts(): array
    {
        return ['deducted_at' => 'datetime'];
    }
}
