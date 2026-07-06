<div class="h-full">
    <section
        data-testid="dashboard-completion-center"
        class="h-full rounded-3xl border border-slate-200 bg-white p-6 shadow-sm"
    >
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-wide text-slate-500">
                    Cosa richiede attenzione
                </p>

                <h2 class="mt-2 text-xl font-bold text-slate-950">
                    Da completare
                </h2>

                <p class="mt-1 max-w-3xl text-sm leading-6 text-slate-600">
                    Revisioni, coperture stimate e dati mancanti che possono rendere l’archivio più affidabile.
                </p>
            </div>

            <span class="inline-flex w-fit items-center rounded-full bg-slate-100 px-3 py-1 text-sm font-semibold text-slate-700 ring-1 ring-inset ring-slate-500/20">
                {{ $completionTasksCount }} attività
            </span>
        </div>

        <div class="mt-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
            <a
                href="{{ route('reviews.index', ['filter' => 'pending']) }}"
                class="rounded-2xl bg-orange-50 p-4 ring-1 ring-inset ring-orange-200 transition hover:bg-orange-100"
            >
                <p class="text-xs font-semibold uppercase tracking-wide text-orange-700">
                    Candidati
                </p>

                <p class="mt-2 text-2xl font-bold text-slate-950">
                    {{ $pendingCandidatesCount }}
                </p>

                <p class="mt-1 text-xs text-orange-800">
                    Da revisionare
                </p>
            </a>

            <a
                href="{{ route('warranties.index') }}"
                class="rounded-2xl bg-yellow-50 p-4 ring-1 ring-inset ring-yellow-200 transition hover:bg-yellow-100"
            >
                <p class="text-xs font-semibold uppercase tracking-wide text-yellow-700">
                    Coperture
                </p>

                <p class="mt-2 text-2xl font-bold text-slate-950">
                    {{ $estimatedCoveragesCount }}
                </p>

                <p class="mt-1 text-xs text-yellow-800">
                    Stimate da confermare
                </p>
            </a>

            <a
                href="{{ route('products.index') }}"
                class="rounded-2xl bg-slate-50 p-4 ring-1 ring-inset ring-slate-200 transition hover:bg-slate-100"
            >
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                    Data acquisto
                </p>

                <p class="mt-2 text-2xl font-bold text-slate-950">
                    {{ $missingPurchaseDatesCount }}
                </p>

                <p class="mt-1 text-xs text-slate-600">
                    Prodotti incompleti
                </p>
            </a>

            <a
                href="{{ route('products.index') }}"
                class="rounded-2xl bg-slate-50 p-4 ring-1 ring-inset ring-slate-200 transition hover:bg-slate-100"
            >
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                    Documento sorgente
                </p>

                <p class="mt-2 text-2xl font-bold text-slate-950">
                    {{ $missingSourceDocumentsCount }}
                </p>

                <p class="mt-1 text-xs text-slate-600">
                    Collegamento mancante
                </p>
            </a>
        </div>

        @if ($completionItems !== [])
            <div class="mt-6">
                <div class="flex items-center justify-between gap-3">
                    <h3 class="text-sm font-semibold text-slate-950">
                        Priorità
                    </h3>

                    <span class="text-xs text-slate-500">
                        Mostrate fino a 6 attività
                    </span>
                </div>

                <div class="mt-3 grid grid-cols-1 gap-4">
                    @foreach ($completionItems as $item)
                        <a
                            data-testid="dashboard-completion-item-{{ $item['type'] }}-{{ $loop->index }}"
                            href="{{ $item['url'] }}"
                            class="block rounded-2xl border border-slate-200 bg-slate-50 p-4 transition hover:border-slate-300 hover:bg-slate-100"
                        >
                            <div class="flex items-start justify-between gap-4">
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-semibold text-slate-950">
                                        {{ $item['title'] }}
                                    </p>

                                    <p class="mt-1 line-clamp-2 text-xs leading-5 text-slate-600">
                                        {{ $item['subtitle'] }}
                                    </p>
                                </div>

                                <span class="shrink-0 rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $item['badge_classes'] }}">
                                    {{ $item['badge_label'] }}
                                </span>
                            </div>

                            <div class="mt-4 flex items-center justify-between gap-3">
                                <span class="text-sm font-semibold text-slate-800">
                                    {{ $item['action_label'] }}
                                </span>

                                <span class="text-xs text-slate-500">
                                    Aggiornata {{ $item['updated_at_label'] }}
                                </span>
                            </div>
                        </a>
                    @endforeach
                </div>
            </div>
        @else
            <div class="mt-6 rounded-2xl border border-dashed border-slate-300 bg-slate-50 p-5">
                <p class="text-sm font-semibold text-slate-900">
                    Nessuna attività da completare
                </p>

                <p class="mt-1 text-sm leading-6 text-slate-600">
                    Le revisioni e i dati mancanti compariranno qui quando richiederanno un intervento.
                </p>
            </div>
        @endif
    </section>
</div>
