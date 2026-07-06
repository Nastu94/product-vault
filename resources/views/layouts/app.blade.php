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

            {{ $slot }}

            @if (request()->routeIs('dashboard'))
                <div
                    id="dashboard-product-case-centers"
                    class="hidden grid grid-cols-1 gap-6 xl:grid-cols-2"
                >
                    @livewire(
                        'dashboard.dashboard-action-center',
                        [],
                        key('dashboard-action-center')
                    )

                    @livewire(
                        'dashboard.dashboard-results-center',
                        [],
                        key('dashboard-results-center')
                    )

                    @livewire(
                        'dashboard.dashboard-completion-center',
                        [],
                        key('dashboard-completion-center')
                    )

                    @livewire(
                        'dashboard.dashboard-expiry-center',
                        [],
                        key('dashboard-expiry-center')
                    )
                </div>

                <script>
                    (() => {
                        const centers = document.getElementById(
                            'dashboard-product-case-centers'
                        );

                        const dashboardContainer = document.querySelector(
                            'main > div.py-6 > div.mx-auto.max-w-7xl'
                        );

                        const introSection = dashboardContainer
                            ? Array.from(dashboardContainer.children)
                                .find((element) => element.tagName === 'SECTION')
                            : null;

                        if (! centers || ! dashboardContainer || ! introSection) {
                            return;
                        }

                        introSection.insertAdjacentElement(
                            'afterend',
                            centers
                        );

                        centers.classList.remove('hidden');
                    })();
                </script>
            @endif
        </main>
    </div>

    @stack('modals')
    @livewireScripts
</body>
</html>
