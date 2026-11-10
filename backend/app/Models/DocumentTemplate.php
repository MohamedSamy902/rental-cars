<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentTemplate extends Model
{
    public const TYPE_CONTRACT = 'contract';

    public const TYPE_HANDOVER_RECEIPT = 'handover_receipt';

    public const TYPE_OWNER_AGREEMENT = 'owner_agreement';
    /** عقد اعتماد بين المركز ومالك العربية الأساسي (ليس عقد تأجير عميل). */
    public const TYPE_CUSTODY_AGREEMENT = 'custody_agreement';

    protected $fillable = [
        'type',
        'title',
        'content',
        'format',
        'storage_path',
        'version',
        'is_active',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'version' => 'integer',
        ];
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public static function allowedTypes(): array
    {
        return [self::TYPE_CONTRACT, self::TYPE_HANDOVER_RECEIPT, self::TYPE_OWNER_AGREEMENT, self::TYPE_CUSTODY_AGREEMENT];
    }
}
