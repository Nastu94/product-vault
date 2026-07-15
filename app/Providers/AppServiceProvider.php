<?php

namespace App\Providers;

use App\Models\DocumentTextExtraction;
use App\Models\Product;
use App\Models\ProductCase;
use App\Observers\DocumentTextExtractionObserver;
use App\Observers\ProductCaseObserver;
use App\Observers\ProductObserver;
use App\Services\Documents\InvoiceTableExtraction\InvoiceTableExtractionManager;
use App\Services\Documents\InvoiceTableExtraction\OcrGeometryInvoiceTableExtractor;
use App\Services\Documents\InvoiceTableExtraction\OcrVisualLineInvoiceTableExtractor;
use App\Services\Documents\InvoiceTableExtraction\TextInvoiceTableExtractor;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            InvoiceTableExtractionManager::class,
            function ($app) {
                return new InvoiceTableExtractionManager([
                    $app->make(TextInvoiceTableExtractor::class),
                    $app->make(OcrVisualLineInvoiceTableExtractor::class),
                    $app->make(OcrGeometryInvoiceTableExtractor::class),
                ]);
            }
        );
    }

    public function boot(): void
    {
        Product::observe(ProductObserver::class);
        ProductCase::observe(ProductCaseObserver::class);
        DocumentTextExtraction::observe(
            DocumentTextExtractionObserver::class
        );
    }
}
