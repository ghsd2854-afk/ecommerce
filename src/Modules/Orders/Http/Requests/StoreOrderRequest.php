<?php

namespace Modules\Orders\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Modules\Products\Contracts\ProductCatalog;

class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'min:1', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000'],
        ];
    }

    /**
     * The products must exist. Checked through the Products module's public
     * contract, not with an "exists" rule on the products table (another module's table).
     */
    public function after(ProductCatalog $catalog): array
    {
        return [
            function (Validator $validator) use ($catalog) {
                $items = $this->input('items');
                if (! is_array($items)) {
                    return;
                }

                $ids = collect($items)->pluck('product_id')->filter(fn ($id) => is_numeric($id))->map(fn ($id) => (int) $id)->all();
                $found = $catalog->findMany($ids);

                foreach ($items as $index => $item) {
                    $id = $item['product_id'] ?? null;
                    if (is_numeric($id) && ! isset($found[(int) $id])) {
                        $validator->errors()->add("items.$index.product_id", "The selected items.$index.product_id is invalid.");
                    }
                }
            },
        ];
    }
}
