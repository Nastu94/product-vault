<div class="h-full">
    <section
        data-testid="dashboard-expiry-center"
        class="h-full rounded-3xl border border-slate-200 bg-white p-6 shadow-sm"
    >
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-wide text-slate-500">
                    Scadenze operative
                </p>

                <h2 class="mt-2 text-xl font-bold text-slate-950">
                    In scadenza
                </h2>

                <p class="mt-1 text-sm leading-6 text-slate-600">
                    Coperture attive che terminano entro i prossimi 30 giorni.
                </p>
            </div>

            <div class="flex shrink-0 flex-col items-start gap-2 sm:items-end">
                <span class="inline-flex w-fit items-center rounded-full bg-slate-100 px-3 py-1 text-sm font-semibold text-slate-700 ring-1 ring-inset ring-slate-500/20">
                    {{ $expiringCount }} in scadenza
                </span>

                <a
                    data-testid="dashboard-expiring-warranties-link"
                    href="{{ route('warranties.index', ['status' => 'expiring']) }}"
                    class="text-sm font-semibold text-slate-700 transition hover:text-slate-950"
                >
                    Vedi tutte le coperture
                </a>
            </div>
        </div>

        <div class="mt-6 grid grid-cols-2 gap-3">
            <div class="rounded-2xl bg-red-50 p-4 ring-1 ring-inset ring-red-200">
                <p class="text-xs font-semibold uppercase tracking-wide text-red-700">
                    Entro 7 giorni
                </p>

                <p class="mt-2 text-2xl font-bold text-slate-950">
                    {{ $urgentCount }}
                </p>

                <p class="mt-1 text-xs text-red-800">
                    Priorità alta
                </p>
            </div>

            <div class="rounded-2xl bg-yellow-50 p-4 ring-1 ring-inset ring-yellow-200">
                <p class="text-xs font-semibold uppercase tracking-wide text-yellow-700">
                    Tra 8 e 30 giorni
                </p>

                <p class="mt-2 text-2xl font-bold text-slate-950">
                    {{ $upcomingCount }}
                </p>

                <p class="mt-1 text-xs text-yellow-800">
                    Da monitorare
                </p>
            </div>
        </div>

        @if ($expiringItems !== [])
            <div class="mt-6 space-y-4">
                @foreach ($expiringItems as $item)
                    <a
                        data-testid="dashboard-expiry-item-{{ $item['id'] }}"
                        href="{{ $item['url'] }}"
                        class="block rounded-2xl border border-slate-200 bg-slate-50 p-4 transition hover:border-slate-300 hover:bg-slate-100"
                    >
                        <div class="flex items-start justify-between gap-4">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold text-slate-950">
                                    {{ $item['product_name'] }}
                                </p>

                                <p class="mt-1 text-xs text-slate-600">
                                    {{ $item['coverage_type_label'] }}
                                </p>

                                <p class="mt-1 text-xs text-slate-500">
                                    {{ $item['coverage_label'] }}
                                </p>
                            </div>

                            <span class="shrink-0 rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $item['badge_classes'] }}">
                                {{ $item['remaining_label'] }}
                            </span>
                        </div>

                        <p class="mt-4 text-xs font-medium text-slate-600">
                            Termine indicato: {{ $item['ends_at_label'] }}
                        </p>
                    </a>
                @endforeach
            </div>
        @else
            <div class="mt-6 rounded-2xl border border-dashed border-slate-300 bg-slate-50 p-5">
                <p class="text-sm font-semibold text-slate-900">
                    Nessuna copertura in scadenza
                </p>

                <p class="mt-1 text-sm leading-6 text-slate-600">
                    Non risultano coperture attive con termine nei prossimi 30 giorni.
                </p>
            </div>
        @endif
    </section>
</div>
