<?php

namespace App\Http\Controllers;

use App\Models\Material;
use App\Models\MaterialContainer;
use App\Services\ContainerService;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class MaterialContainerController extends Controller
{
    public function __construct(private ContainerService $containers) {}

    public function store(Request $request, Material $material): RedirectResponse
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:50'],
            'size' => ['required', 'numeric', 'gt:0'],
            'opened_on' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        return $this->attempt('size', 'Container opened.', fn () => $this->containers->open($material, (float) $data['size'], $data['label'], Carbon::parse($data['opened_on']), $data['note'] ?? null));
    }

    public function finish(Request $request, MaterialContainer $container): RedirectResponse
    {
        $data = $request->validate([
            'finished_on' => ['required', 'date'],
            'kits_made' => ['required', 'numeric', 'gt:0'],
        ]);

        return $this->attempt('finished_on', 'Marked as finished.', fn () => $this->containers->finish($container, Carbon::parse($data['finished_on']), (float) $data['kits_made']));
    }

    public function reopen(MaterialContainer $container): RedirectResponse
    {
        $this->containers->reopen($container);
        $this->toast('Container is in use again.');

        return back();
    }

    public function destroy(MaterialContainer $container): RedirectResponse
    {
        return $this->attempt('container', 'Removed, quantity returned to stock.', fn () => $this->containers->cancel($container));
    }

    private function attempt(string $field, string $success, Closure $action): RedirectResponse
    {
        try {
            $action();
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([$field => $exception->getMessage()]);
        }

        $this->toast($success);

        return back();
    }
}
