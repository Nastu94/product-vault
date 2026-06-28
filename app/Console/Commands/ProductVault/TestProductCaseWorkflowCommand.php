<?php

namespace App\Console\Commands\ProductVault;

use App\Models\Product;
use App\Models\ProductCase;
use App\Models\User;
use App\Services\ProductCases\ProductCaseCreator;
use App\Policies\ProductCasePolicy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use RuntimeException;
use Throwable;

class TestProductCaseWorkflowCommand extends Command
{
    /**
     * Nome del comando.
     *
     * Il comando verrà esteso nelle micro-patch successive con transizioni,
     * documenti, coperture, allegati e chiusura della pratica.
     *
     * @var string
     */
    protected $signature =
        'product-vault:test-product-case-workflow';

    /**
     * Descrizione del comando.
     *
     * @var string
     */
    protected $description =
        'Verifica con rollback la creazione controllata delle pratiche prodotto.';

    /**
     * Esegue il test senza lasciare dati persistiti.
     */
    public function handle(
        ProductCaseCreator $creator
    ): int {
        $rows = [];
        $failures = [];

        $createdCaseId = null;

        $casesBefore = ProductCase::query()->count();
        $teamsBefore = DB::table('teams')->count();

        $permissionRegistrar =
            app(PermissionRegistrar::class);

        $assertSame = function (
            string $scenario,
            string $assertion,
            mixed $expected,
            mixed $actual
        ) use (&$rows, &$failures): void {
            $passed = $expected === $actual;

            $rows[] = [
                $scenario,
                $assertion,
                $passed ? 'OK' : 'FAIL',
            ];

            if (! $passed) {
                $failures[] = [
                    'scenario' => $scenario,
                    'assertion' => $assertion,
                    'expected' => $expected,
                    'actual' => $actual,
                ];
            }
        };

        DB::beginTransaction();

        try {
            $product = Product::query()
                ->with('team')
                ->whereNotNull('team_id')
                ->orderBy('id')
                ->first();

            if (
                $product === null
                || $product->team === null
            ) {
                throw new RuntimeException(
                    'Nessun prodotto con team utilizzabile per il test.'
                );
            }

            /*
             * Usiamo il proprietario del team del prodotto.
             *
             * Il suo current_team_id viene allineato all'interno della
             * transazione e verrà quindi ripristinato dal rollback.
             */
            $user = User::query()
                ->find($product->team->user_id);

            if ($user === null) {
                throw new RuntimeException(
                    'Nessun utente utilizzabile per il test.'
                );
            }

            User::query()
                ->whereKey($user->id)
                ->update([
                    'current_team_id' => $product->team_id,
                ]);

            $user->refresh();

            /*
             * Nei comandi CLI non viene eseguito il middleware web che imposta
             * il team corrente per Spatie Permission. Replichiamo quindi
             * esplicitamente quel contesto.
             */
            $permissionRegistrar->setPermissionsTeamId(
                $product->team_id
            );

            $user->unsetRelation('roles');
            $user->unsetRelation('permissions');

            $requiredPermissions = [
                'product_cases.view',
                'product_cases.create',
                'product_cases.update',
                'product_cases.close',
                'product_cases.delete',
            ];

            $registeredPermissions = Permission::query()
                ->where('guard_name', 'web')
                ->whereIn('name', $requiredPermissions)
                ->pluck('name')
                ->all();

            sort($requiredPermissions);
            sort($registeredPermissions);

            $assertSame(
                'authorization',
                'permission catalog registered',
                $requiredPermissions,
                $registeredPermissions
            );

            $policy = Gate::getPolicyFor(
                ProductCase::class
            );

            $assertSame(
                'authorization',
                'policy auto-discovered',
                true,
                $policy instanceof ProductCasePolicy
            );

            $gate = Gate::forUser($user);

            $assertSame(
                'authorization',
                'owner can view case list',
                true,
                $gate->allows(
                    'viewAny',
                    ProductCase::class
                )
            );

            $assertSame(
                'authorization',
                'owner can create for current product',
                true,
                $gate->allows(
                    'create',
                    [
                        ProductCase::class,
                        $product,
                    ]
                )
            );

            /*
             * Creazione valida.
             *
             * Inseriamo anche campi di sistema contraffatti per verificare
             * che vengano ignorati dal creator.
             */
            $productCase = $creator->create(
                product: $product,
                openedBy: $user,
                attributes: [
                    'team_id' => 999999,
                    'product_id' => 999999,
                    'status' => ProductCase::STATUS_CLOSED,
                    'original_description' =>
                        'Descrizione contraffatta',
                    'outcome' =>
                        ProductCase::OUTCOME_REFUNDED,

                    'title' =>
                        '  Monitor che non si accende  ',
                    'description' =>
                        '  Il monitor non si accende dopo il collegamento.  ',
                    'occurred_on' => today()->toDateString(),
                    'usability_status' =>
                        ProductCase::USABILITY_UNUSABLE,
                    'accidental_damage_declared' => false,
                    'accidental_damage_notes' => '   ',
                ],
            );

            $createdCaseId = (int) $productCase->id;

            $gate = Gate::forUser($user);

            $assertSame(
                'authorization',
                'owner can view case',
                true,
                $gate->allows(
                    'view',
                    $productCase
                )
            );

            $assertSame(
                'authorization',
                'owner can update case',
                true,
                $gate->allows(
                    'update',
                    $productCase
                )
            );

            $assertSame(
                'authorization',
                'owner can close case',
                true,
                $gate->allows(
                    'close',
                    $productCase
                )
            );

            $assertSame(
                'authorization',
                'owner can delete case',
                true,
                $gate->allows(
                    'delete',
                    $productCase
                )
            );

            $assertSame(
                'valid_creation',
                'one case created',
                $casesBefore + 1,
                ProductCase::query()->count()
            );

            $assertSame(
                'valid_creation',
                'team derived from product',
                (int) $product->team_id,
                (int) $productCase->team_id
            );

            $assertSame(
                'valid_creation',
                'product assigned',
                (int) $product->id,
                (int) $productCase->product_id
            );

            $assertSame(
                'valid_creation',
                'opening user assigned',
                (int) $user->id,
                (int) $productCase->opened_by_user_id
            );

            $assertSame(
                'valid_creation',
                'status forced to draft',
                ProductCase::STATUS_DRAFT,
                $productCase->status
            );

            $assertSame(
                'valid_creation',
                'title normalized',
                'Monitor che non si accende',
                $productCase->title
            );

            $assertSame(
                'valid_creation',
                'original description derived',
                'Il monitor non si accende dopo il collegamento.',
                $productCase->original_description
            );

            $assertSame(
                'valid_creation',
                'current description derived',
                'Il monitor non si accende dopo il collegamento.',
                $productCase->description
            );

            $assertSame(
                'valid_creation',
                'occurrence date persisted',
                today()->toDateString(),
                $productCase->occurred_on?->toDateString()
            );

            $assertSame(
                'valid_creation',
                'usability persisted',
                ProductCase::USABILITY_UNUSABLE,
                $productCase->usability_status
            );

            $assertSame(
                'valid_creation',
                'false accidental damage preserved',
                false,
                $productCase->accidental_damage_declared
            );

            $assertSame(
                'valid_creation',
                'empty accidental notes normalized',
                null,
                $productCase->accidental_damage_notes
            );

            $assertSame(
                'valid_creation',
                'opened timestamp present',
                true,
                $productCase->opened_at !== null
            );

            $assertSame(
                'valid_creation',
                'outcome starts empty',
                null,
                $productCase->outcome
            );

            $assertSame(
                'valid_creation',
                'later workflow timestamps empty',
                true,
                $productCase->contacted_at === null
                    && $productCase->resolved_at === null
                    && $productCase->closed_at === null
                    && $productCase->cancelled_at === null
            );

            /*
             * La descrizione corrente può essere aggiornata con fill().
             * Quella originale non è mass assignable e resta invariata.
             */
            $productCase->fill([
                'description' =>
                    'Descrizione corrente aggiornata.',
                'original_description' =>
                    'Tentativo di sovrascrittura.',
            ])->save();

            $productCase->refresh();

            $assertSame(
                'original_description',
                'original description preserved',
                'Il monitor non si accende dopo il collegamento.',
                $productCase->original_description
            );

            $assertSame(
                'original_description',
                'current description updated',
                'Descrizione corrente aggiornata.',
                $productCase->description
            );

            $caseCountAfterValidCreation =
                ProductCase::query()->count();

            /*
             * Titolo vuoto: la validazione deve bloccare la creazione.
             */
            $blankTitleRejected = false;

            try {
                $creator->create(
                    product: $product,
                    openedBy: $user,
                    attributes: [
                        'title' => '   ',
                        'description' =>
                            'Descrizione valida.',
                    ],
                );
            } catch (ValidationException $exception) {
                $blankTitleRejected =
                    array_key_exists(
                        'title',
                        $exception->errors()
                    );
            }

            $assertSame(
                'validation',
                'blank title rejected',
                true,
                $blankTitleRejected
            );

            $assertSame(
                'validation',
                'blank title creates no case',
                $caseCountAfterValidCreation,
                ProductCase::query()->count()
            );

            /*
             * Stato di utilizzabilità fuori vocabolario.
             */
            $invalidUsabilityRejected = false;

            try {
                $creator->create(
                    product: $product,
                    openedBy: $user,
                    attributes: [
                        'title' =>
                            'Pratica con stato non valido',
                        'description' =>
                            'Descrizione valida.',
                        'usability_status' =>
                            'completely_broken',
                    ],
                );
            } catch (ValidationException $exception) {
                $invalidUsabilityRejected =
                    array_key_exists(
                        'usability_status',
                        $exception->errors()
                    );
            }

            $assertSame(
                'validation',
                'invalid usability rejected',
                true,
                $invalidUsabilityRejected
            );

            $assertSame(
                'validation',
                'invalid usability creates no case',
                $caseCountAfterValidCreation,
                ProductCase::query()->count()
            );

            /*
             * La data del problema non può essere futura.
             */
            $futureDateRejected = false;

            try {
                $creator->create(
                    product: $product,
                    openedBy: $user,
                    attributes: [
                        'title' =>
                            'Pratica con data futura',
                        'description' =>
                            'Descrizione valida.',
                        'occurred_on' =>
                            today()
                                ->addDay()
                                ->toDateString(),
                    ],
                );
            } catch (ValidationException $exception) {
                $futureDateRejected =
                    array_key_exists(
                        'occurred_on',
                        $exception->errors()
                    );
            }

            $assertSame(
                'validation',
                'future occurrence date rejected',
                true,
                $futureDateRejected
            );

            $assertSame(
                'validation',
                'future date creates no case',
                $caseCountAfterValidCreation,
                ProductCase::query()->count()
            );

            /*
             * Creiamo temporaneamente un altro workspace posseduto dallo
             * stesso utente e lo rendiamo corrente.
             *
             * L'utente continua ad appartenere al team originale, ma non deve
             * poter creare una pratica mentre è attivo un team differente.
             */
            $otherTeamId = DB::table('teams')->insertGetId([
                'user_id' => $user->id,
                'name' =>
                    'Product Case Test '
                    . Str::uuid(),
                'personal_team' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            User::query()
                ->whereKey($user->id)
                ->update([
                    'current_team_id' => $otherTeamId,
                ]);

            $user->refresh();

            /*
             * Simula il middleware dopo il cambio di workspace.
             */
            $permissionRegistrar->setPermissionsTeamId(
                $otherTeamId
            );

            $user->unsetRelation('roles');
            $user->unsetRelation('permissions');

            $crossTeamGate = Gate::forUser($user);

            $assertSame(
                'authorization',
                'other team cannot view case list',
                false,
                $crossTeamGate->allows(
                    'viewAny',
                    ProductCase::class
                )
            );

            $assertSame(
                'authorization',
                'other team cannot create for product',
                false,
                $crossTeamGate->allows(
                    'create',
                    [
                        ProductCase::class,
                        $product,
                    ]
                )
            );

            $assertSame(
                'authorization',
                'other team cannot view case',
                false,
                $crossTeamGate->allows(
                    'view',
                    $productCase
                )
            );

            $assertSame(
                'authorization',
                'other team cannot update case',
                false,
                $crossTeamGate->allows(
                    'update',
                    $productCase
                )
            );

            $assertSame(
                'authorization',
                'other team cannot close case',
                false,
                $crossTeamGate->allows(
                    'close',
                    $productCase
                )
            );

            $assertSame(
                'authorization',
                'other team cannot delete case',
                false,
                $crossTeamGate->allows(
                    'delete',
                    $productCase
                )
            );

            $crossTeamExceptionMessage = null;

            try {
                $creator->create(
                    product: $product,
                    openedBy: $user,
                    attributes: [
                        'title' =>
                            'Tentativo da altro workspace',
                        'description' =>
                            'Questa pratica non deve essere creata.',
                    ],
                );
            } catch (RuntimeException $exception) {
                $crossTeamExceptionMessage =
                    $exception->getMessage();
            }

            $assertSame(
                'team_isolation',
                'cross-team creation rejected',
                'L’utente non può aprire una pratica per il team del prodotto.',
                $crossTeamExceptionMessage
            );

            $assertSame(
                'team_isolation',
                'cross-team attempt creates no case',
                $caseCountAfterValidCreation,
                ProductCase::query()->count()
            );
        } catch (Throwable $exception) {
            $rows[] = [
                'runtime',
                'workflow test completed',
                'FAIL',
            ];

            $failures[] = [
                'scenario' => 'runtime',
                'assertion' => 'workflow test completed',
                'expected' => 'no exception',
                'actual' =>
                    $exception::class
                    . ': '
                    . $exception->getMessage(),
            ];
        } finally {
            DB::rollBack();

            $permissionRegistrar->setPermissionsTeamId(
                null
            );

            $permissionRegistrar
                ->forgetCachedPermissions();
        }

        /*
         * Il comando non deve lasciare pratiche o team temporanei.
         */
        $assertSame(
            'rollback',
            'case count restored',
            $casesBefore,
            ProductCase::query()->count()
        );

        if ($createdCaseId !== null) {
            $assertSame(
                'rollback',
                'created case removed',
                false,
                ProductCase::query()
                    ->whereKey($createdCaseId)
                    ->exists()
            );
        }

        $assertSame(
            'rollback',
            'team count restored',
            $teamsBefore,
            DB::table('teams')->count()
        );

        $this->table(
            [
                'Scenario',
                'Assertion',
                'Status',
            ],
            $rows
        );

        if ($failures !== []) {
            foreach ($failures as $failure) {
                $this->error(
                    $failure['scenario']
                    . ' / '
                    . $failure['assertion']
                );

                $this->line(
                    'Expected: '
                    . var_export(
                        $failure['expected'],
                        true
                    )
                );

                $this->line(
                    'Actual: '
                    . var_export(
                        $failure['actual'],
                        true
                    )
                );
            }

            return self::FAILURE;
        }

        $this->info(
            'Product case workflow checks passed.'
        );

        return self::SUCCESS;
    }
}
