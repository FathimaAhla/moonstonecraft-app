<?php

namespace App\Models;

use Database\Factories\PackRecordFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One packing event: the option's real contents, usage and cost at that moment.
 * Legacy rows (is_legacy) stand for kits packed before contents were recorded.
 */
class PackRecord extends Model
{
    /** @use HasFactory<PackRecordFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'packed_on' => 'date',
            'qty' => 'float',
            'qty_remaining' => 'float',
            'unit_cost' => 'float',
            'is_legacy' => 'boolean',
            'contents' => 'array',
            'usage' => 'array',
            'usage_remaining' => 'array',
        ];
    }

    /** @return BelongsTo<ProductOption, $this> */
    public function option(): BelongsTo
    {
        return $this->belongsTo(ProductOption::class, 'product_option_id');
    }
}
