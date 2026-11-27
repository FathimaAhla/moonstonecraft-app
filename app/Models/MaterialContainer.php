<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One opened bottle / can of a material whose use per kit cannot be measured.
 */
class MaterialContainer extends Model
{
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size' => 'float',
            'cost' => 'float',
            'opened_on' => 'date:Y-m-d',
            'finished_on' => 'date:Y-m-d',
            'kits_made' => 'float',
        ];
    }

    /** @return BelongsTo<Material, $this> */
    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }
}
