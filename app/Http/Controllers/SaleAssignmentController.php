<?php

namespace App\Http\Controllers;

use App\Models\Component;
use App\Models\FigureCombo;
use App\Models\SaleItem;
use App\Models\SaleItemAssignment;
use App\Services\AssignmentService;
use App\Services\FigureService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Add figures or a paint set to kits already sold ("choose later"), or take a row off again.
 */
class SaleAssignmentController extends Controller
{
    public function store(Request $request, SaleItem $item, AssignmentService $assignments, FigureService $figures): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(SaleItemAssignment::TYPES)],
            'qty' => ['required', 'numeric', 'gt:0'],
            'combo_id' => ['nullable', 'integer', 'exists:figure_combos,id'],
            'designs' => ['nullable', 'array'],
            'designs.*' => ['integer', 'min:0', 'max:12'],
            'component_id' => ['nullable', 'integer', 'exists:components,id'],
        ]);

        try {
            $what = match (true) {
                $data['type'] === 'paint' => Component::query()->findOrFail($data['component_id'] ?? 0),
                ! empty($data['combo_id']) => FigureCombo::query()->findOrFail($data['combo_id']),
                default => $figures->combo($data['designs'] ?? []),
            };
            $assignments->add($item, $data['type'], $what, (float) $data['qty']);
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['assign' => $exception->getMessage()]);
        }

        $this->toast("Added {$data['qty']} × {$what->name}. Taken out of stock.");

        return back();
    }

    public function destroy(SaleItemAssignment $assignment, AssignmentService $assignments): RedirectResponse
    {
        $assignments->remove($assignment);
        $this->toast('Row removed and put back into stock.');

        return back();
    }
}
