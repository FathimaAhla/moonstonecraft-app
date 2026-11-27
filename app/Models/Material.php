<?php

namespace App\Models;

use Database\Factories\MaterialFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Material extends Model
{
    /** @use HasFactory<MaterialFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_purchasable' => true,
        'tracks_stock' => true,
        'track_by_container' => false,
        'cost_source' => 'purchase',
        'purchase_conversion' => 1,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stock' => 'float',
            'unit_cost' => 'float',
            'reorder_level' => 'float',
            'is_purchasable' => 'boolean',
            'tracks_stock' => 'boolean',
            'track_by_container' => 'boolean',
            'purchase_conversion' => 'float',
            'purchase_unit_cost' => 'float',
            'manual_unit_cost' => 'float',
        ];
    }

    /**
     * `unit_cost` is always the ACTIVE cost. Purchase-derived and manual costs are kept apart
     * and `cost_source` picks which one is active, so neither overwrites the other.
     */
    protected static function booted(): void
    {
        static::saving(function (Material $material) {
            if (! $material->exists) {
                if ($material->type === 'overhead' && $material->is_purchasable && $material->tracks_stock) {
                    $material->is_purchasable = false;
                    $material->tracks_stock = false;
                    $material->cost_source = 'manual';
                    $material->manual_unit_cost ??= $material->unit_cost;
                } elseif (! array_key_exists('purchase_unit_cost', $material->getAttributes()) && $material->cost_source !== 'manual') {
                    $material->purchase_unit_cost = $material->unit_cost;
                }
            }

            if (! $material->tracks_stock) {
                $material->track_by_container = false;
            }

            $material->type = $material->is_purchasable || $material->tracks_stock ? 'material' : 'overhead';

            if ($material->cost_source === 'manual') {
                $material->unit_cost = $material->manual_unit_cost ?? 0;
            } elseif ($material->isDirty('unit_cost') && ! $material->isDirty('purchase_unit_cost') && ! $material->isDirty('cost_source')) {
                $material->purchase_unit_cost = $material->unit_cost;
            } else {
                $material->unit_cost = $material->purchase_unit_cost;
            }
        });
    }

    /** @return HasMany<PurchaseItem, $this> */
    public function purchaseItems(): HasMany
    {
        return $this->hasMany(PurchaseItem::class);
    }

    /** @return HasMany<MaterialPackSize, $this> */
    public function packSizes(): HasMany
    {
        return $this->hasMany(MaterialPackSize::class)->orderBy('size');
    }

    /** @return HasMany<MaterialContainer, $this> */
    public function containers(): HasMany
    {
        return $this->hasMany(MaterialContainer::class);
    }

    /** @return HasMany<StockMovement, $this> */
    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /**
     * Whether recipes and sales automatically deduct this item from stock. Container-tracked
     * items leave stock when a container is opened instead.
     */
    public function deductsStock(): bool
    {
        return $this->tracks_stock && ! $this->track_by_container;
    }

    public function usesManualCost(): bool
    {
        return $this->cost_source === 'manual';
    }
}
