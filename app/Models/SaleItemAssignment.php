<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Figures or a paint set given to kits: "Car + Bike + Bus × 2". On a sold line it says what went
 * into those kits. On a packing (no sale item) it records what was packed, and qty_remaining is
 * how many of those packed kits have not been matched to a sale yet.
 */
class SaleItemAssignment extends Model
{
    public const TYPES = ['figures', 'paint'];

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['qty' => 'float', 'qty_remaining' => 'float', 'unit_cost' => 'float', 'usage' => 'array'];
    }

    /** @return BelongsTo<SaleItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class, 'sale_item_id');
    }

    /** @return BelongsTo<FigureCombo, $this> */
    public function combo(): BelongsTo
    {
        return $this->belongsTo(FigureCombo::class, 'figure_combo_id');
    }

    /** @return BelongsTo<Component, $this> */
    public function paintSet(): BelongsTo
    {
        return $this->belongsTo(Component::class, 'component_id');
    }

    /** @return BelongsTo<PackRecord, $this> */
    public function packRecord(): BelongsTo
    {
        return $this->belongsTo(PackRecord::class);
    }
}
