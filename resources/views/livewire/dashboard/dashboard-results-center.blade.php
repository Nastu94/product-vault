<div class="mx-auto max-w-7xl px-4 pt-6 sm:px-6 lg:px-8">
    <section
        data-testid="dashboard-product-case-results"
        class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm"
    >
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-wide text-slate-500">
                    Risultati
                </p>

                <h2 class="mt-2 text-xl font-bold text-slate-950">
                    Pratiche concluse
                </h2>

                <p class="mt-1 text-sm leading-6 text-slate-600">
                    Esiti registrati nelle pratiche chiuse del workspace attivo.
                </p>
            </div>

            <span class="inline-flex w-fit items-center rounded-full bg-slate-100 px-3 py-1 text-sm font-semibold text-slate-700 ring-1 ring-inset ring-slate-500/20">
                {{ $concludedCount }} concluse
            </span>
        </div>

        <div class="mt-6 grid grid-cols-1 gap-3 sm:grid-cols-3">
            <div class="rounded-2xl bg-slate-50 p-4 ring-1 ring-inset ring-slate-200">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                    Riparati
                </p>

                <p class="mt-2 text-2xl font-bold text-slate-950">
                    {{ $repairedCount }}
                </p>
            </div>

            <div class="rounded-2xl bg-slate-50 p-4 ring-1 ring-inset ring-slate-200">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                    Sostituiti
                </p>

                <p class="mt-2 text-2xl font-bold text-slate-950">
                    {{ $replacedCount }}
                </p>
            </div>

            <div class="rounded-2xl bg-slate-50 p-4 ring-1 ring-inset ring-slate-200">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                    Rimborsati
                </p>

                <p class="mt-2 text-2xl font-bold text-slate-950">
                    {{ $refundedCount }}
                </p>
            </div>
        </div>

        @if ($recentResults !== [])
            <div class="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-2">
                @foreach ($recentResults as $productCase)
                    <a
                        data-testid="dashboard-product-case-result-{{ $productCase['id'] }}"
                        href="{{ route('product-cases.show', $productCase['id']) }}"
                        class="rounded-2xl border border-slate-200 bg-slate-50 p-4 transition hover:border-slate-300 hover:bg-slate-100"
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

                            <span class="shrink-0 rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $productCase['outcome_badge_classes'] }}">
                                {{ $productCase['outcome_label'] }}
                            </span>
                        </div>

                        <p class="mt-4 text-xs text-slate-500">
                            Chiusa il {{ $productCase['closed_at_label'] }}
                        </p>
                    </a>
                @endforeach
            </div>
        @else
            <div class="mt-6 rounded-2xl border border-dashed border-slate-300 bg-slate-50 p-5">
                <p class="text-sm font-semibold text-slate-900">
                    Nessuna pratica conclusa
                </p>

                <p class="mt-1 text-sm leading-6 text-slate-600">
                    Riparazioni, sostituzioni e rimborsi compariranno qui dopo la chiusura della pratica.
                </p>
            </div>
        @endif
    </section>
</div>
