@php
    $hasAlerts = (bool) data_get($notice, 'has_alerts', false);
    $severity = data_get($notice, 'highest_severity', 'warning');
    $mode = data_get($notice, 'enforcement_mode', 'observe');
    $items = data_get($notice, 'items', []);

    $panelClasses = match ($severity) {
        'danger' => 'border-red-200 bg-red-50',
        'critical' => 'border-orange-200 bg-orange-50',
        default => 'border-yellow-200 bg-yellow-50',
    };

    $badgeClasses = match ($severity) {
        'danger' => 'bg-red-100 text-red-700 ring-red-600/20',
        'critical' => 'bg-orange-100 text-orange-700 ring-orange-600/20',
        default => 'bg-yellow-100 text-yellow-800 ring-yellow-600/20',
    };
@endphp

@if ($hasAlerts)
    <div class="mx-auto max-w-7xl px-4 pt-5 sm:px-6 lg:px-8">
        <section
            data-testid="plan-usage-notice"
            class="rounded-2xl border p-4 shadow-sm {{ $panelClasses }}"
        >
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="text-sm font-bold text-slate-950">
                            {{ data_get($notice, 'title') }}
                        </h2>

                        <span class="rounded-full px-2 py-0.5 text-xs font-semibold ring-1 ring-inset {{ $badgeClasses }}">
                            {{ $mode === 'enforce' ? 'Limiti attivi' : 'Solo monitoraggio' }}
                        </span>
                    </div>

                    <p class="mt-2 max-w-4xl text-sm leading-6 text-slate-700">
                        {{ data_get($notice, 'message') }}
                    </p>

                    <div class="mt-3 flex flex-wrap gap-2">
                        @foreach (array_slice($items, 0, $expanded ? count($items) : 3) as $item)
                            <span class="rounded-full bg-white/80 px-3 py-1 text-xs font-medium text-slate-700 ring-1 ring-inset ring-slate-300">
                                {{ $item['message'] }}
                            </span>
                        @endforeach

                        @if (! $expanded && count($items) > 3)
                            <span class="rounded-full bg-white/60 px-3 py-1 text-xs font-medium text-slate-600 ring-1 ring-inset ring-slate-300">
                                +{{ count($items) - 3 }} altre
                            </span>
                        @endif
                    </div>
                </div>

                <a
                    href="{{ route('account.plan') }}"
                    class="inline-flex shrink-0 items-center justify-center rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-700"
                >
                    Controlla piano e utilizzo
                </a>
            </div>
        </section>
    </div>
@endif
