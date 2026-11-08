<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = [
        'category_id',
        'sku',
        'name',
        'brand',
        'unit_price',
        'min_stock',
        'is_active',
        'image_url',
    ];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function stockMovements(): HasMany
    {
       return $this->hasMany(StockMovement::class);
    }
}