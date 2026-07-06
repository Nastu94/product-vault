<div class="h-full">
    <section
        data-testid="dashboard-product-case-actions"
        class="h-full rounded-3xl border border-slate-200 bg-white p-6 shadow-sm"
    >
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-wide text-slate-500">
                    Cosa richiede attenzione
                </p>

                <h2 class="mt-2 text-xl font-bold text-slate-950">
                    Pratiche aperte
                </h2>

                <p class="mt-1 text-sm leading-6 text-slate-600">
                    Continua dal passaggio operativo più utile, senza cercare la pratica nel prodotto.
                </p>
            </div>

            <span class="inline-flex w-fit items-center rounded-full bg-slate-100 px-3 py-1 text-sm font-semibold text-slate-700 ring-1 ring-inset ring-slate-500/20">
                {{ $openProductCasesCount }} aperte
            </span>
        </div>

        @if ($openProductCases !== [])
            <div class="mt-6 space-y-4">
                @foreach ($openProductCases as $productCase)
                    <a
                        data-testid="dashboard-product-case-{{ $productCase['id'] }}"
                        href="{{ route('product-cases.show', $productCase['id']) }}"
                        class="block rounded-2xl border border-slate-200 bg-slate-50 p-4 transition hover:border-slate-300 hover:bg-slate-100"
                    >
                        <div class="flex items-start justify-between gap-4">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold text-slate-950">
                                    {{ $productCase['title'] }}
                                </p>

                                <p class="mt-1 truncate text-xs text-slate-500">
                                    {{ $productCase['product_name'] }}
                                </p>
                            </div>

                            <span class="shrink-0 rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $productCase['status_badge_classes'] }}">
                                {{ $productCase['status_label'] }}
                            </span>
                        </div>

                        <div class="mt-4 flex items-center justify-between gap-3">
                            <span class="text-sm font-semibold text-slate-800">
                                {{ $productCase['action_label'] }}
                            </span>

                            <span class="text-xs text-slate-500">
                                Aggiornata {{ $productCase['updated_at_label'] }}
                            </span>
                        </div>
                    </a>
                @endforeach
            </div>
        @else
            <div class="mt-6 rounded-2xl border border-dashed border-slate-300 bg-slate-50 p-5">
                <p class="text-sm font-semibold text-slate-900">
                    Nessuna pratica aperta
                </p>

                <p class="mt-1 text-sm leading-6 text-slate-600">
                    Le pratiche da completare, contattare, risolvere o chiudere compariranno qui.
                </p>
            </div>
        @endif
    </section>
</div>
