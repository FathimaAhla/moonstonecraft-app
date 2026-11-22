<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSaleRequest extends FormRequest
{
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
            'discount' => ['nullable', 'numeric', 'min:0'],
            'courier_charge' => ['nullable', 'numeric', 'min:0'],
            'amount_paid' => ['nullable', 'numeric', 'min:0'],
            'due_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_option_id' => ['required', 'exists:product_options,id'],
            'items.*.qty' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['customer.phone' => 'customer phone'];
    }
}
