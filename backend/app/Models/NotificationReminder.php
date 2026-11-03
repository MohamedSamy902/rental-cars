<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationReminder extends Model
{
    protected $fillable = [
        'rule_key', 'subject_type', 'subject_id', 'last_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'last_sent_at' => 'datetime',
        ];
    }
}
