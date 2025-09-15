<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\SentimentController;
use App\Http\Controllers\Api\ReviewController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('sentiment/analyze', [SentimentController::class, 'analyze'])
    ->name('api.sentiment.analyze');
Route::get('sentiment/status/{id}', [SentimentController::class, 'status'])
    ->name('api.sentiment.status');
Route::post('sentiment/batch-csv', [SentimentController::class, 'batchCsv'])
    ->name('api.sentiment.batch_csv');
Route::get('sentiment/handle', [SentimentController::class, 'analyzeHandle'])
    ->name('api.sentiment.handle');

Route::post('reviews/import-csv', [ReviewController::class, 'importCsv'])
    ->name('api.reviews.import_csv');
Route::get('reviews/aggregates', [ReviewController::class, 'aggregates'])
    ->name('api.reviews.aggregates');
