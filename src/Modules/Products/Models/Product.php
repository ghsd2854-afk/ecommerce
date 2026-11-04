<?php

namespace Modules\Products\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $fillable = ['name', 'description', 'price', 'category', 'image_path'];

    protected function casts(): array
    {
        return ['price' => 'decimal:2'];
    }
}
