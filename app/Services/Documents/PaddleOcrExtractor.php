<?php

namespace App\Services\Documents;

use RuntimeException;
use Symfony\Component\Process\Process;

class PaddleOcrExtractor
{
    /**
     * Esegue OCR su immagine usando PaddleOCR locale via script Python.
     */
    public function extract(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException('File immagine non trovato per PaddleOCR.');
        }

        $python = (string) config('services.paddleocr.python');
        $script = (string) config('services.paddleocr.script');
        $lang = (string) config('services.paddleocr.lang', 'it');
        $timeout = (int) config('services.paddleocr.timeout', 180);

        if (! is_file($python)) {
            throw new RuntimeException('Interprete Python PaddleOCR non trovato: ' . $python);
        }

        if (! is_file($script)) {
            throw new RuntimeException('Script PaddleOCR non trovato: ' . $script);
        }

        $process = new Process([
            $python,
            $script,
            $path,
            '--lang',
            $lang,
        ]);

        $process->setTimeout($timeout);
        $process->run();

        $output = trim($process->getOutput());
        $errorOutput = trim($process->getErrorOutput());

        $payload = $this->extractJsonPayload($output . "\n" . $errorOutput);

        if (! $payload) {
            throw new RuntimeException(
                'PaddleOCR non ha restituito JSON valido. Output: ' . mb_substr($output . ' ' . $errorOutput, 0, 1000)
            );
        }

        if (($payload['ok'] ?? false) !== true) {
            throw new RuntimeException(
                'PaddleOCR fallito: ' . ($payload['error'] ?? 'errore sconosciuto')
            );
        }

        $rawText = trim((string) ($payload['raw_text'] ?? ''));

        if ($rawText === '') {
            throw new RuntimeException('PaddleOCR completato, ma non ha restituito testo.');
        }

        return [
            'raw_text' => $rawText,
            'confidence_score' => (int) ($payload['confidence_score'] ?? 0),

            /*
            |--------------------------------------------------------------------------
            | Compatibilità con la pipeline attuale
            |--------------------------------------------------------------------------
            |
            | lines resta disponibile per non rompere il codice già esistente.
            | items/layout sono il nuovo output strutturato per i parser layout-aware.
            |
            */
            'lines' => $payload['lines'] ?? [],
            'items' => $payload['items'] ?? [],
            'layout' => $payload['layout'] ?? null,

            'metadata' => [
                'engine' => $payload['engine'] ?? 'paddleocr',
                'lang' => $payload['lang'] ?? $lang,
                'api_mode' => $payload['api_mode'] ?? null,
                'line_count' => $payload['metadata']['line_count'] ?? count($payload['lines'] ?? []),
                'item_count' => $payload['metadata']['item_count'] ?? count($payload['items'] ?? []),
                'visual_line_count' => $payload['metadata']['visual_line_count'] ?? null,
                'average_confidence' => $payload['metadata']['average_confidence'] ?? null,
                'image_width' => $payload['metadata']['image_width'] ?? null,
                'image_height' => $payload['metadata']['image_height'] ?? null,
                'text_length' => mb_strlen($rawText),
            ],
        ];
    }

    /**
     * Estrae il JSON finale anche se PaddleOCR stampa log prima del payload.
     */
    private function extractJsonPayload(string $output): ?array
    {
        $position = strrpos($output, '{"ok"');

        if ($position === false) {
            return null;
        }

        $json = trim(substr($output, $position));

        $payload = json_decode($json, true);

        return is_array($payload) ? $payload : null;
    }
}
