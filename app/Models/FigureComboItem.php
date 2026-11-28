<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A figure design in a combination, and how many of it (Car × 2).
 */
class FigureComboItem extends Model
{
    protected $guarded = ['id'];

    /** @return BelongsTo<FigureCombo, $this> */
    public function combo(): BelongsTo
    {
        return $this->belongsTo(FigureCombo::class, 'figure_combo_id');
    }

    /** @return BelongsTo<Component, $this> */
    public function component(): BelongsTo
    {
        return $this->belongsTo(Component::class);
    }
}
