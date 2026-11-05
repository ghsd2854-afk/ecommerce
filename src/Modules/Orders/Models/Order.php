<?php

namespace Modules\Orders\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Users\Models\User;

class Order extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUSES = ['pending', 'confirmed', 'shipped', 'delivered', 'cancelled'];

    protected $fillable = ['user_id', 'status', 'total'];

    protected function casts(): array
    {
        return ['total' => 'decimal:2'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }
}
