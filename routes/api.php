<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\MenuExtractionController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('/menu/extract', [MenuExtractionController::class, 'extract']);   // ← returns JSON only
Route::post('/menu/preview', [MenuExtractionController::class, 'preview']);   // optional JSON preview