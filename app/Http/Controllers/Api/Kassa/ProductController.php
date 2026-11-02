<?php

namespace App\Http\Controllers\Api\Kassa;

use App\Models\Kassa\Product;
use App\Services\Kassa\KassaReportService;
use App\Services\Kassa\KassaService;
use App\Support\Kassa\Ean13;
use App\Support\Kassa\Presenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductController extends KassaController
{
    public function __construct(private readonly KassaService $kassa, private readonly KassaReportService $reports) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'products' => Presenter::products(Product::orderBy('name')->get()),
            'popular' => $this->reports->popular(),
        ]);
    }

    public function show(Product $product): JsonResponse
    {
        return response()->json($this->reports->productDetail($product));
    }

    public function barcode(): JsonResponse
    {
        return response()->json(['barcode' => $this->kassa->newBarcode()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request, null);
        $opening = (int) ($data['stock'] ?? 0);
        unset($data['stock']);
        $product = $this->kassa->createProduct($this->me($request), $data, $opening);

        return response()->json(['product' => Presenter::product($product)], 201);
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        $data = $this->validated($request, $product);
        unset($data['stock']); // остаток меняют только операции
        $product->update($data);

        return response()->json(['product' => Presenter::product($product)]);
    }

    public function destroy(Product $product): JsonResponse
    {
        $product->delete();

        return response()->json(['ok' => true, 'id' => $product->id]);
    }

    private function validated(Request $request, ?Product $product): array
    {
        $data = $this->check($request, [
            'name' => 'required|string|max:80',
            'category' => ['required', Rule::in(Product::CATEGORIES)],
            'barcode' => 'nullable|string|max:20',
            'buy_price' => 'required|integer|min:0|max:100000000000',
            'sell_price' => 'required|integer|min:1|max:100000000000',
            'min_stock' => 'required|integer|min:0|max:1000000',
            'stock' => 'nullable|integer|min:0|max:1000000',
        ]);

        $data['name'] = trim($data['name']);
        $data['barcode'] = Ean13::normalize($data['barcode'] ?? '') ?: $this->kassa->newBarcode();
        if (! Ean13::isValid($data['barcode'])) {
            throw ValidationException::withMessages(['barcode' => 'Штрих-код должен быть EAN-13: 13 цифр с верной контрольной цифрой']);
        }
        $taken = Product::where('barcode', $data['barcode'])->when($product, fn ($q) => $q->whereKeyNot($product->id))->first();
        if ($taken) {
            throw ValidationException::withMessages(['barcode' => "Штрих-код уже занят товаром «{$taken->name}»"]);
        }

        return $data;
    }
}
