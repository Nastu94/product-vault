<?php

namespace App\Console\Commands\ProductVault;

use App\Models\Plan;
use App\Models\Team;
use App\Models\User;
use App\Services\Monetization\WorkspacePlanAssignmentService;
use Illuminate\Console\Command;
use InvalidArgumentException;

final class AssignWorkspacePlanCommand extends Command
{
    protected $signature =
        'product-vault:assign-workspace-plan
        {team : ID del workspace}
        {plan : Codice del piano target}
        {--apply : Applica realmente l’assegnazione}
        {--force : Consenti l’assegnazione anche se l’uso supera il piano target}
        {--actor= : ID utente da registrare nell’audit log}';

    protected $description =
        'Anteprima o applica in modo controllato un piano a un workspace.';

    public function handle(
        WorkspacePlanAssignmentService $assignmentService
    ): int {
        $team = Team::query()->find((int) $this->argument('team'));

        if ($team === null) {
            $this->error('Workspace non trovato.');

            return self::FAILURE;
        }

        $plan = Plan::query()
            ->where('code', (string) $this->argument('plan'))
            ->first();

        if ($plan === null) {
            $this->error('Piano non trovato.');

            return self::FAILURE;
        }

        if (! $plan->is_active) {
            $this->error('Il piano selezionato non è attivo.');

            return self::FAILURE;
        }

        $actorId = $this->option('actor') !== null
            ? (int) $this->option('actor')
            : null;

        if (
            $actorId !== null
            && ! User::query()->whereKey($actorId)->exists()
        ) {
            $this->error('L’utente indicato come actor non esiste.');

            return self::FAILURE;
        }

        $preview = $assignmentService->preview($team, $plan);

        $this->info(
            'Workspace: ' . $team->name . ' (#' . $team->id . ')'
        );
        $this->line(
            'Piano corrente: '
            . (data_get($preview, 'current_plan.code') ?? 'non configurato')
        );
        $this->line('Piano target: ' . $plan->code);

        $rows = collect(data_get($preview, 'resources', []))
            ->map(fn (array $resource): array => [
                $resource['label'],
                $resource['used'],
                $resource['limit'] === null
                    ? 'Illimitato'
                    : $resource['limit'],
                $resource['status'],
            ])
            ->all();

        $this->table(
            ['Risorsa', 'Uso', 'Limite target', 'Stato'],
            $rows
        );

        if (! $this->option('apply')) {
            $this->warn(
                'Solo anteprima: nessuna modifica applicata. Usa --apply per confermare.'
            );

            return self::SUCCESS;
        }

        try {
            $assignmentService->assign(
                team: $team,
                targetPlan: $plan,
                actorUserId: $actorId,
                force: (bool) $this->option('force'),
            );
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(
            'Piano ' . $plan->code . ' assegnato al workspace.'
        );

        return self::SUCCESS;
    }
}
