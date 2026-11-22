<?php

namespace App\Http\Controllers;

use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

abstract class Controller
{
    protected function toast(string $message, string $type = 'success'): void
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);
    }

    /**
     * Delete a record, refusing when it is still referenced elsewhere (purchases, sales, recipes).
     */
    protected function deleteOrRefuse(callable $delete, string $label): RedirectResponse
    {
        try {
            $delete();
            $this->toast("{$label} deleted.");
        } catch (QueryException) {
            $this->toast("{$label} is used in purchases, sales or recipes and cannot be deleted.", 'error');
        }

        return back();
    }
}
