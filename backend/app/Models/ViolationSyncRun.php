<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ViolationSyncRun extends Model
{
    protected $fillable = [
        'channel', 'status', 'rows_seen', 'rows_created', 'rows_skipped',
        'meta', 'started_by', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'finished_at' => 'datetime',
        ];
    }

    public function starter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    public function violations(): HasMany
    {
        return $this->hasMany(Violation::class, 'sync_run_id');
    }
}
