<?php

namespace Modules\Products\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Modules\Inventory\Contracts\InventoryGateway;
use Modules\Inventory\Exceptions\InventoryUnavailableException;
use Modules\Products\Events\ProductDeleting;
use Modules\Products\Http\Requests\StoreProductRequest;
use Modules\Products\Http\Requests\UpdateProductRequest;
use Modules\Products\Http\Resources\ProductResource;
use Modules\Products\Models\Product;

class ProductController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:100'],
            'min_price' => ['nullable', 'numeric', 'min:0'],
            'max_price' => ['nullable', 'numeric', 'min:0'],
            'sort' => ['nullable', 'in:newest,price_asc,price_desc,name'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $query = Product::query()
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where('name', 'like', '%'.$s.'%'))
            ->when($filters['category'] ?? null, fn ($q, $c) => $q->where('category', $c))
            ->when(isset($filters['min_price']), fn ($q) => $q->where('price', '>=', $filters['min_price']))
            ->when(isset($filters['max_price']), fn ($q) => $q->where('price', '<=', $filters['max_price']));

        match ($filters['sort'] ?? 'newest') {
            'price_asc' => $query->orderBy('price'),
            'price_desc' => $query->orderByDesc('price'),
            'name' => $query->orderBy('name'),
            default => $query->orderByDesc('id'),
        };

        return ProductResource::collection($query->paginate($filters['per_page'] ?? 12));
    }

    public function categories(): JsonResponse
    {
        return response()->json([
            'data' => Product::query()->distinct()->orderBy('category')->pluck('category'),
        ]);
    }

    /**
     * Product details + available quantity asked from the inventory service over gRPC.
     * If the inventory service is down or too slow, the product is still shown,
     * with a clear note that the stock information is temporarily unavailable.
     */
    public function show(Product $product, InventoryGateway $inventory): ProductResource
    {
        try {
            $quantity = $inventory->getAvailableQuantity($product->id);
            $stock = [
                'available_quantity' => $quantity,
                'in_stock' => $quantity > 0,
            ];
        } catch (InventoryUnavailableException $e) {
            report($e);
            $stock = [
                'available_quantity' => null,
                'in_stock' => null,
                'message' => 'Stock information is temporarily unavailable.',
            ];
        }

        return (new ProductResource($product))->withStock($stock);
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $data = $request->safe()->except('image');

        if ($request->hasFile('image')) {
            $data['image_path'] = $this->storeImage($request->file('image'));
        }

        $product = Product::create($data);

        return (new ProductResource($product))->response()->setStatusCode(201);
    }

    public function update(UpdateProductRequest $request, Product $product): ProductResource
    {
        $data = $request->safe()->except('image');

        if ($request->hasFile('image')) {
            $this->deleteImage($product->image_path);
            $data['image_path'] = $this->storeImage($request->file('image'));
        }

        $product->update($data);

        return new ProductResource($product);
    }

    public function destroy(Product $product): JsonResponse
    {
        // Ask the other modules (through an event) whether the product is still needed.
        // The Orders module answers by throwing ProductInUseException, which renders
        // itself as 409 Conflict. Products does not know Orders and never reads its tables.
        event(new ProductDeleting($product->id));

        $product->delete();

        $this->deleteImage($product->image_path);

        return response()->json(null, 204);
    }

    private function storeImage(UploadedFile $file): string
    {
        $name = Str::uuid()->toString().'.'.$file->extension();
        $file->move(public_path('uploads/products'), $name);

        return 'uploads/products/'.$name;
    }

    private function deleteImage(?string $path): void
    {
        if ($path && str_starts_with($path, 'uploads/products/')) {
            File::delete(public_path($path));
        }
    }
}
