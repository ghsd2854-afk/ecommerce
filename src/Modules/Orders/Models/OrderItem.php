<?php

namespace Modules\Orders\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    // product_name and unit_price are a snapshot taken when the order was placed,
    // so an order never needs to read the Products module's table afterwards.
    protected $fillable = ['order_id', 'product_id', 'product_name', 'quantity', 'unit_price'];

    protected function casts(): array
    {
        return ['product_id' => 'integer', 'quantity' => 'integer', 'unit_price' => 'decimal:2'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
