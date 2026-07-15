<?php

namespace App\Livewire\Account;

use App\Models\Document;
use App\Models\Product;
use App\Models\ProductCase;
use App\Models\User;
use App\Models\Warranty;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

final class GettingStarted extends Component
{
    public string $workspaceName = '';

    /**
     * @var list<array<string, mixed>>
     */
    public array $steps = [];

    public int $completedSteps = 0;

    public int $totalSteps = 0;

    public function mount(): void
    {
        $user = Auth::user();

        if (! $user instanceof User || $user->current_team_id === null) {
            return;
        }

        $team = $user->currentTeam;

        if ($team === null) {
            return;
        }

        $teamId = (int) $team->getKey();
        $this->workspaceName = (string) $team->name;

        $documentsCount = Document::query()
            ->where('team_id', $teamId)
            ->count();
        $productsCount = Product::query()
            ->where('team_id', $teamId)
            ->count();
        $casesCount = ProductCase::query()
            ->where('team_id', $teamId)
            ->count();
        $warrantiesCount = Warranty::query()
            ->whereHas(
                'product',
                fn (Builder $query): Builder => $query
                    ->where('team_id', $teamId)
            )
            ->count();

        $this->steps = [
            [
                'key' => 'workspace',
                'title' => 'Workspace disponibile',
                'description' => 'Controlla nome, membri e accessi del workspace attivo.',
                'completed' => true,
                'href' => route('teams.show', $team),
                'action_label' => 'Impostazioni workspace',
            ],
            [
                'key' => 'plan',
                'title' => 'Piano e capacità verificati',
                'description' => 'Consulta piano Free, limiti monitorati e utilizzo corrente.',
                'completed' => $team->plan_id !== null,
                'href' => route('account.plan'),
                'action_label' => 'Piano e utilizzo',
            ],
            [
                'key' => 'document',
                'title' => 'Primo documento caricato',
                'description' => 'Carica una prova d’acquisto, un manuale o un certificato pertinente.',
                'completed' => $documentsCount > 0,
                'href' => $documentsCount > 0
                    ? route('documents.index')
                    : route('documents.upload'),
                'action_label' => $documentsCount > 0
                    ? 'Apri documenti'
                    : 'Carica documento',
            ],
            [
                'key' => 'product',
                'title' => 'Prima scheda prodotto confermata',
                'description' => 'Rivedi i candidati prima di creare una scheda affidabile.',
                'completed' => $productsCount > 0,
                'href' => $productsCount > 0
                    ? route('products.index')
                    : route('reviews.index'),
                'action_label' => $productsCount > 0
                    ? 'Apri prodotti'
                    : 'Vai alle revisioni',
            ],
            [
                'key' => 'coverage',
                'title' => 'Copertura controllata',
                'description' => 'Verifica date e origine delle coperture: le stime non sono conferme legali.',
                'completed' => $warrantiesCount > 0,
                'href' => route('warranties.index'),
                'action_label' => 'Apri coperture',
            ],
            [
                'key' => 'case',
                'title' => 'Workflow assistenza provato',
                'description' => 'Apri una pratica soltanto quando esiste un problema reale da tracciare.',
                'completed' => $casesCount > 0,
                'href' => $casesCount > 0
                    ? route('product-cases.index')
                    : route('products.index'),
                'action_label' => $casesCount > 0
                    ? 'Apri pratiche'
                    : 'Scegli un prodotto',
            ],
        ];

        $this->completedSteps = collect($this->steps)
            ->where('completed', true)
            ->count();
        $this->totalSteps = count($this->steps);
    }

    public function render(): View
    {
        return view('livewire.account.getting-started');
    }
}
