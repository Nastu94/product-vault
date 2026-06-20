<?php

namespace App\Console\Commands\ProductVault;

use App\Models\Document;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

#[Signature('product-vault:test-recognition-quality-contract
    {--expected=storage/app/testing/BATCH01_expected.json : Percorso JSON expected usato come riferimento}
    {--filename= : Seleziona un documento expected specifico, anche tramite testo parziale}')]
#[Description('Verifica gli exit code del Recognition Quality Contract')]
class TestRecognitionQualityContractCommand extends Command
{
    /**
     * Verifica che recognition e completion producano gli exit code attesi.
     *
     * Il comando:
     * - non rilancia la pipeline;
     * - non modifica il database;
     * - non modifica il file expected originale;
     * - crea soltanto file expected temporanei, eliminati al termine.
     */
    public function handle(): int
    {
        $expectedPath = $this->resolveExpectedPath(
            (string) $this->option('expected')
        );

        if ($expectedPath === null) {
            $this->error('File expected di riferimento non trovato.');

            return self::FAILURE;
        }

        $expectedBatch = $this->loadExpectedBatch($expectedPath);

        if ($expectedBatch === null) {
            return self::FAILURE;
        }

        $filenameFilter = trim(
            (string) ($this->option('filename') ?? '')
        );

        $expectedDocument = $this->selectExpectedDocument(
            expectedBatch: $expectedBatch,
            filenameFilter: $filenameFilter
        );

        if ($expectedDocument === null) {
            $this->error(
                'Nessun documento expected compatibile è presente nel database.'
            );

            if ($filenameFilter !== '') {
                $this->line('Filtro filename richiesto: ' . $filenameFilter);
            }

            return self::FAILURE;
        }

        $filename = (string) $expectedDocument['filename'];

        $this->info(
            'Documento di riferimento: ' . $filename
        );

        $results = [];

        foreach ($this->scenarios($expectedDocument) as $scenario) {
            $results[] = $this->runScenario($scenario);
        }

        $this->newLine();

        $this->table([
            'Scenario',
            'Expected Exit',
            'Actual Exit',
            'Output',
            'Status',
        ], array_map(
            fn (array $result): array => [
                $result['scenario'],
                $this->exitCodeLabel($result['expected_exit']),
                $this->exitCodeLabel($result['actual_exit']),
                $result['missing_output'] === []
                    ? 'OK'
                    : 'missing: ' . implode(', ', $result['missing_output']),
                $result['passed'] ? 'OK' : 'FAIL',
            ],
            $results
        ));

        $failedResults = array_values(array_filter(
            $results,
            fn (array $result): bool => ! $result['passed']
        ));

        if ($failedResults !== []) {
            foreach ($failedResults as $failedResult) {
                $this->newLine();

                $this->error(
                    'Scenario fallito: ' . $failedResult['scenario']
                );

                if ($failedResult['exception'] !== null) {
                    $this->line(
                        'Eccezione: ' . $failedResult['exception']
                    );
                }

                if ($failedResult['missing_output'] !== []) {
                    $this->line(
                        'Frammenti output mancanti: '
                        . implode(', ', $failedResult['missing_output'])
                    );
                }

                $this->line('Output validatore:');
                $this->line(
                    $failedResult['output'] !== ''
                        ? $failedResult['output']
                        : '[nessun output]'
                );
            }

            $this->newLine();

            $this->error(
                'Recognition Quality Contract checks failed.'
            );

            return self::FAILURE;
        }

        $this->newLine();

        $this->info(
            'Recognition Quality Contract checks passed.'
        );

        return self::SUCCESS;
    }

    /**
     * Costruisce gli scenari del contratto partendo da un expected valido.
     *
     * @return array<int, array<string, mixed>>
     */
    private function scenarios(array $expectedDocument): array
    {
        $baselineDocument = $expectedDocument;

        /*
         * Merchant è un campo di completion. Anche con --strict e
         * --fail-on-warnings, una sua differenza non deve bloccare.
         */
        $completionWarningDocument = $expectedDocument;
        $completionWarningDocument['expected']['merchant'] =
            '__recognition_contract_completion_mismatch__';

        /*
         * Il totale documento è un warning strutturale di recognition.
         * Con --fail-on-warnings deve produrre exit code failure.
         */
        $recognitionWarningDocument = $expectedDocument;
        $recognitionWarningDocument['expected']['total_amount'] =
            '987654321.99';

        /*
         * Un numero candidati errato è un recognition error e deve
         * fallire indipendentemente dalla presenza di warning.
         */
        $recognitionErrorDocument = $expectedDocument;
        $recognitionErrorDocument['expected']['candidates_count'] =
            999999;

        return [
            [
                'name' => 'baseline_success',
                'expected_document' => $baselineDocument,
                'strict' => false,
                'fail_on_warnings' => true,
                'expected_exit' => self::SUCCESS,
                'required_output' => [
                    'Batch recognition validation passed.',
                ],
            ],
            [
                'name' => 'completion_warning_non_blocking',
                'expected_document' => $completionWarningDocument,
                'strict' => true,
                'fail_on_warnings' => true,
                'expected_exit' => self::SUCCESS,
                'required_output' => [
                    'merchant expected',
                    'Sono presenti warning non bloccanti di completion.',
                ],
            ],
            [
                'name' => 'recognition_warning_blocked',
                'expected_document' => $recognitionWarningDocument,
                'strict' => true,
                'fail_on_warnings' => true,
                'expected_exit' => self::FAILURE,
                'required_output' => [
                    'total_amount expected',
                    'Batch recognition validation completed with warnings.',
                ],
            ],
            [
                'name' => 'recognition_error_blocked',
                'expected_document' => $recognitionErrorDocument,
                'strict' => false,
                'fail_on_warnings' => true,
                'expected_exit' => self::FAILURE,
                'required_output' => [
                    'candidates_count expected',
                    'Batch recognition validation failed.',
                ],
            ],
        ];
    }

    /**
     * Esegue uno scenario usando un file expected temporaneo.
     *
     * @return array{
     *     scenario:string,
     *     expected_exit:int,
     *     actual_exit:int,
     *     missing_output:array<int, string>,
     *     output:string,
     *     exception:string|null,
     *     passed:bool
     * }
     */
    private function runScenario(array $scenario): array
    {
        $temporaryPath = null;
        $actualExit = self::FAILURE;
        $renderedOutput = '';
        $exceptionMessage = null;

        try {
            $temporaryPath = $this->writeTemporaryExpected([
                $scenario['expected_document'],
            ]);

            $arguments = [
                '--expected' => $temporaryPath,
            ];

            if ((bool) $scenario['strict']) {
                $arguments['--strict'] = true;
            }

            if ((bool) $scenario['fail_on_warnings']) {
                $arguments['--fail-on-warnings'] = true;
            }

            $output = new BufferedOutput();

            $actualExit = Artisan::call(
                command: 'product-vault:validate-document-batch',
                parameters: $arguments,
                outputBuffer: $output
            );

            $renderedOutput = trim($output->fetch());
        } catch (Throwable $exception) {
            $exceptionMessage = $exception->getMessage();
        } finally {
            if (
                $temporaryPath !== null
                && is_file($temporaryPath)
            ) {
                @unlink($temporaryPath);
            }
        }

        $missingOutput = [];

        foreach ((array) $scenario['required_output'] as $fragment) {
            if (! str_contains($renderedOutput, (string) $fragment)) {
                $missingOutput[] = (string) $fragment;
            }
        }

        $passed = $exceptionMessage === null
            && $actualExit === (int) $scenario['expected_exit']
            && $missingOutput === [];

        return [
            'scenario' => (string) $scenario['name'],
            'expected_exit' => (int) $scenario['expected_exit'],
            'actual_exit' => $actualExit,
            'missing_output' => $missingOutput,
            'output' => $renderedOutput,
            'exception' => $exceptionMessage,
            'passed' => $passed,
        ];
    }

    /**
     * Seleziona una riga expected che abbia un documento corrispondente nel DB.
     */
    private function selectExpectedDocument(
        array $expectedBatch,
        string $filenameFilter
    ): ?array {
        foreach ($expectedBatch as $row) {
            if (
                ! is_array($row)
                || ! isset($row['expected'])
                || ! is_array($row['expected'])
            ) {
                continue;
            }

            $filename = trim(
                (string) ($row['filename'] ?? '')
            );

            if ($filename === '') {
                continue;
            }

            if (
                $filenameFilter !== ''
                && ! str_contains($filename, $filenameFilter)
            ) {
                continue;
            }

            $documentExists = Document::query()
                ->where('original_filename', $filename)
                ->exists();

            if ($documentExists) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Scrive un file expected temporaneo.
     *
     * @param  array<int, array<string, mixed>>  $payload
     */
    private function writeTemporaryExpected(array $payload): string
    {
        $directory = storage_path('app/testing');

        if (
            ! is_dir($directory)
            && ! mkdir($directory, 0775, true)
            && ! is_dir($directory)
        ) {
            throw new RuntimeException(
                'Impossibile creare la directory temporanea: '
                . $directory
            );
        }

        $temporaryPath = tempnam(
            $directory,
            'recognition_contract_'
        );

        if ($temporaryPath === false) {
            throw new RuntimeException(
                'Impossibile creare il file expected temporaneo.'
            );
        }

        $json = json_encode(
            $payload,
            JSON_PRETTY_PRINT
            | JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_THROW_ON_ERROR
        );

        if (file_put_contents($temporaryPath, $json) === false) {
            @unlink($temporaryPath);

            throw new RuntimeException(
                'Impossibile scrivere il file expected temporaneo.'
            );
        }

        return $temporaryPath;
    }

    /**
     * Carica il file expected di riferimento.
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function loadExpectedBatch(string $path): ?array
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            $this->error(
                'Impossibile leggere il file expected: ' . $path
            );

            return null;
        }

        try {
            $decoded = json_decode(
                $contents,
                true,
                flags: JSON_THROW_ON_ERROR
            );
        } catch (Throwable $exception) {
            $this->error(
                'JSON expected non valido: ' . $exception->getMessage()
            );

            return null;
        }

        if (! is_array($decoded)) {
            $this->error(
                'Il file expected non contiene un array valido.'
            );

            return null;
        }

        return $decoded;
    }

    /**
     * Risolve il percorso expected da path assoluto o relativo.
     */
    private function resolveExpectedPath(string $path): ?string
    {
        $path = trim($path);

        if ($path === '') {
            return null;
        }

        $candidates = [
            $path,
            base_path($path),
            storage_path($path),
            storage_path('app/' . $path),
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Traduce l'exit code in un'etichetta leggibile.
     */
    private function exitCodeLabel(int $exitCode): string
    {
        return match ($exitCode) {
            self::SUCCESS => 'SUCCESS',
            self::FAILURE => 'FAILURE',
            default => (string) $exitCode,
        };
    }
}