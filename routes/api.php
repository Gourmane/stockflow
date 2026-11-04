<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\DatabaseCheckController;



Route::apiResource('products', ProductController::class);

Route::apiResource('categories', CategoryController::class);