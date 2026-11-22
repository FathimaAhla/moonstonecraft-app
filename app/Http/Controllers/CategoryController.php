<?php

namespace App\Http\Controllers;

use App\Models\Category;
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
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Category::query()->create($request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code_prefix' => ['required', 'string', 'max:10', 'alpha', 'unique:categories,code_prefix'],
            'sort' => ['nullable', 'integer'],
        ]) + ['sort' => 0]);
        $this->toast('Category added.');

        return back();
    }

    public function update(Request $request, Category $category): RedirectResponse
    {
        $category->update($request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code_prefix' => ['required', 'string', 'max:10', 'alpha', Rule::unique('categories')->ignore($category)],
            'sort' => ['nullable', 'integer'],
        ]));
        $this->toast('Category updated.');

        return back();
    }

    public function destroy(Category $category): RedirectResponse
    {
        return $this->deleteOrRefuse(fn () => $category->delete(), 'Category');
    }
}
