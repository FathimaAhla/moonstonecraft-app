<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    /**
     * Default values used when a setting has not been saved yet.
     *
     * @var array<string, string>
     */
    public const DEFAULTS = [
        'business_name' => 'Moonstone Craft',
        'business_address' => '',
        'business_phone' => '',
        'business_email' => '',
        'bank_details' => '',
        'invoice_footer' => 'Thank you for your order.',
        'receipt_footer' => 'Thank you! Wet paint takes 10 minutes to dry.',
        'receipt_width_mm' => '80',
        'default_print_action' => 'invoice_page',
        'currency_symbol' => 'Rs.',
        'invoice_prefix' => 'INV',
        'min_margin_pct' => '20',
        'price_step' => '10',
    ];

    /**
     * All settings merged over their defaults.
     *
     * @return array<string, string>
     */
    public static function allValues(): array
    {
        return array_merge(self::DEFAULTS, self::query()->pluck('value', 'key')->map(fn ($value) => (string) $value)->all());
    }

    public static function value(string $key): string
    {
        return self::allValues()[$key] ?? '';
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public static function store(array $values): void
    {
        foreach ($values as $key => $value) {
            self::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        }
    }
}
