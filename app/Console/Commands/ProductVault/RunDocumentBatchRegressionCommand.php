<?php

namespace App\Console\Commands\ProductVault;

use App\Jobs\ProcessDocumentJob;
use App\Models\Document;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

#[Signature('product-vault:regression-batch-documents
    {--expected= : Percorso o glob expected JSON. Default: storage/app/testing/*_expected.json}
    {--filename= : Filtra per original_filename, anche parziale}
    {--strict : Passa --strict al validatore batch}
    {--show-candidates : Mostra dettaglio candidati nel validatore batch}
    {--allow-warnings : Non fallisce se il validatore batch produce warning}
    {--no-reprocess : Non rilancia la pipeline prima della validazione}')]
#[Description('Run Product Vault batch document regression from expected JSON files')]
class RunDocumentBatchRegressionCommand extends Command
{
    /**
     * Esegue la regressione sui batch documentali controllati.
     *
     * A differenza di validate-document-batch, questo comando può rilanciare
     * la pipeline sui documenti attesi prima della validazione. In questo modo
     * il batch diventa un vero guardrail contro regressioni di parsing,
     * classificazione e generazione candidati.
     */
    public function handle(): int
    {
        $expectedFiles = $this->resolveExpectedFiles((string) ($this->option('expected') ?? ''));

        if ($expectedFiles === []) {
            $this->error('Nessun file expected trovato per la regressione batch.');

            $this->line('Default cercato: ' . storage_path('app/testing/*_expected.json'));

            return self::FAILURE;
        }

        $filenameFilter = trim((string) ($this->option('filename') ?? ''));
        $strict = (bool) $this->option('strict');
        $showCandidates = (bool) $this->option('show-candidates');
        $allowWarnings = (bool) $this->option('allow-warnings');
        $noReprocess = (bool) $this->option('no-reprocess');

        $summaryRows = [];
        $failed = false;

        foreach ($expectedFiles as $expectedFile) {
            $reprocessResult = [
                'status' => $noReprocess ? 'SKIP' : 'OK',
                'selected' => 0,
                'processed' => 0,
                'missing' => 0,
                'errors' => [],
            ];

            if (! $noReprocess) {
                $reprocessResult = $this->reprocessExpectedDocuments(
                    expectedFile: $expectedFile,
                    filenameFilter: $filenameFilter
                );

                if ($reprocessResult['status'] !== 'OK') {
                    $failed = true;
                }
            }

            $arguments = [
                '--expected' => $expectedFile,
            ];

            if ($filenameFilter !== '') {
                $arguments['--filename'] = $filenameFilter;
            }

            if ($strict) {
                $arguments['--strict'] = true;
            }

            if ($showCandidates) {
                $arguments['--show-candidates'] = true;
            }

            if (! $allowWarnings) {
                $arguments['--fail-on-warnings'] = true;
            }

            $output = new BufferedOutput();

            $exitCode = Artisan::call(
                command: 'product-vault:validate-document-batch',
                parameters: $arguments,
                outputBuffer: $output
            );

            $this->newLine();
            $this->line('<info>Validazione batch:</info> ' . $expectedFile);
            $this->line(rtrim($output->fetch()));

            if ($exitCode !== self::SUCCESS) {
                $failed = true;
            }

            $summaryRows[] = [
                'expected' => basename($expectedFile),
                'docs' => $reprocessResult['processed'] . '/' . $reprocessResult['selected'],
                'missing' => $reprocessResult['missing'],
                'reprocess' => $reprocessResult['status'],
                'validate' => $exitCode === self::SUCCESS ? 'OK' : 'FAIL',
                'status' => $reprocessResult['status'] === 'OK' && $exitCode === self::SUCCESS ? 'OK' : 'FAIL',
                'errors' => $reprocessResult['errors'] === []
                    ? '-'
                    : implode(' | ', $reprocessResult['errors']),
            ];
        }

        $this->newLine();

        $this->table(
            ['Expected', 'Docs', 'Missing', 'Reprocess', 'Validate', 'Status', 'Errors'],
            $summaryRows
        );

        if ($failed) {
            $this->error('Batch document regression failed.');

            return self::FAILURE;
        }

        $this->info('Batch document regression passed.');

        return self::SUCCESS;
    }

    /**
     * Rilancia la pipeline per tutti i documenti indicati nel file expected.
     *
     * @return array{status:string, selected:int, processed:int, missing:int, errors:array<int, string>}
     */
    private function reprocessExpectedDocuments(string $expectedFile, string $filenameFilter): array
    {
        $expectedBatch = $this->loadExpectedBatch($expectedFile);

        if ($expectedBatch === null) {
            return [
                'status' => 'ERROR',
                'selected' => 0,
                'processed' => 0,
                'missing' => 0,
                'errors' => ['expected_json_invalid'],
            ];
        }

        $selectedRows = collect($expectedBatch)
            ->filter(fn (array $row): bool => $filenameFilter === ''
                || str_contains((string) ($row['filename'] ?? ''), $filenameFilter))
            ->values();

        if ($selectedRows->isEmpty()) {
            return [
                'status' => 'ERROR',
                'selected' => 0,
                'processed' => 0,
                'missing' => 0,
                'errors' => ['no_expected_rows_selected'],
            ];
        }

        $processed = 0;
        $missing = 0;
        $errors = [];

        foreach ($selectedRows as $expectedDocument) {
            $filename = (string) ($expectedDocument['filename'] ?? '');

            if ($filename === '') {
                $errors[] = 'empty_filename_in_expected';

                continue;
            }

            $document = Document::query()
                ->where('original_filename', $filename)
                ->latest('id')
                ->first();

            if (! $document) {
                $missing++;
                $errors[] = 'document_not_found:' . $filename;

                continue;
            }

            try {
                ProcessDocumentJob::dispatchSync($document->id);

                $processed++;
            } catch (Throwable $exception) {
                $errors[] = $filename . ': ' . $exception->getMessage();
            }
        }

        return [
            'status' => $errors === [] && $missing === 0 ? 'OK' : 'FAIL',
            'selected' => $selectedRows->count(),
            'processed' => $processed,
            'missing' => $missing,
            'errors' => $errors,
        ];
    }

    /**
     * Carica e valida il JSON expected.
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function loadExpectedBatch(string $path): ?array
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        $decoded = json_decode($contents, true);

        if (! is_array($decoded)) {
            return null;
        }

        foreach ($decoded as $row) {
            if (
                ! is_array($row)
                || empty($row['filename'])
                || ! isset($row['expected'])
                || ! is_array($row['expected'])
            ) {
                return null;
            }
        }

        return $decoded;
    }

    /**
     * Risolve uno o più file expected da path, path relativi o glob.
     *
     * Supporta:
     * - default storage/app/testing/*_expected.json
     * - storage/app/testing/BATCH01_expected.json
     * - path assoluti Windows/Linux
     * - lista separata da virgole
     */
    private function resolveExpectedFiles(string $option): array
    {
        $patterns = trim($option) !== ''
            ? explode(',', $option)
            : [storage_path('app/testing/*_expected.json')];

        $files = [];

        foreach ($patterns as $pattern) {
            $pattern = trim($pattern);

            if ($pattern === '') {
                continue;
            }

            foreach ($this->expectedPathCandidates($pattern) as $candidate) {
                $matches = glob($candidate) ?: [];

                foreach ($matches as $match) {
                    if (is_file($match)) {
                        $files[] = $match;
                    }
                }
            }
        }

        sort($files);

        return array_values(array_unique($files));
    }

    /**
     * Genera path candidati compatibili con path assoluti e relativi.
     *
     * @return array<int, string>
     */
    private function expectedPathCandidates(string $path): array
    {
        if ($this->isAbsolutePath($path)) {
            return [$path];
        }

        return [
            $path,
            base_path($path),
            storage_path($path),
            storage_path('app/' . $path),
        ];
    }

    /**
     * Riconosce path assoluti Linux/macOS e Windows.
     */
    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1;
    }
}