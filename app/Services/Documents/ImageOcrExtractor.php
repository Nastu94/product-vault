<?php

namespace App\Services\Documents;

use RuntimeException;
use Symfony\Component\Process\Process;

class ImageOcrExtractor
{
    /**
     * Esegue OCR su un'immagine usando Tesseract.
     *
     * Prima crea una copia preprocessata dell'immagine:
     * - ridimensionamento se troppo piccola;
     * - scala di grigi;
     * - aumento contrasto;
     * - salvataggio temporaneo in PNG.
     */
    public function extract(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException('File immagine non trovato per OCR.');
        }

        $preparedPath = $this->prepareImageForOcr($path);

        try {
            return $this->runTesseract($preparedPath, $path);
        } finally {
            if ($preparedPath !== $path && is_file($preparedPath)) {
                @unlink($preparedPath);
            }
        }
    }

    /**
     * Prepara l'immagine per migliorare la lettura OCR.
     */
    private function prepareImageForOcr(string $path): string
    {
        if (! extension_loaded('gd')) {
            return $path;
        }

        $imageInfo = @getimagesize($path);

        if (! $imageInfo) {
            return $path;
        }

        [$width, $height] = $imageInfo;
        $mimeType = $imageInfo['mime'] ?? null;

        $source = match ($mimeType) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            default => false,
        };

        if (! $source) {
            return $path;
        }

        /*
        |--------------------------------------------------------------------------
        | Ridimensionamento
        |--------------------------------------------------------------------------
        |
        | Tesseract lavora meglio se il testo non è troppo piccolo.
        | Per scontrini/foto portiamo la larghezza almeno a 1800 px.
        |
        */
        $targetWidth = max($width, 1800);
        $targetHeight = (int) round($height * ($targetWidth / $width));

        $prepared = imagecreatetruecolor($targetWidth, $targetHeight);

        imagecopyresampled(
            $prepared,
            $source,
            0,
            0,
            0,
            0,
            $targetWidth,
            $targetHeight,
            $width,
            $height
        );

        /*
        |--------------------------------------------------------------------------
        | Pulizia base
        |--------------------------------------------------------------------------
        |
        | In GD il contrasto funziona al contrario:
        | valori negativi aumentano il contrasto.
        |
        */
        imagefilter($prepared, IMG_FILTER_GRAYSCALE);
        imagefilter($prepared, IMG_FILTER_CONTRAST, -35);
        imagefilter($prepared, IMG_FILTER_SMOOTH, -2);

        $temporaryPath = storage_path('app/tmp/ocr_' . uniqid('', true) . '.png');

        if (! is_dir(dirname($temporaryPath))) {
            mkdir(dirname($temporaryPath), 0775, true);
        }

        imagepng($prepared, $temporaryPath);

        imagedestroy($source);
        imagedestroy($prepared);

        return $temporaryPath;
    }

    /**
     * Esegue Tesseract sull'immagine preparata.
     */
    private function runTesseract(string $preparedPath, string $originalPath): array
    {
        $binary = (string) config('services.tesseract.binary', 'tesseract');
        $lang = (string) config('services.tesseract.lang', 'ita+eng');
        $psm = (string) config('services.tesseract.psm', 4);
        $timeout = (int) config('services.tesseract.timeout', 60);

        $process = new Process([
            $binary,
            $preparedPath,
            'stdout',
            '-l',
            $lang,
            '--psm',
            $psm,
        ]);

        $process->setTimeout($timeout);
        $process->run();

        if (! $process->isSuccessful()) {
            $error = trim($process->getErrorOutput()) ?: trim($process->getOutput());

            throw new RuntimeException(
                'Tesseract OCR fallito: ' . ($error ?: 'errore sconosciuto')
            );
        }

        $rawText = $this->normalizeOcrText($process->getOutput());

        return [
            'raw_text' => $rawText,
            'metadata' => [
                'binary' => $binary,
                'lang' => $lang,
                'psm' => $psm,
                'timeout' => $timeout,
                'original_path' => $originalPath,
                'prepared_path_used' => $preparedPath !== $originalPath,
                'text_length' => mb_strlen($rawText),
            ],
        ];
    }

    /**
     * Normalizza leggermente il testo OCR senza distruggere le righe.
     */
    private function normalizeOcrText(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        $lines = collect(explode("\n", $text))
            ->map(fn (string $line) => trim(preg_replace('/[ \t]+/u', ' ', $line) ?: $line))
            ->filter(fn (string $line) => $line !== '')
            ->values()
            ->all();

        return trim(implode("\n", $lines));
    }
}