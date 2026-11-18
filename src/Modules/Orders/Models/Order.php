<?php

namespace Modules\Orders\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUSES = ['pending', 'confirmed', 'shipped', 'delivered', 'cancelled'];

    // user_id is only an id: the Orders module does not load or join the Users module's data.
    protected $fillable = ['user_id', 'status', 'total', 'reservation_id'];

    protected function casts(): array
    {
        return ['user_id' => 'integer', 'total' => 'decimal:2'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }
}
