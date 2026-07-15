<div class="py-6">
    <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
        @php
            $plan = data_get($entitlements, 'plan');
            $resources = data_get($usageSnapshot, 'resources', []);
            $features = data_get($entitlements, 'features', []);
            $currentPlanCode = data_get($plan, 'code');
            $enforcementMode = data_get($usageSnapshot, 'enforcement_mode', 'observe');
            $averageResolutionDays = data_get($valueMetrics, 'average_resolution_days');
        @endphp

        <section
            data-testid="plan-overview"
            class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm"
        >
            <div class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <p class="text-sm font-semibold uppercase tracking-wide text-slate-500">
                        Piano e utilizzo
                    </p>

                    <h1 class="mt-2 text-2xl font-bold text-slate-950 sm:text-3xl">
                        {{ data_get($plan, 'name', 'Piano non configurato') }}
                    </h1>

                    <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">
                        {{ data_get($plan, 'description', 'Il workspace non ha ancora un piano configurato.') }}
                    </p>

                    <p class="mt-3 text-sm text-slate-500">
                        Workspace:
                        <span class="font-semibold text-slate-800">
                            {{ $workspaceName }}
                        </span>
                    </p>
                </div>

                <div class="rounded-2xl bg-slate-50 px-4 py-3 ring-1 ring-inset ring-slate-200">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                        Applicazione limiti
                    </p>

                    <p class="mt-1 text-sm font-semibold text-slate-900">
                        {{ $enforcementMode === 'enforce' ? 'Attiva' : 'Monitoraggio' }}
                    </p>

                    <p class="mt-1 max-w-xs text-xs leading-5 text-slate-500">
                        @if ($enforcementMode === 'enforce')
                            Le nuove operazioni vengono bloccate quando esauriscono un limite.
                        @else
                            I superamenti sono visibili ma non bloccano ancora il flusso.
                        @endif
                    </p>
                </div>
            </div>
        </section>

        <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
            <div>
                <p class="text-sm font-semibold uppercase tracking-wide text-slate-500">
                    Capacità del workspace
                </p>

                <h2 class="mt-2 text-xl font-bold text-slate-950">
                    Utilizzo e limiti
                </h2>
            </div>

            <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($resources as $resource)
                    @php
                        $status = $resource['status'] ?? 'available';
                        $statusClasses = match ($status) {
                            'exceeded' => 'bg-red-50 text-red-700 ring-red-600/20',
                            'exhausted' => 'bg-orange-50 text-orange-700 ring-orange-600/20',
                            'warning' => 'bg-yellow-50 text-yellow-800 ring-yellow-600/20',
                            'unlimited' => 'bg-green-50 text-green-700 ring-green-600/20',
                            'unconfigured' => 'bg-slate-100 text-slate-600 ring-slate-500/20',
                            default => 'bg-blue-50 text-blue-700 ring-blue-600/20',
                        };
                        $unit = $resource['unit'] ?? null;
                        $usedLabel = number_format((int) ($resource['used'] ?? 0), 0, ',', '.');
                        $limitLabel = ($resource['is_unlimited'] ?? false)
                            ? 'Illimitato'
                            : (is_int($resource['limit'] ?? null)
                                ? number_format((int) $resource['limit'], 0, ',', '.')
                                : 'Non configurato');
                    @endphp

                    <article class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="text-sm font-semibold text-slate-950">
                                    {{ $resource['label'] ?? $resource['key'] }}
                                </p>

                                <p class="mt-2 text-2xl font-bold text-slate-950">
                                    {{ $usedLabel }}
                                    @if ($unit)
                                        <span class="text-sm font-semibold text-slate-500">
                                            {{ $unit }}
                                        </span>
                                    @endif
                                </p>

                                <p class="mt-1 text-xs text-slate-500">
                                    Limite: {{ $limitLabel }}
                                    @if ($unit && ! ($resource['is_unlimited'] ?? false))
                                        {{ $unit }}
                                    @endif
                                </p>
                            </div>

                            <span class="rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $statusClasses }}">
                                {{ match ($status) {
                                    'exceeded' => 'Superato',
                                    'exhausted' => 'Esaurito',
                                    'warning' => 'Quasi esaurito',
                                    'unlimited' => 'Illimitato',
                                    'unconfigured' => 'Da configurare',
                                    default => 'Disponibile',
                                } }}
                            </span>
                        </div>

                        @if (is_int($resource['percentage'] ?? null))
                            <div class="mt-4 h-2 overflow-hidden rounded-full bg-slate-200">
                                <div
                                    class="h-full rounded-full bg-slate-700"
                                    style="width: {{ min(100, (int) $resource['percentage']) }}%"
                                ></div>
                            </div>
                        @endif

                        @if (! empty($resource['description']))
                            <p class="mt-3 text-xs leading-5 text-slate-500">
                                {{ $resource['description'] }}
                            </p>
                        @endif
                    </article>
                @endforeach
            </div>
        </section>

        <section class="grid grid-cols-1 gap-6 xl:grid-cols-2">
            <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                <p class="text-sm font-semibold uppercase tracking-wide text-slate-500">
                    Valore operativo
                </p>

                <h2 class="mt-2 text-xl font-bold text-slate-950">
                    Risultati misurabili
                </h2>

                <div class="mt-6 grid grid-cols-2 gap-3">
                    <div class="rounded-2xl bg-slate-50 p-4">
                        <p class="text-xs text-slate-500">Pratiche avviate</p>
                        <p class="mt-2 text-2xl font-bold text-slate-950">
                            {{ data_get($valueMetrics, 'practices_started', 0) }}
                        </p>
                    </div>

                    <div class="rounded-2xl bg-slate-50 p-4">
                        <p class="text-xs text-slate-500">Pratiche concluse</p>
                        <p class="mt-2 text-2xl font-bold text-slate-950">
                            {{ data_get($valueMetrics, 'practices_concluded', 0) }}
                        </p>
                    </div>

                    <div class="rounded-2xl bg-green-50 p-4 ring-1 ring-inset ring-green-200">
                        <p class="text-xs text-green-700">
                            Riparazioni, sostituzioni o rimborsi
                        </p>
                        <p class="mt-2 text-2xl font-bold text-slate-950">
                            {{ data_get($valueMetrics, 'successful_outcomes', 0) }}
                        </p>
                    </div>

                    <div class="rounded-2xl bg-slate-50 p-4">
                        <p class="text-xs text-slate-500">
                            Tempo medio di risoluzione
                        </p>
                        <p class="mt-2 text-2xl font-bold text-slate-950">
                            {{ $averageResolutionDays !== null
                                ? $averageResolutionDays . ' gg'
                                : '—' }}
                        </p>
                    </div>
                </div>

                <p class="mt-4 text-xs leading-5 text-slate-500">
                    Queste metriche misurano il valore del flusso operativo e non vengono usate per prendere decisioni automatiche sulle pratiche.
                </p>
            </div>

            <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                <p class="text-sm font-semibold uppercase tracking-wide text-slate-500">
                    Funzionalità
                </p>

                <h2 class="mt-2 text-xl font-bold text-slate-950">
                    Cosa include il piano
                </h2>

                <div class="mt-6 space-y-3">
                    @foreach ($features as $feature)
                        <div class="flex items-start justify-between gap-4 rounded-2xl bg-slate-50 p-4">
                            <p class="text-sm font-semibold text-slate-900">
                                {{ $feature['description']
                                    ?? str_replace('_', ' ', $feature['key']) }}
                            </p>

                            <span class="rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ ($feature['enabled'] ?? false)
                                ? 'bg-green-50 text-green-700 ring-green-600/20'
                                : 'bg-slate-100 text-slate-500 ring-slate-500/20' }}">
                                {{ ($feature['enabled'] ?? false)
                                    ? 'Inclusa'
                                    : 'Non inclusa' }}
                            </span>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
            <p class="text-sm font-semibold uppercase tracking-wide text-slate-500">
                Catalogo
            </p>

            <h2 class="mt-2 text-xl font-bold text-slate-950">
                Piani previsti
            </h2>

            <p class="mt-1 text-sm leading-6 text-slate-600">
                Il catalogo è pronto per la validazione del prodotto. Checkout e pagamenti non sono ancora attivi.
            </p>

            <div class="mt-6 grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
                @foreach ($catalog as $catalogPlan)
                    <article class="rounded-2xl border p-5 {{ $catalogPlan['code'] === $currentPlanCode
                        ? 'border-slate-900 bg-slate-50'
                        : 'border-slate-200 bg-white' }}">
                        <div class="flex items-start justify-between gap-3">
                            <h3 class="text-lg font-bold text-slate-950">
                                {{ $catalogPlan['name'] }}
                            </h3>

                            @if ($catalogPlan['code'] === $currentPlanCode)
                                <span class="rounded-full bg-slate-900 px-2 py-0.5 text-xs font-semibold text-white">
                                    Attuale
                                </span>
                            @endif
                        </div>

                        <p class="mt-2 text-sm font-semibold text-slate-700">
                            {{ $catalogPlan['price_label'] }}
                        </p>

                        <p class="mt-3 text-xs leading-5 text-slate-500">
                            {{ $catalogPlan['description'] }}
                        </p>
                    </article>
                @endforeach
            </div>
        </section>

        <section
            data-testid="one-time-offers"
            class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm"
        >
            <p class="text-sm font-semibold uppercase tracking-wide text-slate-500">
                Acquisti singoli
            </p>

            <h2 class="mt-2 text-xl font-bold text-slate-950">
                Servizi operativi previsti
            </h2>

            <p class="mt-1 text-sm leading-6 text-slate-600">
                Sono offerte indipendenti dall’abbonamento. Prezzi, checkout e concessione automatica non sono ancora attivi.
            </p>

            <div class="mt-6 grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
                @foreach ($oneTimeOffers as $offer)
                    <article class="rounded-2xl border border-slate-200 bg-slate-50 p-5">
                        <h3 class="text-base font-bold text-slate-950">
                            {{ $offer['name'] ?? $offer['code'] }}
                        </h3>

                        <p class="mt-2 text-sm font-semibold text-slate-700">
                            {{ is_int($offer['price_cents'] ?? null)
                                ? number_format(
                                    $offer['price_cents'] / 100,
                                    2,
                                    ',',
                                    '.'
                                ) . ' ' . ($offer['currency_code'] ?? 'EUR')
                                : 'Prezzo da definire' }}
                        </p>

                        <p class="mt-3 text-xs leading-5 text-slate-500">
                            {{ $offer['description'] ?? '' }}
                        </p>
                    </article>
                @endforeach
            </div>
        </section>
    </div>
</div>
