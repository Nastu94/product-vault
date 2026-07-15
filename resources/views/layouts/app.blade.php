<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'Laravel') }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="font-sans antialiased">
    <x-banner />

    <div class="min-h-screen bg-gray-100">
        @livewire('navigation-menu')

        @if (isset($header))
            <header class="bg-white shadow">
                <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
                    {{ $header }}
                </div>
            </header>
        @endif

        <main>
            @if (request()->routeIs('product-cases.show'))
                @php($workflowProductCase = request()->route('productCase'))

                @if ($workflowProductCase instanceof \App\Models\ProductCase)
                    @livewire(
                        'product-cases.product-case-workflow-bar',
                        ['productCase' => $workflowProductCase],
                        key('product-case-workflow-bar-' . $workflowProductCase->getKey())
                    )

                    @livewire(
                        'product-cases.product-case-stop-bar',
                        ['productCase' => $workflowProductCase],
                        key('product-case-stop-bar-' . $workflowProductCase->getKey())
                    )
                @endif
            @endif

            @if (! request()->routeIs('account.plan'))
                @livewire(
                    'account.plan-usage-notice',
                    [],
                    key('global-plan-usage-notice')
                )
            @endif

            {{ $slot }}
        </main>

        <footer class="border-t border-slate-200 bg-white">
            <div class="mx-auto flex max-w-7xl flex-col gap-3 px-6 py-5 text-xs text-slate-500 sm:flex-row sm:items-center sm:justify-between">
                <p>
                    Product Vault organizza dati e stime: verifica sempre il documento originale.
                </p>

                <div class="flex flex-wrap gap-4 font-medium">
                    <a href="{{ route('legal.privacy') }}" class="hover:text-slate-900">Privacy</a>
                    <a href="{{ route('legal.terms') }}" class="hover:text-slate-900">Termini</a>
                    <a href="{{ route('legal.document-processing') }}" class="hover:text-slate-900">Trattamento documenti</a>
                </div>
            </div>
        </footer>
    </div>

    @stack('modals')
    @livewireScripts
</body>
</html>
