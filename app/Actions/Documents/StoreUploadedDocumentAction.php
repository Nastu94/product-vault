<?php

namespace App\Actions\Documents;

use App\Models\Document;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class StoreUploadedDocumentAction
{
    /**
     * Salva il record Document e associa il file originale
     * alla media collection original_file.
     *
     * In questo punto non facciamo ancora OCR, parsing o classificazione:
     * salviamo solo la prova originale in modo privato e tracciabile.
     */
    public function handle(TemporaryUploadedFile|UploadedFile $file): Document
    {
        $user = Auth::user();

        $teamId = $user->current_team_id ?? $user->currentTeam?->id;

        abort_unless($user && $teamId, 403, 'Nessun workspace attivo.');

        $document = new Document();

        $document->team_id = $teamId;
        $document->uploaded_by_user_id = $user->id;
        $document->status = 'uploaded';
        $document->source = 'manual_upload';
        $document->original_filename = $file->getClientOriginalName();
        $document->mime_type = $file->getMimeType();
        $document->file_size = $file->getSize();

        $document->save();

        /*
        |--------------------------------------------------------------------------
        | Nome file sicuro
        |--------------------------------------------------------------------------
        |
        | Manteniamo il nome originale nei metadati del documento, ma salviamo
        | fisicamente il file con un nome normalizzato per evitare caratteri strani,
        | collisioni e problemi futuri su filesystem diversi.
        |
        */
        $originalName = $file->getClientOriginalName();
        $baseName = pathinfo($originalName, PATHINFO_FILENAME);

        $safeBaseName = Str::slug(Str::ascii($baseName));

        if ($safeBaseName === '') {
            $safeBaseName = 'documento';
        }

        $extension = strtolower(
            $file->getClientOriginalExtension()
                ?: $file->guessExtension()
                ?: 'bin'
        );

        $storedFileName = $safeBaseName
            . '-'
            . now()->format('YmdHis')
            . '-'
            . Str::lower(Str::random(8))
            . '.'
            . $extension;

        /*
        |--------------------------------------------------------------------------
        | Media Library
        |--------------------------------------------------------------------------
        |
        | La collection original_file deve restare privata.
        | Non usiamo cartelle pubbliche e non esponiamo direttamente il path.
        |
        */
        $document
            ->addMedia($file->getRealPath())
            ->usingName($originalName)
            ->usingFileName($storedFileName)
            ->withCustomProperties([
                'original_client_filename' => $originalName,
                'uploaded_by_user_id' => $user->id,
                'team_id' => $teamId,
                'source' => 'manual_upload',
            ])
            ->toMediaCollection('original_file', 'local');

        return $document->refresh();
    }
}