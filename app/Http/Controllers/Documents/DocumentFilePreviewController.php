<?php

namespace App\Http\Controllers\Documents;

use App\Http\Controllers\Controller;
use App\Models\Document;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DocumentFilePreviewController extends Controller
{
    /**
     * Mostra il file originale in modalità inline.
     *
     * Il file resta su storage privato e viene servito solo dopo
     * autorizzazione tramite DocumentPolicy.
     */
    public function __invoke(Document $document): BinaryFileResponse
    {
        Gate::authorize('view', $document);

        $media = $document->getFirstMedia('original_file');

        abort_unless($media, 404, 'File originale non trovato.');

        $path = $media->getPath();

        abort_unless(is_file($path), 404, 'File fisico non trovato.');

        $fileName = $document->original_filename ?: $media->file_name;

        // Evita problemi nel Content-Disposition con doppi apici nel nome file.
        $safeFileName = str_replace('"', '', $fileName);

        return response()->file($path, [
            'Content-Type' => $media->mime_type ?: $document->mime_type ?: 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="' . $safeFileName . '"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}