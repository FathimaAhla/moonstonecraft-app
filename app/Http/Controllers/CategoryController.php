<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Services\KitCounts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CategoryController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('categories/index', [
            'categories' => Category::query()->withCount('products')->orderBy('sort')->get(),
            'pickOptions' => KitCounts::PICKS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Category::query()->create($request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code_prefix' => ['required', 'string', 'max:10', 'alpha', 'unique:categories,code_prefix'],
            'kit_picks' => ['required', Rule::in(array_keys(KitCounts::PICKS))],
            'sort' => ['nullable', 'integer'],
        ]) + ['sort' => 0]);
        $this->toast('Category added.');

        return back();
    }

    /**
     * Changing what is picked recounts the figures and pots of every kit in the category.
     */
    public function update(Request $request, Category $category, KitCounts $counts): RedirectResponse
    {
        $category->update($request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code_prefix' => ['required', 'string', 'max:10', 'alpha', Rule::unique('categories')->ignore($category)],
            'kit_picks' => ['required', Rule::in(array_keys(KitCounts::PICKS))],
            'sort' => ['nullable', 'integer'],
        ]));

        if ($category->wasChanged('kit_picks')) {
            $counts->syncCategory($category);
        }

        $this->toast('Category updated.');

        return back();
    }

    public function destroy(Category $category): RedirectResponse
    {
        return $this->deleteOrRefuse(fn () => $category->delete(), 'Category');
    }
}
