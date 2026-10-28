<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PettyCashMovement extends Model
{
    protected $fillable = [
        'petty_cash_account_id', 'type', 'amount', 'moved_on', 'work_order_id', 'performed_by', 'notes',
    ];

    protected function casts(): array
    {
        return ['moved_on' => 'date'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(PettyCashAccount::class, 'petty_cash_account_id');
    }
}
