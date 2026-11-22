<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockCheckLine extends Model
{
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'system_qty' => 'float',
            'counted_qty' => 'float',
            'unit_cost' => 'float',
        ];
    }

    /** @return BelongsTo<StockCheck, $this> */
    public function check(): BelongsTo
    {
        return $this->belongsTo(StockCheck::class, 'stock_check_id');
    }

    /**
     * Counted minus system: negative is missing, positive is found. Null until counted.
     */
    public function difference(): ?float
    {
        return $this->counted_qty === null ? null : round($this->counted_qty - $this->system_qty, 3);
    }
}
