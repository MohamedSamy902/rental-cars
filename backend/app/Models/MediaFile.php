<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class MediaFile extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'disk', 'path', 'original_name', 'mime_type', 'kind', 'collection', 'purpose',
        'size_bytes', 'attachable_type', 'attachable_id', 'uploaded_by',
    ];

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function isSensitive(): bool
    {
        return in_array($this->purpose, ['national_id', 'license', 'id_image'], true)
            || in_array($this->collection, ['customers_id'], true);
    }
}
