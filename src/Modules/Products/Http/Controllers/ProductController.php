<?php

namespace Modules\Products\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ReadYourWrites;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
use Modules\Products\Services\ProductCache;

/**
 * Requirement 3 in this controller:
 *  - reads go through ProductCache (Redis, TTL + version invalidation)
 *  - reads use the read replica, except right after a product write (ReadYourWrites)
 *  - every write calls $this->cache->invalidate(), so changes are visible immediately
 *  - ETag / 304 is added by the "etag" middleware on the GET routes (see Routes/api.php)
 */
class ProductController extends Controller
{
    public function __construct(private ProductCache $cache)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:100'],
            'min_price' => ['nullable', 'numeric', 'min:0'],
            'max_price' => ['nullable', 'numeric', 'min:0'],
            'sort' => ['nullable', 'in:newest,price_asc,price_desc,name'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        ksort($filters);

        // One cache entry per combination of filters + page (+ host: the cached page contains
        // absolute URLs, e.g. pagination links and image URLs).
        $key = sha1(json_encode([$filters, $request->getSchemeAndHttpHost()]));
        $payload = $this->cache->remember('product_list', $key, function () use ($filters) {
            $query = $this->query()
                ->when($filters['search'] ?? null, fn ($q, $s) => $q->where('name', 'like', '%'.$s.'%'))
                ->when($filters['category'] ?? null, fn ($q, $c) => $q->where('category', $c))
                ->when(isset($filters['min_price']), fn ($q) => $q->where('price', '>=', $filters['min_price']))
                ->when(isset($filters['max_price']), fn ($q) => $q->where('price', '<=', $filters['max_price']));

            // id as the second sort key: a stable order (no product repeated / skipped between pages)
            // and it matches the indexes (category, price) / (price) / (name), which end with the id.
            match ($filters['sort'] ?? 'newest') {
                'price_asc' => $query->orderBy('price')->orderBy('id'),
                'price_desc' => $query->orderByDesc('price')->orderByDesc('id'),
                'name' => $query->orderBy('name')->orderBy('id'),
                default => $query->orderByDesc('id'),
            };

            $page = $query->paginate($filters['per_page'] ?? 12, ['*'], 'page', $filters['page'] ?? 1);

            return ProductResource::collection($page)->response()->getData(true);
        });

        return response()->json($payload);
    }

    public function categories(): JsonResponse
    {
        $categories = $this->cache->remember('categories', 'all', fn () => $this->query()
            ->distinct()->orderBy('category')->pluck('category')->all());

        return response()->json(['data' => $categories]);
    }

    /**
     * Product details (cached) + available quantity asked LIVE from the inventory
     * service over gRPC (stock is never cached).
     * If the inventory service is down or too slow, the product is still shown,
     * with a clear note that the stock information is temporarily unavailable.
     */
    public function show(int $id, InventoryGateway $inventory): ProductResource
    {
        // Not on the replica (e.g. created a moment ago) -> asked once more on the primary before 404.
        $attributes = $this->cache->remember('product', (string) $id, fn () => ($this->query()->find($id)
            ?? Product::query()->useWritePdo()->find($id))?->getAttributes());

        abort_if($attributes === null, 404, 'Product not found.');

        $product = (new Product())->newFromBuilder($attributes);

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
        $this->cache->invalidate();

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
        $this->cache->invalidate();

        return new ProductResource($product);
    }

    public function destroy(Product $product): JsonResponse
    {
        // Ask the other modules (through an event) whether the product is still needed.
        // The Orders module answers by throwing ProductInUseException, which renders
        // itself as 409 Conflict. Products does not know Orders and never reads its tables.
        event(new ProductDeleting($product->id));

        $product->delete();

        $this->cache->invalidate();

        $this->deleteImage($product->image_path);

        return response()->json(null, 204);
    }

    /** Reads use the replica, or the primary right after a product write (read-your-writes). */
    private function query(): Builder
    {
        return ReadYourWrites::apply(Product::query(), 'products');
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
