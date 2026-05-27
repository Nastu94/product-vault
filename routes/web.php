<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Documents\DocumentFilePreviewController;
use App\Http\Controllers\Documents\DocumentFileDownloadController;
use App\Livewire\Documents\DocumentIndex;
use App\Livewire\Documents\DocumentUpload;
use App\Livewire\Documents\DocumentShow;

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
    Route::get('/documents/{document}', DocumentShow::class)
        ->whereNumber('document')
        ->name('documents.show');
    Route::get('/documents/{document}/preview', DocumentFilePreviewController::class)
        ->whereNumber('document')
        ->name('documents.preview');
    Route::get('/documents/{document}/download', DocumentFileDownloadController::class)
        ->whereNumber('document')
        ->name('documents.download');
});