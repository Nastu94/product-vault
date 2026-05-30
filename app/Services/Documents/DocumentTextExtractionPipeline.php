<?php

namespace App\Services\Documents;

use App\Models\Document;
use App\Models\DocumentTextExtraction;
use Smalot\PdfParser\Parser;
use Throwable;

class DocumentTextExtractionPipeline
{
    /**
     * Inietta i service OCR immagini.
     */
    public function __construct(
        private readonly ImageOcrExtractor $imageOcrExtractor,
        private readonly PaddleOcrExtractor $paddleOcrExtractor,
        private readonly PdfToImageConverter $pdfToImageConverter
    ) {
    }

    /**
     * Avvia il primo tentativo reale di estrazione testo.
     *
     * MVP attuale:
     * - PDF digitale: estrazione con Smalot PDF Parser.
     * - Immagini: OCR con Tesseract.
     * - PDF senza testo utile: richiede OCR.
     */
    public function extract(Document $document): DocumentTextExtraction
    {
        $media = $document->getFirstMedia('original_file');

        if (! $media) {
            return $this->failExtraction(
                document: $document,
                engine: 'media_lookup',
                message: 'File originale non associato al documento.'
            );
        }

        $path = $media->getPath();

        if (! is_file($path)) {
            return $this->failExtraction(
                document: $document,
                engine: 'storage_lookup',
                message: 'File fisico non trovato nello storage.'
            );
        }

        $mimeType = $media->mime_type ?: $document->mime_type;

        if ($mimeType === 'application/pdf') {
            return $this->extractPdfWithSmalot($document, $path, $mimeType);
        }

        if (str_starts_with((string) $mimeType, 'image/')) {
            return $this->extractImageWithPaddleOcr($document, $path, $mimeType);
        }

        return $this->failExtraction(
            document: $document,
            engine: 'unsupported_mime',
            message: 'MIME type non supportato per estrazione testo: ' . ($mimeType ?: 'sconosciuto')
        );
    }

    /**
     * Estrae testo da PDF digitale usando Smalot PDF Parser.
     */
    private function extractPdfWithSmalot(Document $document, string $path, ?string $mimeType): DocumentTextExtraction
    {
        $extraction = DocumentTextExtraction::query()->create([
            'document_id' => $document->id,
            'engine' => 'smalot_pdfparser',
            'status' => 'running',
            'metadata' => [
                'mime_type' => $mimeType,
                'path_exists' => is_file($path),
            ],
            'started_at' => now(),
        ]);

        try {
            $parser = new Parser();

            $pdf = $parser->parseFile($path);

            $rawText = trim($pdf->getText());

            if ($rawText === '') {
                $extraction->update([
                    'status' => 'requires_ocr',
                    'raw_text' => null,
                    'confidence_score' => 0,
                    'metadata' => array_merge($extraction->metadata ?? [], [
                        'reason' => 'PDF leggibile ma senza testo estraibile. Probabile PDF scansionato.',
                        'next_step' => 'pdf_to_image_ocr',
                    ]),
                    'completed_at' => now(),
                ]);

                $document->update([
                    'text_extraction_status' => 'requires_ocr',
                ]);

                return $this->extractScannedPdfWithOcr($document, $path, $mimeType);
            }

            $confidenceScore = $this->estimateConfidenceScore($rawText);

            $extraction->update([
                'status' => 'completed',
                'raw_text' => $rawText,
                'confidence_score' => $confidenceScore,
                'metadata' => array_merge($extraction->metadata ?? [], [
                    'text_length' => mb_strlen($rawText),
                ]),
                'completed_at' => now(),
            ]);

            $document->update([
                'status' => 'text_extracted',
                'text_extraction_status' => 'completed',
                'raw_text' => $rawText,
                'document_confidence_score' => $confidenceScore,
            ]);

            return $extraction->refresh();
        } catch (Throwable $exception) {
            $extraction->update([
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
                'completed_at' => now(),
            ]);

            $document->update([
                'text_extraction_status' => 'failed',
            ]);

            return $extraction->refresh();
        }
    }

    /**
     * Estrae testo da immagine usando PaddleOCR locale.
     */
    private function extractImageWithPaddleOcr(Document $document, string $path, ?string $mimeType): DocumentTextExtraction
    {
        $extraction = DocumentTextExtraction::query()->create([
            'document_id' => $document->id,
            'engine' => 'paddleocr',
            'status' => 'running',
            'metadata' => [
                'mime_type' => $mimeType,
                'path_exists' => is_file($path),
            ],
            'started_at' => now(),
        ]);

        try {
            $result = $this->paddleOcrExtractor->extract($path);

            $rawText = trim((string) $result['raw_text']);
            $confidenceScore = (int) ($result['confidence_score'] ?? $this->estimateConfidenceScore($rawText));

            if (mb_strlen($rawText) < 20) {
                $extraction->update([
                    'status' => 'completed',
                    'raw_text' => $rawText,
                    'confidence_score' => $confidenceScore,
                    'metadata' => array_merge($extraction->metadata ?? [], [
                        /*
                        |--------------------------------------------------------------------------
                        | OCR layout data
                        |--------------------------------------------------------------------------
                        |
                        | ocr_lines resta per compatibilità con la pipeline attuale.
                        | ocr_items e ocr_layout saranno usati dai parser layout-aware futuri.
                        |
                        */
                        'ocr_lines' => $result['lines'] ?? [],
                        'ocr_items' => $result['items'] ?? [],
                        'ocr_layout' => $result['layout'] ?? null,
                    ], $result['metadata'] ?? []),
                    'completed_at' => now(),
                ]);

                $document->update([
                    'text_extraction_status' => 'failed',
                ]);

                return $extraction->refresh();
            }

            $extraction->update([
                'status' => 'completed',
                'raw_text' => $rawText,
                'confidence_score' => $confidenceScore,
                'metadata' => array_merge($extraction->metadata ?? [], [
                    /*
                    |--------------------------------------------------------------------------
                    | OCR layout data
                    |--------------------------------------------------------------------------
                    |
                    | ocr_lines resta per compatibilità.
                    | ocr_items e ocr_layout saranno usati dai parser layout-aware futuri.
                    |
                    */
                    'ocr_lines' => $result['lines'] ?? [],
                    'ocr_items' => $result['items'] ?? [],
                    'ocr_layout' => $result['layout'] ?? null,
                ], $result['metadata'] ?? []),
                'completed_at' => now(),
            ]);

            $document->update([
                'status' => 'text_extracted',
                'text_extraction_status' => 'completed',
                'raw_text' => $rawText,
                'document_confidence_score' => $confidenceScore,
            ]);

            return $extraction->refresh();
        } catch (\Throwable $exception) {
            $extraction->update([
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
                'completed_at' => now(),
            ]);

            /*
            |--------------------------------------------------------------------------
            | Fallback Tesseract
            |--------------------------------------------------------------------------
            |
            | Se PaddleOCR fallisce per motivi tecnici, proviamo Tesseract.
            | Se Tesseract riesce, il documento non resta bloccato.
            |
            */
            return $this->extractImageWithTesseract($document, $path, $mimeType);
        }
    }

    /**
     * Estrae testo da immagine usando Tesseract OCR.
     */
    private function extractImageWithTesseract(Document $document, string $path, ?string $mimeType): DocumentTextExtraction
    {
        $extraction = DocumentTextExtraction::query()->create([
            'document_id' => $document->id,
            'engine' => 'tesseract_ocr',
            'status' => 'running',
            'metadata' => [
                'mime_type' => $mimeType,
                'path_exists' => is_file($path),
            ],
            'started_at' => now(),
        ]);

        try {
            $result = $this->imageOcrExtractor->extract($path);

            $rawText = trim((string) $result['raw_text']);

            if (mb_strlen($rawText) < 20) {
                $extraction->update([
                    'status' => 'failed',
                    'raw_text' => $rawText !== '' ? $rawText : null,
                    'confidence_score' => 0,
                    'error_message' => 'OCR completato, ma non ha restituito testo utile.',
                    'metadata' => array_merge($extraction->metadata ?? [], $result['metadata'] ?? []),
                    'completed_at' => now(),
                ]);

                $document->update([
                    'text_extraction_status' => 'failed',
                ]);

                return $extraction->refresh();
            }

            $confidenceScore = $this->estimateConfidenceScore($rawText);

            $extraction->update([
                'status' => 'completed',
                'raw_text' => $rawText,
                'confidence_score' => $confidenceScore,
                'metadata' => array_merge($extraction->metadata ?? [], $result['metadata'] ?? []),
                'completed_at' => now(),
            ]);

            $document->update([
                'status' => 'text_extracted',
                'text_extraction_status' => 'completed',
                'raw_text' => $rawText,
                'document_confidence_score' => $confidenceScore,
            ]);

            return $extraction->refresh();
        } catch (Throwable $exception) {
            $extraction->update([
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
                'completed_at' => now(),
            ]);

            $document->update([
                'text_extraction_status' => 'failed',
            ]);

            return $extraction->refresh();
        }
    }

    /**
     * Estrae testo da un PDF scansionato convertendo le pagine in immagini
     * e passando ogni immagine a PaddleOCR.
     */
    private function extractScannedPdfWithOcr(Document $document, string $path, ?string $mimeType): DocumentTextExtraction
    {
        $extraction = DocumentTextExtraction::query()->create([
            'document_id' => $document->id,
            'engine' => 'pdf_scan_paddleocr',
            'status' => 'running',
            'metadata' => [
                'mime_type' => $mimeType,
                'source' => 'pdf_to_image_ocr',
                'path_exists' => is_file($path),
            ],
            'started_at' => now(),
        ]);

        try {
            $conversion = $this->pdfToImageConverter->convert($path);

            $texts = [];
            $scores = [];
            $pageMetadata = [];

            foreach ($conversion['paths'] as $index => $imagePath) {
                $pageNumber = $index + 1;
                $pageEngine = 'paddleocr';

                try {
                    $result = $this->paddleOcrExtractor->extract($imagePath);
                } catch (Throwable $paddleException) {
                    /*
                    |--------------------------------------------------------------------------
                    | Fallback Tesseract per singola pagina
                    |--------------------------------------------------------------------------
                    |
                    | Se PaddleOCR fallisce su una pagina del PDF convertito,
                    | proviamo Tesseract sulla stessa immagine invece di perdere tutto il PDF.
                    |
                    */
                    $result = $this->imageOcrExtractor->extract($imagePath);
                    $pageEngine = 'tesseract_ocr';
                }

                $pageText = trim((string) ($result['raw_text'] ?? ''));

                if ($pageText !== '') {
                    $texts[] = $pageText;
                }

                $score = (int) ($result['confidence_score'] ?? 0);

                if ($score <= 0 && $pageText !== '') {
                    $score = $this->estimateConfidenceScore($pageText);
                }

                if ($score > 0) {
                    $scores[] = $score;
                }

                $pageMetadata[] = [
                    'page' => $pageNumber,
                    'engine' => $pageEngine,
                    'image_path' => $imagePath,
                    'text_length' => mb_strlen($pageText),
                    'confidence_score' => $score,
                    'ocr_lines' => $result['lines'] ?? [],
                    'ocr_items' => $result['items'] ?? [],
                    'ocr_layout' => $result['layout'] ?? null,
                    'metadata' => $result['metadata'] ?? [],
                ];
            }

            $rawText = trim(implode("\n\n", $texts));

            if (mb_strlen($rawText) < 20) {
                $extraction->update([
                    'status' => 'failed',
                    'raw_text' => $rawText !== '' ? $rawText : null,
                    'confidence_score' => 0,
                    'error_message' => 'OCR PDF completato, ma non ha restituito testo utile.',
                    'metadata' => array_merge($extraction->metadata ?? [], [
                        'conversion' => [
                            'directory' => $conversion['directory'],
                            'generated_pages' => count($conversion['paths']),
                            'dpi' => $conversion['dpi'],
                            'max_pages' => $conversion['max_pages'],
                        ],
                        'pages' => $pageMetadata,
                    ]),
                    'completed_at' => now(),
                ]);

                $document->update([
                    'text_extraction_status' => 'failed',
                ]);

                return $extraction->refresh();
            }

            $confidenceScore = $scores !== []
                ? (int) round(array_sum($scores) / count($scores))
                : $this->estimateConfidenceScore($rawText);

            $extraction->update([
                'status' => 'completed',
                'raw_text' => $rawText,
                'confidence_score' => $confidenceScore,
                'metadata' => array_merge($extraction->metadata ?? [], [
                    'conversion' => [
                        'directory' => $conversion['directory'],
                        'generated_pages' => count($conversion['paths']),
                        'dpi' => $conversion['dpi'],
                        'max_pages' => $conversion['max_pages'],
                    ],
                    'pages' => $pageMetadata,
                    'text_length' => mb_strlen($rawText),
                ]),
                'completed_at' => now(),
            ]);

            $document->update([
                'status' => 'text_extracted',
                'text_extraction_status' => 'completed',
                'raw_text' => $rawText,
                'document_confidence_score' => $confidenceScore,
            ]);

            return $extraction->refresh();
        } catch (Throwable $exception) {
            $extraction->update([
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
                'completed_at' => now(),
            ]);

            $document->update([
                'text_extraction_status' => 'failed',
            ]);

            return $extraction->refresh();
        }
    }

    /**
     * Segna un documento come bisognoso di OCR.
     *
     * Per ora resta utile per PDF scansionati o futuri casi non gestiti.
     */
    private function markAsRequiresOcr(
        Document $document,
        string $engine,
        ?string $mimeType,
        string $reason
    ): DocumentTextExtraction {
        $extraction = DocumentTextExtraction::query()->create([
            'document_id' => $document->id,
            'engine' => $engine,
            'status' => 'requires_ocr',
            'raw_text' => null,
            'confidence_score' => 0,
            'metadata' => [
                'mime_type' => $mimeType,
                'reason' => $reason,
            ],
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        $document->update([
            'text_extraction_status' => 'requires_ocr',
        ]);

        return $extraction;
    }

    /**
     * Registra un fallimento tecnico dell’estrazione.
     */
    private function failExtraction(Document $document, string $engine, string $message): DocumentTextExtraction
    {
        $extraction = DocumentTextExtraction::query()->create([
            'document_id' => $document->id,
            'engine' => $engine,
            'status' => 'failed',
            'raw_text' => null,
            'confidence_score' => 0,
            'error_message' => $message,
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        $document->update([
            'text_extraction_status' => 'failed',
        ]);

        return $extraction;
    }

    /**
     * Stima molto semplice della confidenza del testo estratto.
     *
     * Non è ancora uno score intelligente: serve solo a distinguere
     * testo quasi vuoto da testo ragionevolmente utilizzabile.
     */
    private function estimateConfidenceScore(string $text): int
    {
        $length = mb_strlen($text);

        if ($length < 50) {
            return 30;
        }

        if ($length < 250) {
            return 55;
        }

        if ($length < 1000) {
            return 75;
        }

        return 90;
    }
}