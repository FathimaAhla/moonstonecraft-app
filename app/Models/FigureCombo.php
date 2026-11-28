<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A saved mix of figure designs from one kit (theme), such as Car + Bike + Bus. Made-up sets are
 * counted on the set part behind it; making sets takes the single figures out of stock.
 */
class FigureCombo extends Model
{
    protected $guarded = ['id'];

    /**
     * The kit (theme) whose figures this set uses.
     *
     * @return BelongsTo<Product, $this>
     */
    public function kit(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /** @return HasMany<FigureComboItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(FigureComboItem::class)->orderBy('id');
    }

    /**
     * The part that holds this combination's made-up set stock.
     *
     * @return BelongsTo<Component, $this>
     */
    public function setPart(): BelongsTo
    {
        return $this->belongsTo(Component::class, 'component_id');
    }
}
