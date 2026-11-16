<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class JournalEntry extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'entry_date', 'narration', 'source', 'posted_by',
    ];

    protected function casts(): array
    {
        return [
            'entry_date' => 'date:Y-m-d',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    /**
     * Soft-delete while freeing unique `source` so the same key can be re-posted (expense rewrite, etc.).
     */
    public function softDeleteReleasingSource(): void
    {
        if ($this->trashed()) {
            return;
        }

        JournalLine::query()
            ->where('journal_entry_id', $this->id)
            ->orderBy('id')
            ->each(function (JournalLine $line): void {
                if (! $line->trashed()) {
                    $line->delete();
                }
            });

        $source = (string) $this->source;
        if ($source !== '' && ! str_contains($source, '__del_')) {
            $this->forceFill([
                'source' => mb_substr($source, 0, 180).'__del_'.$this->id,
            ])->save();
        }

        $this->delete();
    }
}
