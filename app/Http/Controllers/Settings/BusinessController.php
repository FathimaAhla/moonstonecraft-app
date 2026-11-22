<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class BusinessController extends Controller
{
    public function edit(): Response
    {
        return Inertia::render('settings/business', ['settings' => Setting::allValues()]);
    }

    public function update(Request $request): RedirectResponse
    {
        Setting::store($request->validate([
            'business_name' => ['required', 'string', 'max:255'],
            'business_address' => ['nullable', 'string'],
            'business_phone' => ['nullable', 'string', 'max:50'],
            'business_email' => ['nullable', 'email'],
            'bank_details' => ['nullable', 'string'],
            'invoice_footer' => ['nullable', 'string'],
            'receipt_footer' => ['nullable', 'string'],
            'receipt_width_mm' => ['required', Rule::in(['80', '58'])],
            'default_print_action' => ['required', Rule::in(['receipt', 'invoice_page', 'none'])],
            'invoice_prefix' => ['required', 'string', 'max:10', 'alpha'],
            'min_margin_pct' => ['required', 'numeric', 'min:0', 'max:100'],
            'price_step' => ['required', 'integer', 'min:1'],
        ]));
        $this->toast('Business settings saved.');

        return back();
    }
}
