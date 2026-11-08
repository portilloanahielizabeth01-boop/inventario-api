<?php

use App\Http\Controllers\Api\InternoProductoController;
use App\Http\Controllers\Api\ProductoController;
use App\Http\Middleware\VerificarTokenInterno;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::apiResource('productos', ProductoController::class);

Route::middleware(VerificarTokenInterno::class)
    ->prefix('interno')
    ->group(function () {
        Route::get('productos/{id}', [InternoProductoController::class, 'show'])->whereNumber('id');
        Route::post('productos', [InternoProductoController::class, 'store']);
    });
