<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Payment extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'amount', 'paid_at', 'method', 'direction', 'reference', 'link_type', 'link_id', 'user_id', 'notes',
    ];

    protected function casts(): array
    {
        return ['paid_at' => 'datetime'];
    }
}
