<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $fillable = [
        'category_id',
        'sku',
        'name',
        'brand',
        'unit_price',
        'quantity',
        'min_stock',
        'is_active',
        'image_url',
    ];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }
}