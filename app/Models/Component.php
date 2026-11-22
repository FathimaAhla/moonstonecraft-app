<?php

namespace App\Models;

use Database\Factories\ComponentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A part you make and cost on its own (figure, painting set, packing), built from making stages.
 */
class Component extends Model
{
    /** @use HasFactory<ComponentFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'yield_qty' => 'float',
            'wastage_pct' => 'float',
            'keeps_stock' => 'boolean',
            'stock' => 'float',
            'reorder_level' => 'float',
            'active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return HasMany<ComponentStage, $this> */
    public function stages(): HasMany
    {
        return $this->hasMany(ComponentStage::class)->orderBy('sort_order')->orderBy('id');
    }

    /** @return HasMany<StockMovement, $this> */
    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }
}
