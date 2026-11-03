<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\SourceController;
use App\Http\Controllers\Api\ArticleController;

// Route::get('/user', function (Request $request) {
//     return $request->user();
// })->middleware('auth:sanctum');

Route::get('/sources', [SourceController::class, 'index']);
Route::post('/sources', [SourceController::class, 'store'])->middleware('throttle:10,1');
Route::get('/articles', [ArticleController::class, 'index']);
