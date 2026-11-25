<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sale extends Model
{
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sold_on' => 'date',
            'due_date' => 'date',
            'printed_at' => 'datetime',
            'subtotal' => 'float',
            'discount' => 'float',
            'discount_value' => 'float',
            'courier_charge' => 'float',
            'total' => 'float',
            'paid_amount' => 'float',
            'balance' => 'float',
            'cost_total' => 'float',
            'courier_cost' => 'float',
            'courier_usage' => 'array',
        ];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return HasMany<SaleItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    /** @return HasMany<SalePayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(SalePayment::class)->orderBy('paid_on')->orderBy('id');
    }
}
