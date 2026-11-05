<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\SourceController;
use App\Http\Controllers\Api\ArticleController;

// Route::get('/user', function (Request $request) {
//     return $request->user();
// })->middleware('auth:sanctum');

// For pinging the server
Route::get('/ping', function () {
    return response()->json(['status' => 'alive'], 200);
});

// Rest of the API endpoints go here
Route::get('/sources', [SourceController::class, 'index']);
Route::post('/sources', [SourceController::class, 'store'])->middleware('throttle:10,1');
Route::get('/articles', [ArticleController::class, 'index']);
Route::post('/articles/refresh', [ArticleController::class, 'refresh'])
    ->middleware('throttle:6,1'); // max 6 refreshes per minute per client
Route::post('/news-fetch', [ArticleController::class, 'fetch']);
