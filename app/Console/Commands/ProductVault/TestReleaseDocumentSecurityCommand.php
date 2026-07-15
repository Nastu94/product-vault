<?php

namespace App\Console\Commands\ProductVault;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Throwable;

final class TestReleaseDocumentSecurityCommand extends Command
{
    protected $signature =
        'product-vault:test-release-document-security';

    protected $description =
        'Verifica storage privato, autorizzazioni e consegna sicura dei documenti.';

    public function handle(): int
    {
        $rows = [];
        $failures = [];

        $assertSame = function (
            string $scenario,
            string $assertion,
            mixed $expected,
            mixed $actual
        ) use (&$rows, &$failures): void {
            $passed = $expected === $actual;
            $rows[] = [$scenario, $assertion, $passed ? 'OK' : 'FAIL'];

            if (! $passed) {
                $failures[] = compact(
                    'scenario',
                    'assertion',
                    'expected',
                    'actual'
                );
            }
        };

        try {
            $paths = [
                'filesystems' => config_path('filesystems.php'),
                'document' => app_path('Models/Document.php'),
                'policy' => app_path('Policies/DocumentPolicy.php'),
                'preview' => app_path(
                    'Http/Controllers/Documents/DocumentFilePreviewController.php'
                ),
                'download' => app_path(
                    'Http/Controllers/Documents/DocumentFileDownloadController.php'
                ),
            ];

            foreach ($paths as $path) {
                if (! File::exists($path)) {
                    throw new RuntimeException(
                        'File richiesto non disponibile: ' . $path
                    );
                }
            }

            $filesystems = File::get($paths['filesystems']);
            $document = File::get($paths['document']);
            $policy = File::get($paths['policy']);
            $preview = File::get($paths['preview']);
            $download = File::get($paths['download']);

            $assertSame(
                'storage',
                'local disk uses private root',
                true,
                str_contains(
                    $filesystems,
                    "'root' => storage_path('app/private')"
                )
            );
            $assertSame(
                'storage',
                'original files use local disk',
                true,
                str_contains(
                    $document,
                    "->addMediaCollection('original_file')"
                )
                    && str_contains($document, "->useDisk('local')")
            );
            $assertSame(
                'authorization',
                'document policy enforces current team',
                true,
                substr_count(
                    $policy,
                    '$user->current_team_id === $document->team_id'
                ) >= 3
            );

            foreach (
                [
                    'preview' => $preview,
                    'download' => $download,
                ] as $name => $controller
            ) {
                $assertSame(
                    'delivery',
                    $name . ' authorizes document',
                    true,
                    str_contains(
                        $controller,
                        "Gate::authorize('view', \$document)"
                    )
                );
                $assertSame(
                    'delivery',
                    $name . ' checks physical file',
                    true,
                    str_contains($controller, 'abort_unless(is_file($path)')
                );
                $assertSame(
                    'delivery',
                    $name . ' sends nosniff header',
                    true,
                    str_contains(
                        $controller,
                        "'X-Content-Type-Options' => 'nosniff'"
                    )
                );
                $assertSame(
                    'delivery',
                    $name . ' avoids public storage URLs',
                    false,
                    str_contains($controller, 'Storage::url')
                );
            }

            foreach (
                ['documents.preview', 'documents.download']
                as $routeName
            ) {
                $route = Route::getRoutes()->getByName($routeName);
                $middleware = $route?->gatherMiddleware() ?? [];

                $assertSame(
                    'routing',
                    $routeName . ' protected by auth',
                    true,
                    $route !== null
                        && collect($middleware)->contains(
                            fn (string $item): bool =>
                                str_starts_with($item, 'auth')
                        )
                );
            }
        } catch (Throwable $exception) {
            $rows[] = ['runtime', 'document security completed', 'FAIL'];
            $failures[] = [
                'scenario' => 'runtime',
                'assertion' => 'document security completed',
                'expected' => 'no exception',
                'actual' => $exception::class
                    . ': '
                    . $exception->getMessage(),
            ];
        }

        $this->table(['Scenario', 'Assertion', 'Status'], $rows);

        if ($failures !== []) {
            foreach ($failures as $failure) {
                $this->error(
                    $failure['scenario']
                    . ' / '
                    . $failure['assertion']
                );
                $this->line(
                    'Expected: '
                    . var_export($failure['expected'], true)
                );
                $this->line(
                    'Actual: '
                    . var_export($failure['actual'], true)
                );
            }

            return self::FAILURE;
        }

        $this->info('Release document security checks passed.');

        return self::SUCCESS;
    }
}
