<?php

namespace App\Http\Controllers;

use App\Models\Component;
use App\Models\Material;
use App\Models\WastageLog;
use App\Services\WastageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class WastageController extends Controller
{
    public function index(Request $request): Response
    {
        $month = $request->string('month')->toString() ?: now()->format('Y-m');
        $start = Carbon::parse($month.'-01');

        $logs = WastageLog::query()->with('material:id,name,unit', 'component:id,code,name')
            ->whereBetween('logged_on', [$start->toDateString(), $start->copy()->endOfMonth()->toDateString()])
            ->latest('logged_on')->latest('id')->get();

        return Inertia::render('wastage/index', [
            'logs' => $logs,
            'month' => $month,
            'totals' => [
                'material' => round($logs->where('scope', 'material')->sum('cost'), 2),
                'product' => round($logs->where('scope', 'product')->sum('cost'), 2),
                'business' => round($logs->where('scope', 'business')->sum('cost'), 2),
            ],
            'materials' => Material::query()->where('type', 'material')->orderBy('name')->get(['id', 'name', 'unit']),
            'components' => Component::query()->orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    public function store(Request $request, WastageService $service): RedirectResponse
    {
        $data = $request->validate([
            'scope' => ['required', Rule::in(['material', 'product', 'business'])],
            'logged_on' => ['required', 'date'],
            'material_id' => ['required_if:scope,material', 'nullable', 'exists:materials,id'],
            'component_id' => ['required_if:scope,product', 'nullable', 'exists:components,id'],
            'qty' => ['required_unless:scope,business', 'nullable', 'numeric', 'gt:0'],
            'amount' => ['required_if:scope,business', 'nullable', 'numeric', 'gt:0'],
            'reason' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ]);

        match ($data['scope']) {
            'material' => $service->material(Material::query()->findOrFail($data['material_id']), (float) $data['qty'], $data['logged_on'], $data['reason'] ?? null, $data['notes'] ?? null),
            'product' => $service->component(Component::query()->findOrFail($data['component_id']), (float) $data['qty'], $data['logged_on'], $data['reason'] ?? null, $data['notes'] ?? null),
            'business' => $service->business((float) $data['amount'], $data['logged_on'], $data['reason'] ?? null, $data['notes'] ?? null),
        };
        $this->toast('Wastage logged.');

        return back();
    }

    public function destroy(WastageLog $wastage, WastageService $service): RedirectResponse
    {
        $service->delete($wastage);
        $this->toast('Wastage entry removed and stock restored.');

        return back();
    }
}
