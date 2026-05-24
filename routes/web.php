<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DashboardController;
use App\Livewire\Documents\DocumentIndex;
use App\Livewire\Documents\DocumentUpload;

Route::get('/', function () {
    return view('welcome');
})->name('welcome');

Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])
        ->name('dashboard');
    Route::get('/documents', DocumentIndex::class)
        ->name('documents.index');
    Route::get('/documents/upload', DocumentUpload::class)
        ->name('documents.upload');
});