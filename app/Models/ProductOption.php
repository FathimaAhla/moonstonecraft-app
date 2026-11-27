<?php

namespace App\Models;

use Database\Factories\ProductOptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductOption extends Model
{
    /** @use HasFactory<ProductOptionFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'retail_price' => 'float',
            'wholesale_price' => 'float',
            'courier_fee_estimate' => 'float',
            'figures_per_kit' => 'integer',
            'paint_pots_per_kit' => 'integer',
            'stock' => 'float',
            'reorder_level' => 'float',
            'active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<Component, $this> */
    public function courierPacking(): BelongsTo
    {
        return $this->belongsTo(Component::class, 'courier_packing_component_id');
    }

    /** @return HasMany<OptionPart, $this> */
    public function parts(): HasMany
    {
        return $this->hasMany(OptionPart::class);
    }

    /** @return HasMany<StockMovement, $this> */
    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }
}
