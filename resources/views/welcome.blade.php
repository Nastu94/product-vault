<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    {{-- Meta base della pagina --}}
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    {{-- Titolo mostrato nella scheda del browser --}}
    <title>Product Vault</title>

    {{-- Font --}}
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700" rel="stylesheet" />

    {{-- Asset compilati da Vite --}}
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="min-h-screen bg-slate-50 text-slate-900 antialiased">
    <div class="min-h-screen flex flex-col">

        {{-- Header sticky responsive --}}
        <header class="sticky top-0 z-50 w-full border-b border-slate-200 bg-white/85 backdrop-blur-xl">
            <div class="mx-auto flex max-w-7xl items-center justify-between px-4 py-3 sm:px-6 lg:py-4">
                <a href="{{ route('welcome') }}" class="flex items-center gap-2 text-base font-semibold tracking-tight text-slate-950 sm:text-lg">
                    <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-slate-900 text-sm font-bold text-white">
                        PV
                    </span>

                    <span>
                        Product Vault
                    </span>
                </a>

                <div class="flex items-center gap-6">
                    {{-- Link desktop alle sezioni --}}
                    <x-welcome.nav-links context="desktop" />

                    <div class="flex items-center gap-3">
                        {{-- Navigazione desktop/tablet --}}
                        <x-welcome.cta-actions context="header" />

                        {{-- Navigazione mobile --}}
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
        </header>

        {{-- Contenuto principale --}}
        <main class="flex-1">
            {{-- Hero iniziale --}}
            <section class="mx-auto grid max-w-7xl grid-cols-1 gap-12 px-6 py-20 lg:grid-cols-2 lg:items-center">
                
                {{-- Testo principale --}}
                <div class="pv-animate-fade-up">
                    <p class="mb-4 inline-flex rounded-full border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-600 shadow-sm">
                        Archivio intelligente per prodotti, documenti e garanzie
                    </p>

                    <h1 class="max-w-3xl text-4xl font-bold tracking-tight text-slate-950 sm:text-5xl lg:text-6xl">
                        Il portafoglio digitale per i tuoi prodotti.
                    </h1>

                    <p class="mt-6 max-w-2xl text-lg leading-8 text-slate-600">
                        Carica scontrini, fatture, manuali o certificati. Product Vault li organizza,
                        estrae i dati utili e ti aiuta a creare schede prodotto verificabili.
                    </p>

                    <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                        <x-welcome.cta-actions context="hero" />
                    </div>
                </div>

                {{-- Box visuale laterale: esempio non interattivo --}}
                <div class="pv-animate-fade-left pv-animate-delay-200 pv-animate-float rounded-3xl border border-slate-200 bg-white p-6 shadow-xl">
                    <div class="mb-4 flex items-center justify-between">
                        <p class="text-sm font-semibold text-slate-700">
                            Esempio di revisione documento
                        </p>

                        <span class="rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-xs font-medium text-slate-500">
                            Demo UI
                        </span>
                    </div>

                    <div class="rounded-2xl bg-slate-50 p-5">
                        <div class="flex items-center justify-between border-b border-slate-200 pb-4">
                            <div>
                                <p class="text-sm font-medium text-slate-500">Documento caricato</p>
                                <p class="mt-1 font-semibold text-slate-950">scontrino-mediaworld.jpg</p>
                            </div>

                            <span class="rounded-full bg-amber-100 px-3 py-1 text-xs font-medium text-amber-700">
                                Da revisionare
                            </span>
                        </div>

                        <div class="mt-5 space-y-4">
                            <div>
                                <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Venditore</p>
                                <p class="mt-1 text-sm font-medium text-slate-800">MediaWorld</p>
                            </div>

                            <div>
                                <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Prodotto suggerito</p>
                                <p class="mt-1 text-sm font-medium text-slate-800">Apple iPhone 15 128GB Black</p>
                            </div>

                            <div>
                                <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Affidabilità prodotto</p>

                                <div class="mt-2 h-2 overflow-hidden rounded-full bg-slate-200">
                                    <div class="h-full w-[62%] rounded-full bg-slate-900"></div>
                                </div>

                                <p class="mt-2 text-sm text-slate-500">
                                    62/100 — revisione consigliata
                                </p>
                            </div>
                        </div>

                        <div class="mt-6 grid grid-cols-2 gap-3">
                            <div class="rounded-xl bg-slate-900 px-4 py-3 text-center text-sm font-semibold text-white">
                                Conferma
                            </div>

                            <div class="rounded-xl border border-slate-300 bg-white px-4 py-3 text-center text-sm font-semibold text-slate-700">
                                Modifica
                            </div>
                        </div>

                        <p class="mt-4 text-xs leading-5 text-slate-400">
                            Schermata dimostrativa: i dati mostrati sono solo un esempio del flusso di revisione.
                        </p>
                    </div>
                </div>
            </section>

            {{-- Sezione: Come funziona --}}
            <section id="come-funziona" class="scroll-mt-24 border-t border-slate-200 bg-white py-20">
                <div class="mx-auto max-w-7xl px-6">
                    <div class="max-w-2xl">
                        <p class="text-sm font-semibold uppercase tracking-wide text-slate-500">
                            Come funziona
                        </p>

                        <h2 class="mt-3 text-3xl font-bold tracking-tight text-slate-950 sm:text-4xl">
                            Dal documento alla scheda prodotto, senza perdere il controllo.
                        </h2>

                        <p class="mt-4 text-lg leading-8 text-slate-600">
                            Product Vault non inventa dati: estrae ciò che riesce a leggere, segnala le informazioni incerte
                            e ti permette di confermare o correggere prima di salvare.
                        </p>
                    </div>

                    <div class="mt-12 grid grid-cols-1 gap-6 md:grid-cols-3">
                        <div class="pv-card-hover rounded-2xl border border-slate-200 bg-slate-50 p-6">
                            <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-slate-900 text-sm font-bold text-white">
                                1
                            </div>

                            <h3 class="mt-5 text-lg font-semibold text-slate-950">
                                Carica un documento
                            </h3>

                            <p class="mt-3 text-sm leading-6 text-slate-600">
                                Puoi caricare scontrini, fatture, manuali, certificati di garanzia o documenti collegati a un prodotto.
                            </p>
                        </div>

                        <div class="pv-card-hover rounded-2xl border border-slate-200 bg-slate-50 p-6">
                            <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-slate-900 text-sm font-bold text-white">
                                2
                            </div>

                            <h3 class="mt-5 text-lg font-semibold text-slate-950">
                                Il sistema lo analizza
                            </h3>

                            <p class="mt-3 text-sm leading-6 text-slate-600">
                                Product Vault prova a leggere testo, venditore, data, importi e possibili informazioni prodotto.
                            </p>
                        </div>

                        <div class="pv-card-hover rounded-2xl border border-slate-200 bg-slate-50 p-6">
                            <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-slate-900 text-sm font-bold text-white">
                                3
                            </div>

                            <h3 class="mt-5 text-lg font-semibold text-slate-950">
                                Confermi e completi
                            </h3>

                            <p class="mt-3 text-sm leading-6 text-slate-600">
                                Rivedi i campi estratti, correggi quelli incerti e salvi una scheda prodotto più affidabile.
                            </p>
                        </div>
                    </div>
                </div>
            </section>

            {{-- Sezione: Cosa puoi caricare --}}
            <section id="documenti" class="scroll-mt-24 border-t border-slate-200 bg-slate-50 py-20">
                <div class="mx-auto max-w-7xl px-6">
                    <div class="max-w-2xl">
                        <p class="text-sm font-semibold uppercase tracking-wide text-slate-500">
                            Documenti supportati
                        </p>

                        <h2 class="mt-3 text-3xl font-bold tracking-tight text-slate-950 sm:text-4xl">
                            Non solo scontrini: tutto ciò che racconta la vita di un prodotto.
                        </h2>

                        <p class="mt-4 text-lg leading-8 text-slate-600">
                            Product Vault ti aiuta a raccogliere documenti utili per acquisto, garanzia,
                            identificazione, assistenza e riparazione. Ogni documento resta separato dal prodotto,
                            ma può essere collegato alla sua scheda.
                        </p>
                    </div>

                    <div class="mt-12 grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
                        <div class="pv-card-hover rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                            <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-slate-900 text-white">
                                <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M7 3h10a2 2 0 0 1 2 2v16l-3-2-2 2-2-2-2 2-2-2-3 2V5a2 2 0 0 1 2-2Z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 8h6M9 12h6M9 16h3" />
                                </svg>
                            </div>

                            <h3 class="mt-5 text-lg font-semibold text-slate-950">
                                Scontrini e fatture
                            </h3>

                            <p class="mt-3 text-sm leading-6 text-slate-600">
                                Conserva prove d’acquisto e dati come venditore, data, totale e possibili righe prodotto.
                            </p>
                        </div>

                        <div class="pv-card-hover rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                            <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-slate-900 text-white">
                                <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 3 5 6v6c0 4.5 3 7.5 7 9 4-1.5 7-4.5 7-9V6l-7-3Z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m9 12 2 2 4-4" />
                                </svg>
                            </div>

                            <h3 class="mt-5 text-lg font-semibold text-slate-950">
                                Certificati di garanzia
                            </h3>

                            <p class="mt-3 text-sm leading-6 text-slate-600">
                                Collega garanzie commerciali, estensioni o certificati alla scheda del prodotto corretto.
                            </p>
                        </div>

                        <div class="pv-card-hover rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                            <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-slate-900 text-white">
                                <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 4h10a4 4 0 0 1 4 4v12H8a3 3 0 0 0-3-3V4Z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 8h7M8 12h6" />
                                </svg>
                            </div>

                            <h3 class="mt-5 text-lg font-semibold text-slate-950">
                                Manuali prodotto
                            </h3>

                            <p class="mt-3 text-sm leading-6 text-slate-600">
                                Salva manuali, istruzioni e documentazione utile senza confonderli con prove d’acquisto.
                            </p>
                        </div>

                        <div class="pv-card-hover rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                            <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-slate-900 text-white">
                                <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M14.7 6.3a4 4 0 0 0-5.4 5.4L4 17v3h3l5.3-5.3a4 4 0 0 0 5.4-5.4l-2.4 2.4-3-3 2.4-2.4Z" />
                                </svg>
                            </div>

                            <h3 class="mt-5 text-lg font-semibold text-slate-950">
                                Riparazioni e assistenza
                            </h3>

                            <p class="mt-3 text-sm leading-6 text-slate-600">
                                Tieni traccia di interventi, preventivi e documenti di assistenza nello storico del prodotto.
                            </p>
                        </div>

                        <div class="pv-card-hover rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                            <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-slate-900 text-white">
                                <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 7V5a1 1 0 0 1 1-1h2M17 4h2a1 1 0 0 1 1 1v2M20 17v2a1 1 0 0 1-1 1h-2M7 20H5a1 1 0 0 1-1-1v-2" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 8v8M11 8v8M15 8v8M17 8v8" />
                                </svg>
                            </div>

                            <h3 class="mt-5 text-lg font-semibold text-slate-950">
                                Barcode e numeri seriali
                            </h3>

                            <p class="mt-3 text-sm leading-6 text-slate-600">
                                Aggiungi foto o codici identificativi quando lo scontrino non descrive bene il prodotto.
                            </p>
                        </div>

                        <div class="pv-card-hover rounded-2xl border border-dashed border-slate-300 bg-white p-6 shadow-sm">
                            <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-slate-100 text-slate-700">
                                <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M10.3 4.3 2.8 17.3A2 2 0 0 0 4.5 20h15a2 2 0 0 0 1.7-2.7L13.7 4.3a2 2 0 0 0-3.4 0Z" />
                                </svg>
                            </div>

                            <h3 class="mt-5 text-lg font-semibold text-slate-950">
                                Documenti incerti
                            </h3>

                            <p class="mt-3 text-sm leading-6 text-slate-600">
                                Se un file non viene riconosciuto, Product Vault può salvarlo come documento da revisionare,
                                senza creare automaticamente dati poco affidabili.
                            </p>
                        </div>
                    </div>
                </div>
            </section>

            {{-- Sezione: Perché usarlo --}}
            <section class="border-t border-slate-200 bg-white py-20">
                <div class="mx-auto max-w-7xl px-6">
                    <section id="benefici" class="scroll-mt-24 border-t border-slate-200 bg-white py-20">
                        
                        {{-- Testo introduttivo --}}
                        <div>
                            <p class="text-sm font-semibold uppercase tracking-wide text-slate-500">
                                Perché usarlo
                            </p>

                            <h2 class="mt-3 text-3xl font-bold tracking-tight text-slate-950 sm:text-4xl">
                                Meno documenti sparsi, più controllo sui tuoi prodotti.
                            </h2>

                            <p class="mt-4 text-lg leading-8 text-slate-600">
                                Product Vault nasce per aiutarti a conservare informazioni che spesso finiscono
                                disperse tra email, cassetti, foto del telefono e PDF scaricati una sola volta.
                            </p>

                            <p class="mt-4 text-lg leading-8 text-slate-600">
                                Il valore non è solo salvare un file, ma collegarlo al prodotto giusto e renderlo
                                utile quando devi controllare una garanzia, recuperare una prova d’acquisto o gestire
                                un intervento di assistenza.
                            </p>
                        </div>

                        {{-- Lista benefici --}}
                        <div class="grid grid-cols-1 gap-5">
                            <div class="pv-card-hover rounded-2xl border border-slate-200 bg-slate-50 p-6">
                                <div class="flex gap-4">
                                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-slate-900 text-white">
                                        <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="m5 13 4 4L19 7" />
                                        </svg>
                                    </div>

                                    <div>
                                        <h3 class="text-lg font-semibold text-slate-950">
                                            Ritrovi più facilmente le prove d’acquisto
                                        </h3>

                                        <p class="mt-2 text-sm leading-6 text-slate-600">
                                            Scontrini e fatture non restano più dispersi tra foto, download e documenti non ordinati.
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <div class="pv-card-hover rounded-2xl border border-slate-200 bg-slate-50 p-6">
                                <div class="flex gap-4">
                                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-slate-900 text-white">
                                        <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="m5 13 4 4L19 7" />
                                        </svg>
                                    </div>

                                    <div>
                                        <h3 class="text-lg font-semibold text-slate-950">
                                            Costruisci uno storico del prodotto
                                        </h3>

                                        <p class="mt-2 text-sm leading-6 text-slate-600">
                                            Puoi collegare acquisto, garanzia, manuale, seriale e documenti di assistenza alla stessa scheda.
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <div class="pv-card-hover rounded-2xl border border-slate-200 bg-slate-50 p-6">
                                <div class="flex gap-4">
                                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-slate-900 text-white">
                                        <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="m5 13 4 4L19 7" />
                                        </svg>
                                    </div>

                                    <div>
                                        <h3 class="text-lg font-semibold text-slate-950">
                                            Controlli dati e scadenze con più chiarezza
                                        </h3>

                                        <p class="mt-2 text-sm leading-6 text-slate-600">
                                            Le informazioni estratte vengono mostrate come dati da verificare, non come verità assolute.
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <div class="pv-card-hover rounded-2xl border border-slate-200 bg-slate-50 p-6">
                                <div class="flex gap-4">
                                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-slate-900 text-white">
                                        <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="m5 13 4 4L19 7" />
                                        </svg>
                                    </div>

                                    <div>
                                        <h3 class="text-lg font-semibold text-slate-950">
                                            Eviti schede prodotto inventate
                                        </h3>

                                        <p class="mt-2 text-sm leading-6 text-slate-600">
                                            Se i dati sono insufficienti, Product Vault salva il documento e ti chiede di completare ciò che manca.
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            {{-- Sezione: Privacy e sicurezza --}}
            <section id="sicurezza" class="scroll-mt-24 border-t border-slate-800 bg-slate-950 py-20 text-white">
                <div class="mx-auto max-w-7xl px-6">
                    <div class="grid grid-cols-1 gap-12 lg:grid-cols-2 lg:items-center">

                        {{-- Testo principale --}}
                        <div>
                            <p class="text-sm font-semibold uppercase tracking-wide text-slate-400">
                                Privacy e sicurezza
                            </p>

                            <h2 class="mt-3 text-3xl font-bold tracking-tight sm:text-4xl">
                                I documenti prodotto possono contenere dati personali.
                            </h2>

                            <p class="mt-4 text-lg leading-8 text-slate-300">
                                Scontrini, fatture e certificati possono includere indirizzi, codici fiscali,
                                numeri ordine, importi e riferimenti di pagamento. Per questo Product Vault
                                li tratta come documenti privati, non come semplici immagini pubbliche.
                            </p>

                            <p class="mt-4 text-lg leading-8 text-slate-300">
                                L’obiettivo dell’MVP è costruire un flusso ordinato e controllabile:
                                ogni file resta collegato al tuo account, ogni dato estratto può essere
                                verificato e le informazioni incerte vengono mostrate come tali.
                            </p>
                        </div>

                        {{-- Card sicurezza --}}
                        <div class="rounded-3xl border border-slate-800 bg-white/5 p-6 shadow-2xl">
                            <div class="space-y-5">
                                <div class="rounded-2xl border border-slate-800 bg-slate-900 p-5">
                                    <div class="flex gap-4">
                                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-white text-slate-950">
                                            <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 3 5 6v6c0 4.5 3 7.5 7 9 4-1.5 7-4.5 7-9V6l-7-3Z" />
                                                <path stroke-linecap="round" stroke-linejoin="round" d="m9 12 2 2 4-4" />
                                            </svg>
                                        </div>

                                        <div>
                                            <h3 class="text-base font-semibold text-white">
                                                File gestiti come risorse private
                                            </h3>

                                            <p class="mt-2 text-sm leading-6 text-slate-400">
                                                I documenti caricati non devono essere trattati come asset pubblici,
                                                ma serviti solo attraverso controlli di accesso.
                                            </p>
                                        </div>
                                    </div>
                                </div>

                                <div class="rounded-2xl border border-slate-800 bg-slate-900 p-5">
                                    <div class="flex gap-4">
                                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-white text-slate-950">
                                            <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M16 10V7a4 4 0 0 0-8 0v3" />
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 10h12v10H6V10Z" />
                                            </svg>
                                        </div>

                                        <div>
                                            <h3 class="text-base font-semibold text-white">
                                                Accesso legato all’account
                                            </h3>

                                            <p class="mt-2 text-sm leading-6 text-slate-400">
                                                Documenti e prodotti appartengono a un account/workspace,
                                                così il controllo dei permessi resta chiaro anche in scenari futuri.
                                            </p>
                                        </div>
                                    </div>
                                </div>

                                <div class="rounded-2xl border border-slate-800 bg-slate-900 p-5">
                                    <div class="flex gap-4">
                                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-white text-slate-950">
                                            <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 11h6M9 15h4" />
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M7 3h7l4 4v14H7V3Z" />
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M14 3v5h5" />
                                            </svg>
                                        </div>

                                        <div>
                                            <h3 class="text-base font-semibold text-white">
                                                Dati estratti sempre revisionabili
                                            </h3>

                                            <p class="mt-2 text-sm leading-6 text-slate-400">
                                                Le informazioni lette dal documento non diventano automaticamente
                                                dati certi: puoi confermare, correggere o completare.
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <p class="mt-6 rounded-2xl border border-slate-800 bg-slate-900 p-4 text-sm leading-6 text-slate-400">
                                Le scadenze di garanzia mostrate da Product Vault devono essere considerate stime
                                operative da verificare, non consulenza legale o garanzie assolute.
                            </p>
                        </div>
                    </div>
                </div>
            </section>

            {{-- Sezione: CTA finale --}}
            <section class="border-t border-slate-200 bg-slate-50 py-20">
                <div class="mx-auto max-w-4xl px-6 text-center">
                    <p class="text-sm font-semibold uppercase tracking-wide text-slate-500">
                        Inizia da un documento
                    </p>

                    <h2 class="mt-3 text-3xl font-bold tracking-tight text-slate-950 sm:text-4xl">
                        Costruisci il tuo archivio prodotto, una prova d’acquisto alla volta.
                    </h2>

                    <p class="mx-auto mt-5 max-w-2xl text-lg leading-8 text-slate-600">
                        Carica il primo documento, verifica i dati estratti e inizia a organizzare prodotti,
                        garanzie e informazioni utili in un unico spazio.
                    </p>

                    <div class="mt-8 flex flex-col justify-center gap-3 sm:flex-row">
                        <x-welcome.cta-actions context="final" />
                    </div>
                </div>
            </section>
        </main>

        {{-- Footer minimale --}}
        <footer class="border-t border-slate-200 bg-white">
            <div class="mx-auto flex max-w-7xl flex-col gap-4 px-6 py-6 text-sm text-slate-500 sm:flex-row sm:items-center sm:justify-between">
                <p>
                    © {{ date('Y') }} Product Vault. Tutti i diritti riservati.
                </p>

                <p>
                    MVP in sviluppo — documenti, prodotti e garanzie in un unico spazio.
                </p>
            </div>
        </footer>
    </div>
</body>
</html>