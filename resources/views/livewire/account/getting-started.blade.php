<div class="py-6">
    <div class="mx-auto max-w-5xl space-y-6 px-4 sm:px-6 lg:px-8">
        <section
            data-testid="getting-started"
            class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8"
        >
            <div class="flex flex-col gap-5 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <p class="text-sm font-semibold uppercase tracking-wide text-slate-500">
                        Prima configurazione
                    </p>

                    <h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-950 sm:text-3xl">
                        Inizia con {{ $workspaceName !== '' ? $workspaceName : 'il tuo workspace' }}
                    </h1>

                    <p class="mt-3 max-w-3xl text-sm leading-6 text-slate-600">
                        Completa il flusso essenziale senza dare per certi i dati estratti.
                        Documenti, prodotti, coperture e pratiche restano separati e revisionabili.
                    </p>
                </div>

                <div class="shrink-0 rounded-2xl bg-slate-100 px-4 py-3 text-center ring-1 ring-inset ring-slate-200">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                        Avanzamento
                    </p>
                    <p class="mt-1 text-2xl font-bold text-slate-950">
                        {{ $completedSteps }} / {{ $totalSteps }}
                    </p>
                </div>
            </div>

            @if ($totalSteps > 0)
                @php
                    $progress = min(100, (int) round(($completedSteps / $totalSteps) * 100));
                @endphp

                <div class="mt-6 h-2 overflow-hidden rounded-full bg-slate-200">
                    <div
                        class="h-full rounded-full bg-slate-900 transition-all"
                        style="width: {{ $progress }}%"
                    ></div>
                </div>
            @endif
        </section>

        <section class="grid grid-cols-1 gap-4 md:grid-cols-2">
            @foreach ($steps as $step)
                <article
                    data-testid="getting-started-step-{{ $step['key'] }}"
                    class="rounded-2xl border p-5 shadow-sm {{ $step['completed'] ? 'border-green-200 bg-green-50/60' : 'border-slate-200 bg-white' }}"
                >
                    <div class="flex items-start gap-4">
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl {{ $step['completed'] ? 'bg-green-600 text-white' : 'bg-slate-100 text-slate-500' }}">
                            @if ($step['completed'])
                                <span class="text-lg font-bold">✓</span>
                            @else
                                <span class="text-sm font-bold">{{ $loop->iteration }}</span>
                            @endif
                        </div>

                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <h2 class="text-base font-semibold text-slate-950">
                                    {{ $step['title'] }}
                                </h2>

                                <span class="rounded-full px-2 py-0.5 text-xs font-semibold ring-1 ring-inset {{ $step['completed'] ? 'bg-green-100 text-green-700 ring-green-600/20' : 'bg-slate-100 text-slate-600 ring-slate-500/20' }}">
                                    {{ $step['completed'] ? 'Completato' : 'Da fare' }}
                                </span>
                            </div>

                            <p class="mt-2 text-sm leading-6 text-slate-600">
                                {{ $step['description'] }}
                            </p>

                            <a
                                href="{{ $step['href'] }}"
                                class="mt-4 inline-flex text-sm font-semibold text-slate-800 transition hover:text-slate-950"
                            >
                                {{ $step['action_label'] }}
                            </a>
                        </div>
                    </div>
                </article>
            @endforeach
        </section>

        <section class="rounded-3xl border border-amber-200 bg-amber-50 p-6">
            <h2 class="text-base font-semibold text-amber-950">
                Prima di affidarti a una scadenza o a una pratica
            </h2>
            <p class="mt-2 text-sm leading-6 text-amber-900">
                Controlla sempre documento originale, data di acquisto, soggetto destinatario
                e condizioni applicabili. Product Vault organizza e segnala, ma non sostituisce
                la verifica della fonte.
            </p>

            <div class="mt-4 flex flex-wrap gap-4 text-sm font-semibold">
                <a href="{{ route('legal.document-processing') }}" class="text-amber-900 underline underline-offset-4">
                    Come trattiamo i documenti
                </a>
                <a href="{{ route('legal.privacy') }}" class="text-amber-900 underline underline-offset-4">
                    Privacy
                </a>
                <a href="{{ route('legal.terms') }}" class="text-amber-900 underline underline-offset-4">
                    Termini essenziali
                </a>
            </div>
        </section>
    </div>
</div>
