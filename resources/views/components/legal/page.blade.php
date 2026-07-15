@props([
    'title',
    'description' => null,
])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} · {{ config('app.name', 'Product Vault') }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-50 text-slate-900 antialiased">
    <header class="border-b border-slate-200 bg-white">
        <div class="mx-auto flex max-w-5xl items-center justify-between gap-4 px-6 py-4">
            <a href="{{ route('welcome') }}" class="flex items-center gap-3 font-semibold text-slate-950">
                <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-slate-900 text-sm font-bold text-white">
                    PV
                </span>
                <span>Product Vault</span>
            </a>

            <div class="flex items-center gap-3">
                @auth
                    <a href="{{ route('dashboard') }}" class="text-sm font-semibold text-slate-700 hover:text-slate-950">
                        Dashboard
                    </a>
                @else
                    <a href="{{ route('login') }}" class="text-sm font-semibold text-slate-700 hover:text-slate-950">
                        Accedi
                    </a>
                @endauth
            </div>
        </div>
    </header>

    <main class="mx-auto max-w-5xl px-6 py-12 sm:py-16">
        <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-10">
            <p class="text-sm font-semibold uppercase tracking-wide text-slate-500">
                Informazioni legali e operative
            </p>

            <h1 class="mt-3 text-3xl font-bold tracking-tight text-slate-950 sm:text-4xl">
                {{ $title }}
            </h1>

            @if ($description)
                <p class="mt-4 max-w-3xl text-lg leading-8 text-slate-600">
                    {{ $description }}
                </p>
            @endif

            <p class="mt-4 text-sm text-slate-500">
                Data di efficacia:
                {{ config('release_readiness.legal.effective_date') }}
            </p>

            <div class="prose prose-slate mt-10 max-w-none">
                {{ $slot }}
            </div>
        </div>
    </main>

    <footer class="border-t border-slate-200 bg-white">
        <div class="mx-auto flex max-w-5xl flex-col gap-3 px-6 py-6 text-sm text-slate-500 sm:flex-row sm:items-center sm:justify-between">
            <p>© {{ date('Y') }} {{ config('app.name', 'Product Vault') }}</p>
            <div class="flex flex-wrap gap-4">
                <a href="{{ route('legal.privacy') }}" class="hover:text-slate-900">Privacy</a>
                <a href="{{ route('legal.terms') }}" class="hover:text-slate-900">Termini</a>
                <a href="{{ route('legal.document-processing') }}" class="hover:text-slate-900">Trattamento documenti</a>
            </div>
        </div>
    </footer>
</body>
</html>
