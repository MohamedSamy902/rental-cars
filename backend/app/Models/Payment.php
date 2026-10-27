<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $fillable = [
        'amount', 'paid_at', 'method', 'direction', 'reference', 'link_type', 'link_id', 'user_id', 'notes',
    ];

    protected function casts(): array
    {
        return ['paid_at' => 'datetime'];
    }
}
