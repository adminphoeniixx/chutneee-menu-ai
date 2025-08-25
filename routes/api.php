<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\MenuExtractionController;
use App\Http\Controllers\MenuImageController;
use App\Http\Controllers\ImageGenController;
use App\Http\Controllers\ImageGenerationController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('/menu/extract', [MenuExtractionController::class, 'extract']);   // ← returns JSON only
Route::post('/menu/preview', [MenuExtractionController::class, 'preview']);   // optional JSON preview




Route::prefix('images')->group(function () {
    // Menu-specific routes (optimized for food delivery apps)
    Route::post('/menu/generate', [ImageGenerationController::class, 'generateMenuItem'])
        ->name('images.menu.generate');
    
    Route::post('/menu/generate-batch', [ImageGenerationController::class, 'generateMenuBatch'])
        ->name('images.menu.generate.batch');
    
    // General purpose routes
    Route::post('/generate', [ImageGenerationController::class, 'generate'])
        ->name('images.generate');
    
    Route::post('/generate-batch', [ImageGenerationController::class, 'generateBatch'])
        ->name('images.generate.batch');
});

// With rate limiting for production
Route::middleware(['throttle:20,1'])->prefix('images')->group(function () {
    Route::post('/menu/generate', [ImageGenerationController::class, 'generateMenuItem']);
    Route::post('/menu/generate-batch', [ImageGenerationController::class, 'generateMenuBatch']);
    Route::post('/generate', [ImageGenerationController::class, 'generate']);
    Route::post('/generate-batch', [ImageGenerationController::class, 'generateBatch']);
});