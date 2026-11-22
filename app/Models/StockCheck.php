<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A counting session: system quantities are frozen when it starts, and completing it
 * writes each difference to the stock ledger as missing or found.
 */
class StockCheck extends Model
{
    public const SCOPES = ['all', 'materials', 'components', 'kits'];

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'checked_on' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    /** @return HasMany<StockCheckLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(StockCheckLine::class)->orderBy('item_type')->orderBy('name');
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }
}
