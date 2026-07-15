<?php

namespace App\Console\Commands\ProductVault;

use App\Livewire\Account\GettingStarted;
use App\Models\Team;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Throwable;

final class TestReleaseLegalUiCommand extends Command
{
    protected $signature =
        'product-vault:test-release-legal-ui';

    protected $description =
        'Verifica pagine legali, onboarding, footer e navigazione di release.';

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

        DB::beginTransaction();

        try {
            foreach ([
                'legal.privacy',
                'legal.terms',
                'legal.document-processing',
            ] as $routeName) {
                $assertSame(
                    'routing',
                    $routeName . ' available',
                    true,
                    Route::has($routeName)
                );
            }

            $legalFiles = [
                'privacy' => resource_path(
                    'views/legal/privacy.blade.php'
                ),
                'terms' => resource_path(
                    'views/legal/terms.blade.php'
                ),
                'document-processing' => resource_path(
                    'views/legal/document-processing.blade.php'
                ),
            ];

            foreach ($legalFiles as $key => $path) {
                $source = File::get($path);

                $assertSame(
                    'legal source',
                    $key . ' marker present',
                    true,
                    str_contains(
                        $source,
                        'data-testid="legal-' . $key . '"'
                    )
                );
            }

            $privacy = File::get($legalFiles['privacy']);
            $terms = File::get($legalFiles['terms']);
            $processing = File::get(
                $legalFiles['document-processing']
            );

            $assertSame(
                'legal source',
                'privacy pilot warning present',
                true,
                str_contains($privacy, 'deve essere verificata e adattata')
            );
            $assertSame(
                'legal source',
                'coverage estimate warning present',
                true,
                str_contains(
                    $terms,
                    'non costituisce conferma giuridica'
                )
            );
            $assertSame(
                'legal source',
                'document product separation explained',
                true,
                str_contains(
                    $processing,
                    'Documento e prodotto restano distinti'
                )
            );

            $layout = File::get(
                resource_path('views/layouts/app.blade.php')
            );
            $navigation = File::get(
                resource_path('views/navigation-menu.blade.php')
            );
            $welcome = File::get(
                resource_path('views/welcome.blade.php')
            );

            $assertSame(
                'layout',
                'legal footer links present',
                true,
                str_contains($layout, "route('legal.privacy')")
                    && str_contains($layout, "route('legal.terms')")
                    && str_contains(
                        $layout,
                        "route('legal.document-processing')"
                    )
            );
            $assertSame(
                'welcome',
                'public legal footer links present',
                true,
                str_contains($welcome, "route('legal.privacy')")
                    && str_contains($welcome, "route('legal.terms')")
                    && str_contains(
                        $welcome,
                        "route('legal.document-processing')"
                    )
            );
            $assertSame(
                'navigation',
                'getting started linked desktop and mobile',
                2,
                substr_count(
                    $navigation,
                    "route('account.getting-started')"
                )
            );

            $team = Team::query()->orderBy('id')->first();

            if ($team === null) {
                throw new RuntimeException(
                    'Nessun workspace disponibile per il test.'
                );
            }

            $user = User::query()->find($team->user_id);

            if ($user === null) {
                throw new RuntimeException(
                    'Proprietario workspace non disponibile.'
                );
            }

            $user->forceFill([
                'current_team_id' => $team->id,
            ])->save();
            $user->refresh();
            Auth::login($user);

            $component = app(GettingStarted::class);
            $component->mount();

            $assertSame(
                'onboarding',
                'workspace exposed',
                $team->name,
                $component->workspaceName
            );
            $assertSame(
                'onboarding',
                'six steps exposed',
                6,
                $component->totalSteps
            );
            $assertSame(
                'onboarding',
                'workspace step completed',
                true,
                data_get($component->steps, '0.completed')
            );

            $html = $component
                ->render()
                ->with([
                    'workspaceName' => $component->workspaceName,
                    'steps' => $component->steps,
                    'completedSteps' => $component->completedSteps,
                    'totalSteps' => $component->totalSteps,
                ])
                ->render();

            $assertSame(
                'onboarding',
                'getting started marker rendered',
                true,
                str_contains($html, 'data-testid="getting-started"')
            );
            $assertSame(
                'onboarding',
                'source verification warning rendered',
                true,
                str_contains(
                    $html,
                    'verifica della fonte'
                )
            );
        } catch (Throwable $exception) {
            $rows[] = ['runtime', 'release legal UI completed', 'FAIL'];
            $failures[] = [
                'scenario' => 'runtime',
                'assertion' => 'release legal UI completed',
                'expected' => 'no exception',
                'actual' => $exception::class
                    . ': '
                    . $exception->getMessage(),
            ];
        } finally {
            Auth::logout();
            DB::rollBack();
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

        $this->info('Release legal and onboarding UI checks passed.');

        return self::SUCCESS;
    }
}
