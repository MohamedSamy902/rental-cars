<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IdempotencyKey extends Model
{
    protected $fillable = [
        'key', 'user_id', 'method', 'path', 'request_hash', 'status',
        'response_code', 'response_body', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'response_code' => 'integer',
        ];
    }
}
