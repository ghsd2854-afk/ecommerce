<?php

namespace Modules\Products\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    /** Stock info from the inventory service (only set on the product details endpoint). */
    private ?array $stockInfo = null;

    public function withStock(array $stock): static
    {
        $this->stockInfo = $stock;

        return $this;
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'price' => $this->price,
            'category' => $this->category,
            'image_url' => $this->image_path ? asset($this->image_path) : null,
            'stock' => $this->when($this->stockInfo !== null, fn () => $this->stockInfo),
            'created_at' => $this->created_at,
        ];
    }
}
