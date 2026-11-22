<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ExpenseController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('expenses/index', [
            'expenses' => Expense::query()->latest('spent_on')->latest('id')->paginate(50),
            'categories' => Expense::query()->distinct()->orderBy('category')->pluck('category'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Expense::query()->create($request->validate([
            'spent_on' => ['required', 'date'],
            'category' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'gt:0'],
        ]));
        $this->toast('Expense added.');

        return back();
    }

    public function destroy(Expense $expense): RedirectResponse
    {
        if ($expense->ref_id) {
            $this->toast('This expense belongs to a purchase. Delete the purchase instead.', 'error');

            return back();
        }

        $expense->delete();
        $this->toast('Expense deleted.');

        return back();
    }
}
