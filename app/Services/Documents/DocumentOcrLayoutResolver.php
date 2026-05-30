<?php

namespace App\Services\Documents;

use App\Models\Document;
use App\Models\DocumentTextExtraction;

class DocumentOcrLayoutResolver
{
    /**
     * Restituisce layout OCR normalizzato per immagini e PDF scansionati.
     *
     * Supporta:
     * - metadata['ocr_items'] per immagini singole;
     * - metadata['pages'][*]['ocr_items'] per PDF convertiti in immagini.
     */
    public function resolve(Document $document): array
    {
        $extraction = DocumentTextExtraction::query()
            ->where('document_id', $document->id)
            ->where('status', 'completed')
            ->whereNotNull('metadata')
            ->latest('id')
            ->first();

        if (! $extraction) {
            return [
                'extraction' => null,
                'items' => [],
                'metadata' => [],
                'layout' => null,
            ];
        }

        $metadata = $extraction->metadata ?? [];

        $items = $this->extractItems($metadata);
        $layout = $this->extractLayout($metadata, $items);

        return [
            'extraction' => $extraction,
            'items' => $items,
            'metadata' => $this->normalizeMetadata($metadata, $layout),
            'layout' => $layout,
        ];
    }

    private function extractItems(array $metadata): array
    {
        $rootItems = $metadata['ocr_items'] ?? [];

        if (is_array($rootItems) && ! empty($rootItems)) {
            return array_values($rootItems);
        }

        $items = [];

        foreach (($metadata['pages'] ?? []) as $pageIndex => $page) {
            if (! is_array($page)) {
                continue;
            }

            $pageNumber = (int) ($page['page'] ?? ($pageIndex + 1));

            foreach (($page['ocr_items'] ?? []) as $item) {
                if (! is_array($item)) {
                    continue;
                }

                /*
                |--------------------------------------------------------------------------
                | Normalizzazione pagina
                |--------------------------------------------------------------------------
                |
                | Gli item prodotti da PaddleOCR hanno già page = 1 perché ogni pagina
                | PDF viene processata come immagine singola. Qui correggiamo il valore
                | per mantenere coerenza nei PDF multi-pagina.
                |
                */
                $item['page'] = $pageNumber;
                $item['pdf_page'] = $pageNumber;

                $items[] = $item;
            }
        }

        return $items;
    }

    private function extractLayout(array $metadata, array $items): ?array
    {
        $rootLayout = $metadata['ocr_layout'] ?? null;

        if (is_array($rootLayout)) {
            return $rootLayout;
        }

        $visualLines = [];
        $image = null;

        foreach (($metadata['pages'] ?? []) as $pageIndex => $page) {
            if (! is_array($page)) {
                continue;
            }

            $pageNumber = (int) ($page['page'] ?? ($pageIndex + 1));
            $pageLayout = $page['ocr_layout'] ?? null;

            if (! is_array($pageLayout)) {
                continue;
            }

            if ($image === null && isset($pageLayout['image']) && is_array($pageLayout['image'])) {
                $image = $pageLayout['image'];
            }

            foreach (($pageLayout['visual_lines'] ?? []) as $visualLine) {
                if (! is_array($visualLine)) {
                    continue;
                }

                $visualLine['page'] = $pageNumber;
                $visualLine['pdf_page'] = $pageNumber;

                $visualLines[] = $visualLine;
            }
        }

        if ($items === [] && $visualLines === [] && $image === null) {
            return null;
        }

        return [
            'image' => $image,
            'visual_lines' => $visualLines,
        ];
    }

    private function normalizeMetadata(array $metadata, ?array $layout): array
    {
        $normalized = $metadata;

        if (! isset($normalized['image_width'])) {
            $normalized['image_width'] = $layout['image']['width'] ?? $this->firstPageMetadataValue($metadata, 'image_width');
        }

        if (! isset($normalized['image_height'])) {
            $normalized['image_height'] = $layout['image']['height'] ?? $this->firstPageMetadataValue($metadata, 'image_height');
        }

        return $normalized;
    }

    private function firstPageMetadataValue(array $metadata, string $key): mixed
    {
        foreach (($metadata['pages'] ?? []) as $page) {
            if (! is_array($page)) {
                continue;
            }

            if (isset($page['metadata'][$key])) {
                return $page['metadata'][$key];
            }
        }

        return null;
    }
}