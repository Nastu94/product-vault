<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="text-xl font-semibold leading-tight text-gray-800">
                    Dashboard
                </h2>

                <p class="mt-1 text-sm text-gray-500">
                    Archivio personale per documenti, prodotti e garanzie.
                </p>
            </div>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">

            {{-- Riga principale compatta --}}
            <section class="grid grid-cols-1 gap-6 xl:grid-cols-3">
                {{-- Benvenuto + CTA --}}
                <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm xl:col-span-2">
                    <div class="flex flex-col gap-6 lg:flex-row lg:items-center lg:justify-between">
                        <div>
                            <p class="text-sm font-semibold uppercase tracking-wide text-slate-500">
                                Archivio prodotto
                            </p>

                            <h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-950 sm:text-3xl">
                                Ciao {{ $userName ?? 'Utente' }},
                                organizza i tuoi documenti.
                            </h1>

                            <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-600">
                                Carica scontrini, fatture, manuali o certificati. I dati estratti saranno
                                sempre revisionabili prima di creare una scheda prodotto affidabile.
                            </p>
                        </div>

                        <div class="flex shrink-0 flex-col gap-3 sm:flex-row lg:flex-col">
                            <a
                                href="{{ route('reviews.index') }}"
                                class="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-5 py-3 text-sm font-semibold text-slate-800 transition hover:bg-slate-100"
                            >
                                Revisioni
                            </a>
                        </div>
                    </div>
                </div>

                {{-- Stato archivio --}}
                <div class="rounded-3xl border border-dashed border-slate-300 bg-white p-6 shadow-sm">
                    <div class="flex items-start gap-4">
                        <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-slate-100 text-slate-700">
                            <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M7 3h7l5 5v13H7V3Z" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M14 3v6h5" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 14h6M9 17h4" />
                            </svg>
                        </div>

                        <div>
                            <h2 class="text-lg font-bold text-slate-950">
                                @if ($isArchiveEmpty)
                                    Archivio vuoto
                                @else
                                    Archivio attivo
                                @endif
                            </h2>

                            <p class="mt-1 text-xs font-medium uppercase tracking-wide text-slate-400">
                                {{ $activeWorkspaceName ?? 'Workspace personale' }}
                            </p>

                            <p class="mt-2 text-sm leading-6 text-slate-600">
                                @if ($isArchiveEmpty)
                                    Quando caricherai i primi documenti, qui compariranno attività,
                                    revisioni e schede recenti.
                                @else
                                    Il workspace contiene documenti o prodotti. Le attività utili verranno
                                    evidenziate in base a revisioni, garanzie e dati incompleti.
                                @endif
                            </p>
                        </div>
                    </div>
                </div>
            </section>

            {{-- Statistiche principali --}}
            <section class="grid grid-cols-2 gap-4 xl:grid-cols-4">
                <x-dashboard.stat-card
                    title="Documenti"
                    :value="$stats['documents_count'] ?? 0"
                    description="Caricati"
                />

                <x-dashboard.stat-card
                    title="Prodotti"
                    :value="$stats['products_count'] ?? 0"
                    description="Salvati"
                />

                <x-dashboard.stat-card
                    title="Revisioni"
                    :value="$stats['open_reviews_count'] ?? 0"
                    tone="warning"
                    description="Aperte"
                />

                <x-dashboard.stat-card
                    title="Garanzie"
                    :value="$stats['expiring_warranties_count'] ?? 0"
                    tone="warning"
                    description="In scadenza entro 30 giorni"
                />
            </section>

            {{-- Attività principali compatte --}}
            <section class="grid grid-cols-1 gap-6 xl:grid-cols-3">
                <x-dashboard.section-panel
                    title="Prossimo passo"
                    description="Inizia dalla navbar."
                    empty-title="Carica il primo documento"
                    empty-message="Usa il pulsante in alto per aggiungere scontrini, fatture, manuali o certificati."
                />

                <x-dashboard.section-panel
                    title="Revisioni aperte"
                    description="Documenti e prodotti con dati incerti."
                    :href="route('reviews.index')"
                    link-label="Apri"
                    empty-title="Nessuna revisione"
                    empty-message="Le attività da completare compariranno qui."
                >
                    @if (($openReviewDocuments ?? collect())->isNotEmpty())
                        <div class="space-y-3">
                            @foreach ($openReviewDocuments as $document)
                                <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4">
                                    <div class="flex items-start gap-3">
                                        <div class="min-w-0 flex-1">
                                            <p class="truncate text-sm font-semibold text-slate-950" title="{{ $document->original_filename ?? 'Documento senza nome' }}">
                                                {{ $document->original_filename ?? 'Documento senza nome' }}
                                            </p>

                                            <p class="mt-1 text-xs text-amber-700">
                                                {{ str_replace('_', ' ', $document->status) }}
                                            </p>
                                        </div>

                                        <a
                                            href="{{ route('documents.show', $document) }}"
                                            class="shrink-0 rounded-md px-2 py-1 text-xs font-semibold text-amber-700 hover:bg-amber-100 hover:text-amber-900"
                                        >
                                            Apri
                                        </a>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </x-dashboard.section-panel>

                <x-dashboard.section-panel
                    title="Garanzie da controllare"
                    description="Scadenze nei prossimi 30 giorni."
                    :href="route('warranties.index')"
                    link-label="Apri"
                    empty-title="Nessuna garanzia in scadenza"
                    empty-message="Le scadenze appariranno qui quando saranno vicine."
                >
                    @if (($expiringWarranties ?? collect())->isNotEmpty())
                        <div class="space-y-3">
                            @foreach ($expiringWarranties as $warranty)
                                <div class="rounded-2xl border border-yellow-200 bg-yellow-50 p-4">
                                    <div class="flex items-start justify-between gap-3">
                                        <div>
                                            <p class="line-clamp-1 text-sm font-semibold text-slate-950">
                                                {{ $warranty->product?->name ?? 'Prodotto senza nome' }}
                                            </p>

                                            <p class="mt-1 text-xs text-yellow-700">
                                                Scade il {{ $warranty->ends_at?->format('d/m/Y') ?? '—' }}
                                            </p>

                                            <p class="mt-1 text-xs text-slate-500">
                                                {{ $warranty->warrantyType?->name ?? 'Garanzia' }}
                                                · {{ $warranty->source === 'manual' ? 'Manuale' : 'Calcolata' }}
                                            </p>
                                        </div>

                                        @if ($warranty->product)
                                            <a
                                                href="{{ route('products.show', $warranty->product) }}"
                                                class="shrink-0 text-xs font-semibold text-yellow-700 hover:text-yellow-900"
                                            >
                                                Apri
                                            </a>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </x-dashboard.section-panel>
            </section>

            {{-- Recenti compatti --}}
            <section class="grid grid-cols-1 gap-6 xl:grid-cols-2">
                <x-dashboard.section-panel
                    title="Documenti recenti"
                    description="Ultimi file caricati."
                    :href="url('/documents')"
                    link-label="Vedi tutti"
                    empty-title="Nessun documento"
                    empty-message="Scontrini, fatture, manuali e certificati appariranno qui."
                >
                    @if (($recentDocuments ?? collect())->isNotEmpty())
                        <div class="space-y-3">
                            @foreach ($recentDocuments as $document)
                                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                    <div class="flex items-start justify-between gap-3">
                                        <div>
                                            <p class="line-clamp-1 text-sm font-semibold text-slate-950">
                                                {{ $document->original_filename ?? 'Documento senza nome' }}
                                            </p>

                                            <p class="mt-1 text-xs text-slate-500">
                                                Stato: {{ str_replace('_', ' ', $document->status ?? 'non disponibile') }}
                                            </p>
                                        </div>

                                        <a
                                            href="{{ url('/documents/' . $document->id) }}"
                                            class="shrink-0 text-xs font-semibold text-slate-700 hover:text-slate-950"
                                        >
                                            Apri
                                        </a>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </x-dashboard.section-panel>

                <x-dashboard.section-panel
                    title="Prodotti recenti"
                    description="Ultime schede prodotto."
                    :href="url('/products')"
                    link-label="Vedi tutti"
                    empty-title="Nessun prodotto"
                    empty-message="Le schede create o confermate appariranno qui."
                >
                    @if (($recentProducts ?? collect())->isNotEmpty())
                        <div class="space-y-3">
                            @foreach ($recentProducts as $product)
                                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                    <div class="flex items-start justify-between gap-3">
                                        <div>
                                            <p class="line-clamp-1 text-sm font-semibold text-slate-950">
                                                {{ $product->name ?? 'Prodotto senza nome' }}
                                            </p>

                                            <p class="mt-1 text-xs text-slate-500">
                                                Creato {{ optional($product->created_at)->diffForHumans() }}
                                            </p>
                                        </div>

                                        <a
                                            href="{{ url('/products/' . $product->id) }}"
                                            class="shrink-0 text-xs font-semibold text-slate-700 hover:text-slate-950"
                                        >
                                            Apri
                                        </a>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </x-dashboard.section-panel>
            </section>

        </div>
    </div>
</x-app-layout>