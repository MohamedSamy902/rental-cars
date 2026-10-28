<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PrintSnapshot extends Model
{
    protected $fillable = [
        'type', 'record_type', 'record_id', 'payload', 'printed_by', 'printed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'printed_at' => 'datetime',
        ];
    }
}
