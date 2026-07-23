<?php

declare(strict_types=1);

use App\Http\Controllers\ItemController;
use Illuminate\Support\Facades\Route;

Route::get('/items/{id}', [ItemController::class, 'show'])->whereNumber('id');
Route::post('/items/{id}/buy', [ItemController::class, 'buy'])->whereNumber('id');
