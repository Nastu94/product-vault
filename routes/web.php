<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Documents\DocumentFileDownloadController;
use App\Http\Controllers\Documents\DocumentFilePreviewController;
use App\Livewire\Account\PlanOverview;
use App\Livewire\Documents\DocumentIndex;
use App\Livewire\Documents\DocumentShow;
use App\Livewire\Documents\DocumentUpload;
use App\Livewire\ProductCases\ProductCaseIndex;
use App\Livewire\ProductCases\ProductCaseShow;
use App\Livewire\Products\ProductIndex;
use App\Livewire\Products\ProductShow;
use App\Livewire\Reviews\ReviewIndex;
use App\Livewire\Warranties\WarrantyIndex;
use Illuminate\Support\Facades\Route;

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
    Route::get(
        '/documents/{document}/preview',
        DocumentFilePreviewController::class
    )
        ->whereNumber('document')
        ->name('documents.preview');
    Route::get(
        '/documents/{document}/download',
        DocumentFileDownloadController::class
    )
        ->whereNumber('document')
        ->name('documents.download');

    Route::get('/products', ProductIndex::class)
        ->name('products.index');
    Route::get('/products/{product}', ProductShow::class)
        ->whereNumber('product')
        ->name('products.show');

    Route::get('/product-cases', ProductCaseIndex::class)
        ->name('product-cases.index');
    Route::get('/product-cases/{productCase}', ProductCaseShow::class)
        ->whereNumber('productCase')
        ->name('product-cases.show');

    Route::get('/warranties', WarrantyIndex::class)
        ->name('warranties.index');

    Route::get('/reviews', ReviewIndex::class)
        ->name('reviews.index');

    Route::get('/account/plan', PlanOverview::class)
        ->name('account.plan');
});
