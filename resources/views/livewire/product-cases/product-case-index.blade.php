<div class="py-8">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-wide text-slate-500">
                    Gestione pratiche
                </p>

                <h1 class="mt-2 text-3xl font-bold tracking-tight text-slate-950">
                    @if ($productFilterName)
                        Pratiche per {{ $productFilterName }}
                    @else
                        Pratiche prodotto
                    @endif
                </h1>

                <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">
                    @if ($productFilterName)
                        Consulta soltanto le pratiche associate a questo prodotto.
                    @else
                        Consulta le pratiche aperte, concluse o annullate del workspace attivo.
                    @endif
                </p>
            </div>

            <div class="flex flex-wrap gap-3">
                @if ($productId)
                    <a
                        data-testid="product-case-product-link"
                        href="{{ route('products.show', $productId) }}"
                        class="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
                    >
                        Torna al prodotto
                    </a>

                    <a
                        data-testid="clear-product-case-product-filter"
                        href="{{ route('product-cases.index', ['scope' => $scope]) }}"
                        class="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
                    >
                        Mostra tutte le pratiche
                    </a>
                @else
                    <a
                        href="{{ route('products.index') }}"
                        class="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
                    >
                        Vai ai prodotti
                    </a>
                @endif
            </div>
        </div>

        <section class="mt-8 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div class="flex flex-wrap gap-2">
                    @foreach (
                        [
                            'open' => 'Aperte',
                            'closed' => 'Concluse',
                            'cancelled' => 'Annullate',
                            'all' => 'Tutte',
                        ] as $scopeValue => $scopeLabel
                    )
                        <button
                            type="button"
                            wire:click="$set('scope', '{{ $scopeValue }}')"
                            wire:loading.attr="disabled"
                            wire:target="scope"
                            class="rounded-full px-3 py-1.5 text-sm font-semibold ring-1 ring-inset transition {{ $scope === $scopeValue ? 'bg-slate-900 text-white ring-slate-900' : 'bg-white text-slate-700 ring-slate-300 hover:bg-slate-50' }}"
                        >
                            {{ $scopeLabel }}
                            <span class="ms-1 opacity-75">
                                {{ $counts[$scopeValue] ?? 0 }}
                            </span>
                        </button>
                    @endforeach
                </div>

                <div class="w-full lg:max-w-sm">
                    <label
                        for="product-case-search"
                        class="sr-only"
                    >
                        Cerca pratica
                    </label>

                    <input
                        id="product-case-search"
                        type="search"
                        wire:model.live.debounce.300ms="search"
                        placeholder="Cerca per pratica, prodotto o modello"
                        class="block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-slate-500 focus:ring-slate-500"
                    >
                </div>
            </div>
        </section>

        <section
            data-testid="product-case-index"
            class="mt-6"
        >
            @if ($productCases->count() > 0)
                <div class="space-y-4">
                    @foreach ($productCases as $productCase)
                        @php
                            $outcomeLabel = $presenter->outcomeLabel(
                                $productCase->outcome
                            );
                        @endphp

                        <article
                            data-testid="product-case-index-item-{{ $productCase->id }}"
                            class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm"
                        >
                            <div class="flex flex-col gap-5 lg:flex-row lg:items-center lg:justify-between">
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h2 class="truncate text-lg font-bold text-slate-950">
                                            {{ $productCase->title }}
                                        </h2>

                                        <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset {{ $presenter->statusBadgeClasses($productCase->status) }}">
                                            {{ $presenter->statusLabel($productCase->status) }}
                                        </span>

                                        @if ($outcomeLabel)
                                            <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-700 ring-1 ring-inset ring-slate-500/20">
                                                {{ $outcomeLabel }}
                                            </span>
                                        @endif
                                    </div>

                                    <p class="mt-2 text-sm font-medium text-slate-700">
                                        {{ $productCase->product?->name ?? 'Prodotto non disponibile' }}
                                    </p>

                                    <div class="mt-2 flex flex-wrap gap-x-5 gap-y-1 text-xs text-slate-500">
                                        @if ($productCase->product?->model)
                                            <span>
                                                Modello: {{ $productCase->product->model }}
                                            </span>
                                        @endif

                                        <span>
                                            Aperta il {{ $productCase->opened_at?->format('d/m/Y H:i') ?? '—' }}
                                        </span>

                                        <span>
                                            Aggiornata {{ $productCase->updated_at?->diffForHumans() ?? '—' }}
                                        </span>

                                        @if ($productCase->openedBy)
                                            <span>
                                                Da {{ $productCase->openedBy->name }}
                                            </span>
                                        @endif
                                    </div>
                                </div>

                                <a
                                    href="{{ route('product-cases.show', $productCase) }}"
                                    class="inline-flex shrink-0 items-center justify-center rounded-xl bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-700"
                                >
                                    {{ $presenter->actionLabel($productCase->status) }}
                                </a>
                            </div>
                        </article>
                    @endforeach
                </div>

                <div class="mt-6">
                    {{ $productCases->links() }}
                </div>
            @else
                <div class="rounded-3xl border border-dashed border-slate-300 bg-white p-8 text-center">
                    <h2 class="text-lg font-bold text-slate-950">
                        Nessuna pratica trovata
                    </h2>

                    <p class="mx-auto mt-2 max-w-xl text-sm leading-6 text-slate-600">
                        Modifica il filtro o la ricerca. Le nuove pratiche vengono aperte dalla pagina del prodotto interessato.
                    </p>
                </div>
            @endif
        </section>
    </div>
</div>
