<?php

namespace App\Services\Documents;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

class PdfToImageConverter
{
    /**
     * Converte le prime pagine di un PDF in immagini PNG usando Poppler/pdftoppm.
     *
     * Serve per i PDF scansionati: se il PDF non contiene testo estraibile,
     * possiamo trasformare le pagine in immagini e passarle all'OCR.
     */
    public function convert(string $pdfPath): array
    {
        if (! is_file($pdfPath)) {
            throw new RuntimeException('PDF non trovato per conversione OCR: ' . $pdfPath);
        }

        $binary = (string) config('services.poppler.pdftoppm', 'pdftoppm');
        $dpi = (int) config('services.poppler.pdf_ocr_dpi', 220);
        $maxPages = (int) config('services.poppler.pdf_ocr_max_pages', 3);
        $timeout = (int) config('services.poppler.pdf_ocr_timeout', 180);

        if (! is_file($binary)) {
            throw new RuntimeException('pdftoppm non trovato: ' . $binary);
        }

        $directory = storage_path(
            'app/ocr/pdf-pages/' . now()->format('YmdHis') . '-' . Str::random(8)
        );

        File::ensureDirectoryExists($directory);

        $outputPrefix = $directory . DIRECTORY_SEPARATOR . 'page';

        $process = new Process([
            $binary,
            '-r',
            (string) $dpi,
            '-png',
            '-f',
            '1',
            '-l',
            (string) $maxPages,
            $pdfPath,
            $outputPrefix,
        ]);

        $process->setTimeout($timeout);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(
                'Conversione PDF in immagini fallita: ' .
                trim($process->getErrorOutput() ?: $process->getOutput())
            );
        }

        $paths = collect(File::files($directory))
            ->filter(fn ($file) => str_ends_with(strtolower($file->getFilename()), '.png'))
            ->sortBy(fn ($file) => $file->getFilename())
            ->map(fn ($file) => $file->getPathname())
            ->values()
            ->all();

        if ($paths === []) {
            throw new RuntimeException('Conversione PDF completata, ma nessuna immagine PNG generata.');
        }

        return [
            'directory' => $directory,
            'paths' => $paths,
            'dpi' => $dpi,
            'max_pages' => $maxPages,
        ];
    }
}