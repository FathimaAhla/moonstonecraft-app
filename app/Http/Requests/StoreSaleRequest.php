<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreSaleRequest extends FormRequest
{
    /** A discount is a percentage of the price or a fixed Rs amount. */
    public const DISCOUNT_TYPES = ['percent', 'amount'];

    /** Who pays the courier charge, and when. */
    public const COURIER_CHARGE_PAYERS = ['customer_on_delivery', 'customer_bank_transfer', 'business'];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'sold_on' => ['required', 'date'],
            'sale_type' => ['required', Rule::in(['retail', 'wholesale'])],
            'payment_method' => ['required', Rule::in(['cash', 'cod', 'bank'])],
            'delivery_method' => ['required', Rule::in(['pickup', 'courier'])],
            'delivery_address' => ['nullable', 'string'],
            'customer.phone' => [Rule::requiredIf(fn () => $this->input('sale_type') === 'wholesale' || $this->input('payment_method') === 'cod'), 'nullable', 'string', 'max:30'],
            'customer.name' => ['nullable', 'string', 'max:255'],
            'customer.address' => ['nullable', 'string'],
            'customer.city' => ['nullable', 'string', 'max:255'],
            'discount_type' => ['nullable', Rule::in(self::DISCOUNT_TYPES)],
            'discount' => ['nullable', 'numeric', 'min:0', Rule::when($this->input('discount_type') === 'percent', ['max:100'])],
            'offer_name' => ['nullable', 'string', 'max:255'],
            'courier_charge' => ['nullable', 'numeric', 'min:0'],
            'courier_charge_payer' => ['nullable', Rule::in(self::COURIER_CHARGE_PAYERS)],
            'amount_paid' => ['nullable', 'numeric', 'min:0'],
            'due_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.custom' => ['nullable', 'boolean'],
            'items.*.name' => ['nullable', 'string', 'max:255'],
            'items.*.product_option_id' => ['required_unless:items.*.custom,true,1', 'nullable', 'exists:product_options,id'],
            'items.*.parts' => ['required_if:items.*.custom,true,1', 'nullable', 'array'],
            'items.*.parts.*.type' => ['required', Rule::in(['component', 'material'])],
            'items.*.parts.*.id' => ['required', 'integer'],
            'items.*.parts.*.qty' => ['required', 'numeric', 'gt:0'],
            'items.*.qty' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.discount_type' => ['nullable', Rule::in(self::DISCOUNT_TYPES)],
            'items.*.discount_value' => ['nullable', 'numeric', 'min:0'],
            'items.*.offer_name' => ['nullable', 'string', 'max:255'],
            'items.*.assign' => ['nullable', 'array'],
            'items.*.assign.figures' => ['nullable', 'array'],
            'items.*.assign.figures.*.combo_id' => ['nullable', 'integer', 'exists:figure_combos,id'],
            'items.*.assign.figures.*.designs' => ['nullable', 'array'],
            'items.*.assign.figures.*.designs.*' => ['integer', 'min:0', 'max:12'],
            'items.*.assign.figures.*.qty' => ['required', 'numeric', 'gt:0'],
            'items.*.assign.paint' => ['nullable', 'array'],
            'items.*.assign.paint.*.component_id' => ['required', 'integer', 'exists:components,id'],
            'items.*.assign.paint.*.qty' => ['required', 'numeric', 'gt:0'],
        ];
    }

    /**
     * An item discount cannot be more than 100% or more than the item's price.
     *
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            foreach ((array) $this->input('items', []) as $index => $item) {
                $value = (float) ($item['discount_value'] ?? 0);
                $type = $item['discount_type'] ?? null;

                if ($type === 'percent' && $value > 100) {
                    $validator->errors()->add("items.{$index}.discount_value", 'A percentage discount cannot be more than 100%.');
                } elseif ($type === 'amount' && $value > (float) ($item['unit_price'] ?? 0)) {
                    $validator->errors()->add("items.{$index}.discount_value", 'The discount cannot be more than the unit price.');
                }

                foreach (['figures', 'paint'] as $type) {
                    $assigned = collect($item['assign'][$type] ?? [])->sum(fn ($row) => (float) ($row['qty'] ?? 0));

                    if ($assigned > (float) ($item['qty'] ?? 0) + 1e-9) {
                        $validator->errors()->add("items.{$index}.assign.{$type}", 'These rows add up to more kits than the quantity sold.');
                    }
                }
            }
        }];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['customer.phone' => 'customer phone'];
    }
}
