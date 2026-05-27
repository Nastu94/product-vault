<?php

namespace App\Services\Documents;

use App\Models\Document;
use App\Models\DocumentTextExtraction;
use Smalot\PdfParser\Parser;
use Throwable;

class DocumentTextExtractionPipeline
{
    /**
     * Avvia il primo tentativo reale di estrazione testo.
     *
     * MVP attuale:
     * - PDF digitale: estrazione con Smalot PDF Parser.
     * - Immagini: richiedono OCR, non ancora implementato.
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
            return $this->markAsRequiresOcr(
                document: $document,
                engine: 'image_ocr_pending',
                mimeType: $mimeType,
                reason: 'Il file è un’immagine. L’OCR verrà implementato nello step successivo.'
            );
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
                    ]),
                    'completed_at' => now(),
                ]);

                $document->update([
                    'text_extraction_status' => 'requires_ocr',
                ]);

                return $extraction->refresh();
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
     * Segna un documento come bisognoso di OCR.
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