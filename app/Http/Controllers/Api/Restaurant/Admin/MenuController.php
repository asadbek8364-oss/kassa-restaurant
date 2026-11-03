<?php

namespace App\Http\Controllers\Api\Restaurant\Admin;

use App\Http\Controllers\Api\Restaurant\RestaurantController;
use App\Models\Restaurant\Category;
use App\Models\Restaurant\Dish;
use App\Support\Restaurant\Presenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Меню ресторана: категории и блюда, доступность и стоп-лист. Удаление — мягкое (блюдо остаётся в истории заказов). */
class MenuController extends RestaurantController
{
    public function index(): JsonResponse
    {
        return response()->json([
            'categories' => Category::orderBy('sort')->orderBy('name')->get()->map(fn ($c) => Presenter::category($c) + [
                'dishes' => $c->dishes()->count(),
            ])->all(),
            'dishes' => Dish::orderBy('sort')->orderBy('name')->get()->map(fn ($d) => Presenter::dish($d))->all(),
        ]);
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $data = $this->categoryData($request);
        $category = Category::create($data);

        return response()->json(['category' => Presenter::category($category)], 201);
    }

    public function updateCategory(Request $request, Category $category): JsonResponse
    {
        $category->update($this->categoryData($request, $category));

        return response()->json(['category' => Presenter::category($category)]);
    }

    public function destroyCategory(Category $category): JsonResponse
    {
        if ($category->dishes()->exists()) {
            throw ValidationException::withMessages(['category_id' => 'В категории есть блюда — перенесите или удалите их']);
        }
        $category->delete();

        return response()->json(['ok' => true]);
    }

    public function storeDish(Request $request): JsonResponse
    {
        $dish = Dish::create($this->dishData($request));

        return response()->json(['dish' => Presenter::dish($dish)], 201);
    }

    public function updateDish(Request $request, Dish $dish): JsonResponse
    {
        $dish->update($this->dishData($request));

        return response()->json(['dish' => Presenter::dish($dish)]);
    }

    public function destroyDish(Dish $dish): JsonResponse
    {
        $dish->delete();

        return response()->json(['ok' => true]);
    }

    /** Стоп-лист: блюдо временно закончилось (официант видит, но не может заказать). */
    public function stop(Request $request, Dish $dish): JsonResponse
    {
        $data = $this->check($request, ['stop' => 'required|boolean']);
        $dish->update(['stop' => (bool) $data['stop']]);

        return response()->json(['dish' => Presenter::dish($dish)]);
    }

    private function categoryData(Request $request, ?Category $current = null): array
    {
        $data = $this->check($request, ['name' => 'required|string|max:40', 'sort' => 'nullable|integer|min:0|max:100000']);
        $name = trim($data['name']);
        // сравнение в PHP: LOWER() в SQLite не переводит кириллицу
        $taken = Category::when($current, fn ($q) => $q->whereKeyNot($current->id))->pluck('name')
            ->contains(fn ($n) => mb_strtolower($n) === mb_strtolower($name));
        if ($taken) {
            throw ValidationException::withMessages(['name' => "Категория «{$name}» уже есть"]);
        }

        return ['name' => $name, 'sort' => (int) ($data['sort'] ?? ($current?->sort ?? ((int) Category::max('sort') + 10)))];
    }

    private function dishData(Request $request): array
    {
        $data = $this->check($request, [
            'category_id' => 'required|integer',
            'name' => 'required|string|max:80',
            'description' => 'nullable|string|max:300',
            'price' => 'required|integer|min:0|max:100000000',
            'active' => 'nullable|boolean',
            'stop' => 'nullable|boolean',
            'sort' => 'nullable|integer|min:0|max:100000',
        ]);
        if (! Category::whereKey($data['category_id'])->exists()) {
            throw ValidationException::withMessages(['category_id' => 'Выберите категорию из списка']);
        }
        $description = trim((string) ($data['description'] ?? ''));

        return [
            'category_id' => (int) $data['category_id'],
            'name' => trim($data['name']),
            'description' => $description === '' ? null : $description,
            'price' => (int) $data['price'],
            'active' => (bool) ($data['active'] ?? true),
            'stop' => (bool) ($data['stop'] ?? false),
            'sort' => (int) ($data['sort'] ?? 0),
        ];
    }
}
