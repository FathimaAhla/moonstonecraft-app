<?php

namespace App\Http\Controllers;

use App\Models\Material;
use App\Models\MaterialPackSize;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MaterialPackSizeController extends Controller
{
    public function store(Request $request, Material $material): RedirectResponse
    {
        if (! $material->is_purchasable) {
            throw ValidationException::withMessages(['name' => 'This item is not purchasable.']);
        }

        $material->packSizes()->create($request->validate([
            'name' => ['required', 'string', 'max:20', Rule::unique('material_pack_sizes')->where('material_id', $material->id)],
            'size' => ['required', 'numeric', 'gt:0'],
        ]));
        $this->toast('Pack size added.');

        return back();
    }

    /**
     * Past purchases keep their own unit and quantity, so removing a size never changes them.
     */
    public function destroy(MaterialPackSize $packSize): RedirectResponse
    {
        $packSize->delete();
        $this->toast('Pack size removed.');

        return back();
    }
}
