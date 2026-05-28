<?php

namespace App\Jobs;

use App\Models\Document;
use App\Models\DocumentProcessingAttempt;
use App\Services\Documents\DocumentTextExtractionPipeline;
use App\Services\Documents\DocumentClassifier;
use App\Services\Documents\DocumentDataParser;
use App\Services\Documents\MerchantParser;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessDocumentJob implements ShouldQueue
{
    use Queueable;

    /**
     * ID del documento da processare.
     */
    public int $documentId;

    /**
     * Crea una nuova istanza del job.
     */
    public function __construct(int $documentId)
    {
        $this->documentId = $documentId;
    }

    /**
     * Esegue la pipeline iniziale del documento.
     *
     * Step attuali:
     * 1. bootstrap: verifica record, media e file fisico.
     * 2. text_extraction: prova estrazione testo o marca il documento come "richiede OCR".
     * 3. classification: classifica il tipo documento se il testo è disponibile.
     * 4. parsing: estrae dati base come data, totale e valuta.
     * 5. merchant_parsing: prova a riconoscere o creare il venditore.
     */
    public function handle(
        DocumentTextExtractionPipeline $textExtractionPipeline,
        DocumentClassifier $documentClassifier,
        DocumentDataParser $documentDataParser,
        MerchantParser $merchantParser
    ): void {
        $document = Document::query()->findOrFail($this->documentId);

        $this->runBootstrapStep($document);

        $this->runTextExtractionStep($document->fresh(), $textExtractionPipeline);

        $this->runClassificationStep($document->fresh(), $documentClassifier);

        $this->runParsingStep($document->fresh(), $documentDataParser);

        $this->runMerchantParsingStep($document->fresh(), $merchantParser);
    }

    /**
     * Verifica che documento, media e file fisico siano presenti.
     */
    private function runBootstrapStep(Document $document): void
    {
        $attempt = $this->startAttempt($document, 'bootstrap', [
            'document_status_before' => $document->status,
            'text_extraction_status_before' => $document->text_extraction_status,
            'mime_type' => $document->mime_type,
            'file_size' => $document->file_size,
        ]);

        try {
            $media = $document->getFirstMedia('original_file');

            if (! $media) {
                throw new \RuntimeException('File originale non associato al documento.');
            }

            $path = $media->getPath();

            if (! is_file($path)) {
                throw new \RuntimeException('File fisico non trovato nello storage.');
            }

            $attempt->update([
                'status' => 'completed',
                'metadata' => array_merge($attempt->metadata ?? [], [
                    'media_id' => $media->id,
                    'media_disk' => $media->disk,
                    'media_file_name' => $media->file_name,
                    'media_size' => $media->size,
                    'document_status_after' => $document->fresh()->status,
                    'text_extraction_status_after' => $document->fresh()->text_extraction_status,
                ]),
                'completed_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $attempt->update([
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
                'exception_class' => $exception::class,
                'completed_at' => now(),
            ]);

            $document->update([
                'status' => 'failed',
                'text_extraction_status' => 'failed',
            ]);

            Log::error('Document processing bootstrap failed.', [
                'document_id' => $document->id,
                'exception' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    /**
     * Esegue il primo tentativo reale di estrazione testo.
     */
    private function runTextExtractionStep(
        Document $document,
        DocumentTextExtractionPipeline $textExtractionPipeline
    ): void {
        $attempt = $this->startAttempt($document, 'text_extraction', [
            'document_status_before' => $document->status,
            'text_extraction_status_before' => $document->text_extraction_status,
            'mime_type' => $document->mime_type,
            'file_size' => $document->file_size,
        ]);

        try {
            $document->update([
                'text_extraction_status' => 'running',
            ]);

            $extraction = $textExtractionPipeline->extract($document->fresh());

            $freshDocument = $document->fresh();

            $attempt->update([
                'status' => $extraction->status === 'failed' ? 'failed' : 'completed',
                'error_message' => $extraction->error_message,
                'metadata' => array_merge($attempt->metadata ?? [], [
                    'extraction_id' => $extraction->id,
                    'extraction_engine' => $extraction->engine,
                    'extraction_status' => $extraction->status,
                    'extraction_confidence_score' => $extraction->confidence_score,
                    'raw_text_length' => $extraction->raw_text ? mb_strlen($extraction->raw_text) : 0,
                    'document_status_after' => $freshDocument->status,
                    'text_extraction_status_after' => $freshDocument->text_extraction_status,
                ]),
                'completed_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $attempt->update([
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
                'exception_class' => $exception::class,
                'completed_at' => now(),
            ]);

            $document->update([
                'status' => 'failed',
                'text_extraction_status' => 'failed',
            ]);

            Log::error('Document text extraction failed.', [
                'document_id' => $document->id,
                'exception' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    /**
     * Classifica il documento se il testo è stato estratto correttamente.
     */
    private function runClassificationStep(
        Document $document,
        DocumentClassifier $documentClassifier
    ): void {
        /*
        |--------------------------------------------------------------------------
        | Guard clause
        |--------------------------------------------------------------------------
        |
        | Non classifichiamo immagini o PDF scansionati finché non avremo OCR.
        | In quei casi il documento resta in requires_ocr.
        |
        */
        if ($document->text_extraction_status !== 'completed' || blank($document->raw_text)) {
            return;
        }

        $attempt = $this->startAttempt($document, 'classification', [
            'document_status_before' => $document->status,
            'text_extraction_status_before' => $document->text_extraction_status,
            'raw_text_length' => mb_strlen((string) $document->raw_text),
        ]);

        try {
            $classification = $documentClassifier->classify($document->fresh());

            if (! $classification) {
                throw new \RuntimeException('Classificazione non salvata: tipo documento non trovato o non attivo.');
            }

            $freshDocument = $document->fresh();

            $attempt->update([
                'status' => 'completed',
                'metadata' => array_merge($attempt->metadata ?? [], [
                    'classification_id' => $classification->id,
                    'document_type_id' => $classification->document_type_id,
                    'document_type_code' => $classification->documentType?->code,
                    'document_type_name' => $classification->documentType?->name,
                    'classification_confidence_score' => $classification->confidence_score,
                    'matched_signals' => $classification->metadata['matched_signals'] ?? [],
                    'document_status_after' => $freshDocument->status,
                    'text_extraction_status_after' => $freshDocument->text_extraction_status,
                ]),
                'completed_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $attempt->update([
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
                'exception_class' => $exception::class,
                'completed_at' => now(),
            ]);

            $document->update([
                'status' => 'failed',
            ]);

            Log::error('Document classification failed.', [
                'document_id' => $document->id,
                'exception' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    /**
     * Estrae dati base dal documento classificato.
     */
    private function runParsingStep(
        Document $document,
        DocumentDataParser $documentDataParser
    ): void {
        /*
        |--------------------------------------------------------------------------
        | Guard clause
        |--------------------------------------------------------------------------
        |
        | Non facciamo parsing se non abbiamo testo estratto.
        | Le immagini e i PDF scansionati resteranno in requires_ocr finché
        | non implementeremo l'OCR.
        |
        */
        if ($document->text_extraction_status !== 'completed' || blank($document->raw_text)) {
            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Guard clause sullo stato
        |--------------------------------------------------------------------------
        |
        | Il parsing ha senso dopo una classificazione. Se il documento è ancora
        | text_extracted o classified va bene. Se è failed o unsupported, lo lasciamo stare.
        |
        */
        if (! in_array($document->status, ['text_extracted', 'classified'], true)) {
            return;
        }

        $attempt = $this->startAttempt($document, 'parsing', [
            'document_status_before' => $document->status,
            'text_extraction_status_before' => $document->text_extraction_status,
            'document_type_code_before' => $document->documentType?->code,
            'raw_text_length' => mb_strlen((string) $document->raw_text),
        ]);

        try {
            $parsedDocument = $documentDataParser->parse($document->fresh());

            $attempt->update([
                'status' => 'completed',
                'metadata' => array_merge($attempt->metadata ?? [], [
                    'document_status_after' => $parsedDocument->status,
                    'purchase_date' => $parsedDocument->purchase_date?->toDateString(),
                    'total_amount' => $parsedDocument->total_amount,
                    'currency_id' => $parsedDocument->currency_id,
                    'currency_code' => $parsedDocument->currency?->code,
                ]),
                'completed_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $attempt->update([
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
                'exception_class' => $exception::class,
                'completed_at' => now(),
            ]);

            $document->update([
                'status' => 'failed',
            ]);

            Log::error('Document parsing failed.', [
                'document_id' => $document->id,
                'exception' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    /**
     * Prova a riconoscere o creare il merchant/venditore del documento.
     */
    private function runMerchantParsingStep(
        Document $document,
        MerchantParser $merchantParser
    ): void {
        /*
        |--------------------------------------------------------------------------
        | Guard clause
        |--------------------------------------------------------------------------
        |
        | Il riconoscimento merchant richiede testo estratto.
        | Non ha senso eseguirlo su immagini o PDF scansionati prima dell'OCR.
        |
        */
        if ($document->text_extraction_status !== 'completed' || blank($document->raw_text)) {
            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Evita stati non processabili
        |--------------------------------------------------------------------------
        |
        | Se il documento è fallito o non supportato, non proviamo a creare merchant.
        |
        */
        if (in_array($document->status, ['failed', 'unsupported'], true)) {
            return;
        }

        $attempt = $this->startAttempt($document, 'merchant_parsing', [
            'document_status_before' => $document->status,
            'text_extraction_status_before' => $document->text_extraction_status,
            'document_type_code_before' => $document->documentType?->code,
            'raw_text_length' => mb_strlen((string) $document->raw_text),
        ]);

        try {
            $merchant = $merchantParser->parse($document->fresh());

            $freshDocument = $document->fresh();

            $attempt->update([
                'status' => 'completed',
                'metadata' => array_merge($attempt->metadata ?? [], [
                    'merchant_found' => $merchant !== null,
                    'merchant_id' => $merchant?->id,
                    'merchant_name' => $merchant?->name,
                    'merchant_vat_number' => $merchant?->vat_number,
                    'merchant_email' => $merchant?->email,
                    'document_merchant_id_after' => $freshDocument->merchant_id,
                    'document_status_after' => $freshDocument->status,
                    'text_extraction_status_after' => $freshDocument->text_extraction_status,
                ]),
                'completed_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $attempt->update([
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
                'exception_class' => $exception::class,
                'completed_at' => now(),
            ]);

            /*
            |--------------------------------------------------------------------------
            | Nota
            |--------------------------------------------------------------------------
            |
            | Il fallimento del merchant parsing non deve necessariamente invalidare
            | tutto il documento. Per ora lo lasciamo come errore bloccante perché siamo
            | in sviluppo e vogliamo accorgerci subito dei bug.
            |
            */
            $document->update([
                'status' => 'failed',
            ]);

            Log::error('Document merchant parsing failed.', [
                'document_id' => $document->id,
                'exception' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    /**
     * Crea un record di tracking per uno step di processing.
     */
    private function startAttempt(Document $document, string $step, array $metadata = []): DocumentProcessingAttempt
    {
        $attemptNumber = DocumentProcessingAttempt::query()
            ->where('document_id', $document->id)
            ->where('step', $step)
            ->count() + 1;

        return DocumentProcessingAttempt::query()->create([
            'document_id' => $document->id,
            'step' => $step,
            'status' => 'running',
            'handler' => self::class,
            'attempt_number' => $attemptNumber,
            'metadata' => $metadata,
            'started_at' => now(),
        ]);
    }
}