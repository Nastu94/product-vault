<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Product Vault</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-50 text-slate-900 antialiased">
    @php
        $publicPlans = $planCatalog ?? [];
        $publicOffers = $oneTimeOffers ?? [];
    @endphp

    <div class="min-h-screen flex flex-col">
        <header class="sticky top-0 z-50 w-full border-b border-slate-200 bg-white/90 backdrop-blur-xl">
            <div class="mx-auto flex max-w-7xl items-center justify-between px-4 py-3 sm:px-6 lg:py-4">
                <a href="{{ route('welcome') }}" class="flex items-center gap-2 text-base font-semibold tracking-tight text-slate-950 sm:text-lg">
                    <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-slate-900 text-sm font-bold text-white">
                        PV
                    </span>
                    <span>Product Vault</span>
                </a>

                <div class="flex items-center gap-6">
                    <x-welcome.nav-links context="desktop" />

                    <div class="flex items-center gap-3">
                        <x-welcome.cta-actions context="header" />

                        <details id="welcome-mobile-menu" class="relative md:hidden">
                            <summary
                                class="flex h-11 w-11 cursor-pointer list-none items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-800 shadow-sm transition hover:bg-slate-50 [&::-webkit-details-marker]:hidden"
                                aria-label="Apri menu"
                            >
                                <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 7h16M4 12h16M4 17h16" />
                                </svg>
                            </summary>

                            <div class="absolute right-0 mt-3 w-72 rounded-2xl border border-slate-200 bg-white p-4 shadow-2xl">
                                <p class="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-400">
                                    Navigazione
                                </p>
                                <x-welcome.nav-links context="mobile" />

                                <div class="my-4 h-px bg-slate-200"></div>

                                <p class="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-400">
                                    Account
                                </p>
                                <x-welcome.cta-actions context="mobile" />
                            </div>
                        </details>
                    </div>
                </div>
            </div>
        </header>

        <main class="flex-1">
            <section class="mx-auto grid max-w-7xl grid-cols-1 gap-12 px-6 py-20 lg:grid-cols-2 lg:items-center">
                <div class="pv-animate-fade-up">
                    <p class="mb-4 inline-flex rounded-full border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-600 shadow-sm">
                        Archivio intelligente con piano Free e capacità trasparenti
                    </p>

                    <h1 class="max-w-3xl text-4xl font-bold tracking-tight text-slate-950 sm:text-5xl lg:text-6xl">
                        Documenti, prodotti, garanzie e assistenza in un unico spazio.
                    </h1>

                    <p class="mt-6 max-w-2xl text-lg leading-8 text-slate-600">
                        Carica scontrini, fatture, manuali o certificati. Product Vault conserva la prova,
                        estrae ciò che è affidabile e ti lascia confermare i dati prima di creare una scheda prodotto.
                    </p>

                    <div class="mt-6 flex flex-wrap gap-3 text-sm text-slate-600">
                        <span class="rounded-full bg-white px-3 py-1 ring-1 ring-inset ring-slate-200">Piano Free disponibile</span>
                        <span class="rounded-full bg-white px-3 py-1 ring-1 ring-inset ring-slate-200">Limiti monitorati, non bloccanti</span>
                        <span class="rounded-full bg-white px-3 py-1 ring-1 ring-inset ring-slate-200">Nessun checkout attivo</span>
                    </div>

                    <x-welcome.cta-actions context="hero" />
                </div>

                <div class="pv-animate-fade-left pv-animate-delay-200 rounded-3xl border border-slate-200 bg-white p-6 shadow-xl">
                    <div class="flex items-center justify-between gap-4">
                        <div>
                            <p class="text-sm font-semibold text-slate-950">Workspace personale</p>
                            <p class="mt-1 text-xs text-slate-500">Piano Free · monitoraggio utilizzo</p>
                        </div>
                        <span class="rounded-full bg-green-50 px-3 py-1 text-xs font-semibold text-green-700 ring-1 ring-inset ring-green-600/20">
                            Attivo
                        </span>
                    </div>

                    <div class="mt-6 grid grid-cols-2 gap-3">
                        <div class="rounded-2xl bg-slate-50 p-4">
                            <p class="text-xs text-slate-500">Documenti</p>
                            <p class="mt-2 text-2xl font-bold text-slate-950">12 / 50</p>
                            <div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-200">
                                <div class="h-full w-[24%] rounded-full bg-slate-800"></div>
                            </div>
                        </div>

                        <div class="rounded-2xl bg-slate-50 p-4">
                            <p class="text-xs text-slate-500">Prodotti</p>
                            <p class="mt-2 text-2xl font-bold text-slate-950">7 / 50</p>
                            <div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-200">
                                <div class="h-full w-[14%] rounded-full bg-slate-800"></div>
                            </div>
                        </div>

                        <div class="rounded-2xl bg-yellow-50 p-4 ring-1 ring-inset ring-yellow-200">
                            <p class="text-xs text-yellow-700">Pratiche aperte</p>
                            <p class="mt-2 text-2xl font-bold text-slate-950">1 / 1</p>
                            <p class="mt-2 text-xs text-yellow-800">Capacità raggiunta, flusso non bloccato in monitoraggio.</p>
                        </div>

                        <div class="rounded-2xl bg-slate-50 p-4">
                            <p class="text-xs text-slate-500">OCR mensili</p>
                            <p class="mt-2 text-2xl font-bold text-slate-950">4 / 30</p>
                            <p class="mt-2 text-xs text-slate-500">Si azzerano ogni mese.</p>
                        </div>
                    </div>

                    <p class="mt-5 text-xs leading-5 text-slate-500">
                        Esempio dimostrativo della nuova area Piano e utilizzo. Le capacità reali dipendono dal workspace e dal piano assegnato.
                    </p>
                </div>
            </section>

            <section id="come-funziona" class="scroll-mt-24 border-t border-slate-200 bg-white py-20">
                <div class="mx-auto max-w-7xl px-6">
                    <div class="max-w-3xl">
                        <p class="text-sm font-semibold uppercase tracking-wide text-slate-500">Come funziona</p>
                        <h2 class="mt-3 text-3xl font-bold tracking-tight text-slate-950 sm:text-4xl">
                            Dal documento a un archivio utile, senza automazioni opache.
                        </h2>
                        <p class="mt-4 text-lg leading-8 text-slate-600">
                            Product Vault separa il documento dal prodotto, conserva il dato originale e rende visibile ciò che è estratto,
                            suggerito o ancora da completare.
                        </p>
                    </div>

                    <div class="mt-12 grid grid-cols-1 gap-6 md:grid-cols-4">
                        @foreach ([
                            ['1', 'Carica', 'Aggiungi PDF o immagini in uno storage privato legato al workspace.'],
                            ['2', 'Analizza', 'Il sistema legge testo, importi, righe e possibili informazioni prodotto.'],
                            ['3', 'Conferma', 'Rivedi i dati incerti prima che diventino parte della scheda prodotto.'],
                            ['4', 'Gestisci', 'Controlla coperture, pratiche, documenti collegati e risultati ottenuti.'],
                        ] as [$number, $title, $description])
                            <article class="pv-card-hover rounded-2xl border border-slate-200 bg-slate-50 p-6">
                                <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-slate-900 text-sm font-bold text-white">
                                    {{ $number }}
                                </div>
                                <h3 class="mt-5 text-lg font-semibold text-slate-950">{{ $title }}</h3>
                                <p class="mt-3 text-sm leading-6 text-slate-600">{{ $description }}</p>
                            </article>
                        @endforeach
                    </div>
                </div>
            </section>

            <section id="benefici" class="scroll-mt-24 border-t border-slate-200 bg-slate-50 py-20">
                <div class="mx-auto max-w-7xl px-6">
                    <div class="grid grid-cols-1 gap-10 lg:grid-cols-2 lg:items-start">
                        <div>
                            <p class="text-sm font-semibold uppercase tracking-wide text-slate-500">Valore operativo</p>
                            <h2 class="mt-3 text-3xl font-bold tracking-tight text-slate-950 sm:text-4xl">
                                Non un semplice drive: un archivio che ti aiuta ad agire.
                            </h2>
                            <p class="mt-4 text-lg leading-8 text-slate-600">
                                Ritrova prove d’acquisto, completa schede prodotto, controlla scadenze e prepara pratiche di assistenza tracciabili.
                            </p>
                        </div>

                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            @foreach ([
                                ['Prove sempre collegate', 'Scontrini, fatture e certificati restano associati al prodotto corretto.'],
                                ['Dati revisionabili', 'Le informazioni incerte non vengono presentate come verità assolute.'],
                                ['Pratiche operative', 'Puoi preparare, inviare, risolvere e chiudere richieste di assistenza.'],
                                ['Risultati misurabili', 'Riparazioni, sostituzioni e rimborsi diventano parte dello storico.'],
                            ] as [$title, $description])
                                <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                                    <h3 class="text-base font-semibold text-slate-950">{{ $title }}</h3>
                                    <p class="mt-2 text-sm leading-6 text-slate-600">{{ $description }}</p>
                                </article>
                            @endforeach
                        </div>
                    </div>
                </div>
            </section>

            <section id="piani" data-testid="welcome-monetization" class="scroll-mt-24 border-t border-slate-200 bg-white py-20">
                <div class="mx-auto max-w-7xl px-6">
                    <div class="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
                        <div class="max-w-3xl">
                            <p class="text-sm font-semibold uppercase tracking-wide text-slate-500">Piani e capacità</p>
                            <h2 class="mt-3 text-3xl font-bold tracking-tight text-slate-950 sm:text-4xl">
                                Inizia con il piano Free. Cresci solo quando serve davvero.
                            </h2>
                            <p class="mt-4 text-lg leading-8 text-slate-600">
                                Il sistema misura documenti, prodotti, storage, OCR, membri e pratiche aperte. Durante la validazione i limiti sono in modalità monitoraggio e non attivano addebiti o upgrade automatici.
                            </p>
                        </div>

                        <div class="rounded-2xl bg-slate-100 px-4 py-3 text-sm text-slate-600 ring-1 ring-inset ring-slate-200">
                            Checkout e pagamenti non sono ancora attivi.
                        </div>
                    </div>

                    <div class="mt-10 grid grid-cols-1 gap-5 md:grid-cols-2 xl:grid-cols-4">
                        @forelse ($publicPlans as $plan)
                            @php
                                $limits = $plan['limits'] ?? [];
                                $features = collect($plan['features'] ?? [])
                                    ->filter(fn (array $feature): bool => (bool) ($feature['enabled'] ?? false))
                                    ->take(4);
                                $isRecommended = ($plan['code'] ?? null) === 'premium_personal';
                                $documentsLimit = data_get($limits, 'max_documents.value');
                                $productsLimit = data_get($limits, 'max_products.value');
                                $storageLimit = data_get($limits, 'max_storage_mb.value');
                                $membersLimit = data_get($limits, 'max_team_members.value');
                            @endphp

                            <article class="relative rounded-3xl border p-6 {{ $isRecommended ? 'border-slate-900 bg-slate-50 shadow-lg' : 'border-slate-200 bg-white shadow-sm' }}">
                                @if ($isRecommended)
                                    <span class="absolute right-5 top-5 rounded-full bg-slate-900 px-3 py-1 text-xs font-semibold text-white">
                                        Evoluzione personale
                                    </span>
                                @endif

                                <p class="text-sm font-semibold uppercase tracking-wide text-slate-500">
                                    {{ $plan['code'] ?? 'piano' }}
                                </p>
                                <h3 class="mt-2 text-xl font-bold text-slate-950">{{ $plan['name'] }}</h3>
                                <p class="mt-2 text-sm font-semibold text-slate-700">{{ $plan['price_label'] }}</p>
                                <p class="mt-4 text-sm leading-6 text-slate-600">{{ $plan['description'] }}</p>

                                <dl class="mt-6 space-y-2 text-sm">
                                    <div class="flex justify-between gap-3"><dt class="text-slate-500">Documenti</dt><dd class="font-semibold text-slate-900">{{ $documentsLimit === null ? 'Illimitati' : number_format((int) $documentsLimit, 0, ',', '.') }}</dd></div>
                                    <div class="flex justify-between gap-3"><dt class="text-slate-500">Prodotti</dt><dd class="font-semibold text-slate-900">{{ $productsLimit === null ? 'Illimitati' : number_format((int) $productsLimit, 0, ',', '.') }}</dd></div>
                                    <div class="flex justify-between gap-3"><dt class="text-slate-500">Storage</dt><dd class="font-semibold text-slate-900">{{ $storageLimit === null ? 'Illimitato' : number_format((int) $storageLimit, 0, ',', '.') . ' MB' }}</dd></div>
                                    <div class="flex justify-between gap-3"><dt class="text-slate-500">Membri</dt><dd class="font-semibold text-slate-900">{{ $membersLimit === null ? 'Illimitati' : number_format((int) $membersLimit, 0, ',', '.') }}</dd></div>
                                </dl>

                                <div class="mt-6 space-y-2 border-t border-slate-200 pt-5">
                                    @foreach ($features as $feature)
                                        <p class="flex gap-2 text-xs leading-5 text-slate-600">
                                            <span class="mt-1 text-green-600">✓</span>
                                            <span>{{ $feature['description'] }}</span>
                                        </p>
                                    @endforeach
                                </div>
                            </article>
                        @empty
                            <div class="rounded-3xl border border-dashed border-slate-300 bg-slate-50 p-8 text-sm text-slate-600 md:col-span-2 xl:col-span-4">
                                Il catalogo dei piani non è ancora disponibile. Esegui il seeding della monetizzazione per visualizzarlo.
                            </div>
                        @endforelse
                    </div>

                    @if ($publicOffers !== [])
                        <div class="mt-12 rounded-3xl border border-slate-200 bg-slate-50 p-6">
                            <div class="max-w-3xl">
                                <p class="text-sm font-semibold uppercase tracking-wide text-slate-500">Servizi una tantum previsti</p>
                                <h3 class="mt-2 text-2xl font-bold text-slate-950">Supporto operativo senza cambiare piano.</h3>
                                <p class="mt-2 text-sm leading-6 text-slate-600">
                                    Il catalogo è informativo: prezzi, acquisto e concessione automatica non sono ancora attivi.
                                </p>
                            </div>

                            <div class="mt-6 grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
                                @foreach ($publicOffers as $offer)
                                    <article class="rounded-2xl border border-slate-200 bg-white p-5">
                                        <h4 class="text-base font-semibold text-slate-950">{{ $offer['name'] ?? $offer['code'] }}</h4>
                                        <p class="mt-2 text-xs leading-5 text-slate-600">{{ $offer['description'] ?? '' }}</p>
                                        <p class="mt-4 text-xs font-semibold text-slate-500">Prezzo da definire</p>
                                    </article>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>
            </section>

            <section id="sicurezza" class="scroll-mt-24 border-t border-slate-800 bg-slate-950 py-20 text-white">
                <div class="mx-auto grid max-w-7xl grid-cols-1 gap-10 px-6 lg:grid-cols-2 lg:items-center">
                    <div>
                        <p class="text-sm font-semibold uppercase tracking-wide text-slate-400">Privacy e controllo</p>
                        <h2 class="mt-3 text-3xl font-bold tracking-tight sm:text-4xl">
                            I documenti restano privati e i dati incerti restano verificabili.
                        </h2>
                        <p class="mt-4 text-lg leading-8 text-slate-300">
                            Ogni file appartiene a un workspace, viene servito tramite autorizzazioni e non diventa automaticamente una scheda prodotto certa.
                        </p>
                    </div>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        @foreach ([
                            ['Storage privato', 'I file originali non vengono trattati come asset pubblici.'],
                            ['Permessi per risorsa', 'Ogni utente vede solo i dati del workspace a cui appartiene.'],
                            ['Dati revisionabili', 'Estratto, suggerito e confermato restano concetti distinti.'],
                            ['Garanzie come stime', 'Scadenze e coperture vanno verificate e non costituiscono consulenza legale.'],
                        ] as [$title, $description])
                            <article class="rounded-2xl border border-slate-800 bg-slate-900 p-5">
                                <h3 class="text-base font-semibold text-white">{{ $title }}</h3>
                                <p class="mt-2 text-sm leading-6 text-slate-400">{{ $description }}</p>
                            </article>
                        @endforeach
                    </div>
                </div>
            </section>

            <section class="border-t border-slate-200 bg-slate-50 py-20">
                <div class="mx-auto max-w-4xl px-6 text-center">
                    <p class="text-sm font-semibold uppercase tracking-wide text-slate-500">Inizia dal piano Free</p>
                    <h2 class="mt-3 text-3xl font-bold tracking-tight text-slate-950 sm:text-4xl">
                        Crea il tuo archivio e misura il valore prima di scegliere di più.
                    </h2>
                    <p class="mx-auto mt-5 max-w-2xl text-lg leading-8 text-slate-600">
                        Nessun checkout è attivo: puoi iniziare dal flusso essenziale e verificare documenti, prodotti, coperture e pratiche nel tuo workspace.
                    </p>
                    <x-welcome.cta-actions context="final" />
                </div>
            </section>
        </main>

        <footer class="border-t border-slate-200 bg-white">
            <div class="mx-auto flex max-w-7xl flex-col gap-4 px-6 py-6 text-sm text-slate-500 sm:flex-row sm:items-center sm:justify-between">
                <p>© {{ date('Y') }} Product Vault. Tutti i diritti riservati.</p>
                <p>MVP in validazione — piano Free attivo, catalogo premium senza pagamenti.</p>
            </div>
        </footer>
    </div>
</body>
</html>
