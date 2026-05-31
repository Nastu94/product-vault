<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\Documents\InvoiceTableExtraction\InvoiceTableExtractionManager;
use App\Services\Documents\InvoiceTableExtraction\OcrVisualLineInvoiceTableExtractor;
use App\Services\Documents\InvoiceTableExtraction\TextInvoiceTableExtractor;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(InvoiceTableExtractionManager::class, function ($app) {
            return new InvoiceTableExtractionManager([
                $app->make(TextInvoiceTableExtractor::class),
                $app->make(OcrVisualLineInvoiceTableExtractor::class),
            ]);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
