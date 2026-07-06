<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Documents\DocumentFilePreviewController;
use App\Http\Controllers\Documents\DocumentFileDownloadController;
use App\Livewire\Documents\DocumentIndex;
use App\Livewire\Documents\DocumentUpload;
use App\Livewire\Documents\DocumentShow;
use App\Livewire\Products\ProductIndex;
use App\Livewire\Products\ProductShow;
use App\Livewire\ProductCases\ProductCaseIndex;
use App\Livewire\ProductCases\ProductCaseShow;
use App\Livewire\Reviews\ReviewIndex;
use App\Livewire\Warranties\WarrantyIndex;

Route::get('/', function () {
    return view('welcome');
})->name('welcome');

Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
])->group(function () {
    /**
     * Dashboard
     */
    Route::get('/dashboard', [DashboardController::class, 'index'])
        ->name('dashboard');

    /**
     * Rotte per la gestione dei documenti. Tutte le rotte sono protette da policy
     */
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

    /**
     * Rotte per la gestione dei prodotti. Tutte le rotte sono protette da policy
     */
    Route::get('/products', ProductIndex::class)
        ->name('products.index');

    Route::get('/products/{product}', ProductShow::class)
        ->whereNumber('product')
        ->name('products.show');

    /**
     * Elenco e dettaglio delle pratiche prodotto.
     */
    Route::get('/product-cases', ProductCaseIndex::class)
        ->name('product-cases.index');

    Route::get(
        '/product-cases/{productCase}',
        ProductCaseShow::class
    )
        ->whereNumber('productCase')
        ->name('product-cases.show');

    /**
     * Rotte per la gestione delle garanzie.
     */
    Route::get('/warranties', WarrantyIndex::class)
        ->name('warranties.index');

    /**
     * Rotte per la gestione delle revisioni.
     */
    Route::get('/reviews', ReviewIndex::class)
        ->name('reviews.index');
});
