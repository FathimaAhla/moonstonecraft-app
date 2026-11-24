<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePurchaseRequest extends FormRequest
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
            'purchased_on' => ['required', 'date'],
            'supplier_id' => ['nullable', 'exists:suppliers,id'],
            'supplier_name' => ['nullable', 'string', 'max:255'],
            'transport_cost' => ['nullable', 'numeric', 'min:0'],
            'refreshment_cost' => ['nullable', 'numeric', 'min:0'],
            'other_cost' => ['nullable', 'numeric', 'min:0'],
            'refreshment_as_stock_cost' => ['boolean'],
            'notes' => ['nullable', 'string'],
            'receipt' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.material_id' => ['required', Rule::exists('materials', 'id')->where('is_purchasable', true)],
            'lines.*.purchase_unit' => ['nullable', 'string', 'max:20'],
            'lines.*.conversion_factor' => ['nullable', 'numeric', 'gt:0'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
            'lines.*.line_cost' => ['required', 'numeric', 'min:0'],
        ];
    }
}
