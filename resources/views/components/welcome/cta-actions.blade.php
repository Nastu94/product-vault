@props([
    'context' => 'hero',
])

@php
    /*
    |--------------------------------------------------------------------------
    | Componente CTA per la welcome page
    |--------------------------------------------------------------------------
    |
    | Questo componente gestisce i pulsanti principali della welcome page.
    | Contesti supportati:
    | - header: pulsanti desktop nella navbar
    | - mobile: pulsanti nel menu hamburger
    | - hero: pulsanti principali della hero
    | - final: pulsanti centrati nella CTA finale
    |
    */

    $dashboardUrl = Route::has('dashboard') ? route('dashboard') : url('/dashboard');

    $wrapperClasses = match ($context) {
        'header' => 'hidden items-center gap-3 md:flex',
        'mobile' => 'flex flex-col gap-3',
        'final' => 'mt-8 flex flex-col justify-center gap-3 sm:flex-row',
        default => 'mt-8 flex flex-col gap-3 sm:flex-row',
    };

    $primaryClasses = match ($context) {
        'header' => 'rounded-xl bg-slate-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-700',
        'mobile' => 'inline-flex w-full items-center justify-center rounded-xl bg-slate-900 px-4 py-3 text-sm font-semibold text-white transition hover:bg-slate-700',
        default => 'inline-flex items-center justify-center rounded-xl bg-slate-900 px-6 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-700',
    };

    $secondaryClasses = match ($context) {
        'header' => 'text-sm font-medium text-slate-700 transition hover:text-slate-950',
        'mobile' => 'inline-flex w-full items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm font-semibold text-slate-800 transition hover:bg-slate-100',
        default => 'inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-6 py-3 text-sm font-semibold text-slate-800 shadow-sm transition hover:bg-slate-100',
    };
@endphp

@if ($context === 'header')
    <nav class="{{ $wrapperClasses }}" aria-label="Navigazione principale">
        @auth
            <a href="{{ $dashboardUrl }}" class="{{ $primaryClasses }}">
                Vai alla dashboard
            </a>
        @else
            @if (Route::has('login'))
                <a href="{{ route('login') }}" class="{{ $secondaryClasses }}">
                    Accedi
                </a>
            @endif

            @if (Route::has('register'))
                <a href="{{ route('register') }}" class="{{ $primaryClasses }}">
                    Inizia ora
                </a>
            @endif
        @endauth
    </nav>
@else
    <div class="{{ $wrapperClasses }}">
        @auth
            <a href="{{ $dashboardUrl }}" class="{{ $primaryClasses }}">
                Vai alla dashboard
            </a>
        @else
            @if (Route::has('register'))
                <a href="{{ route('register') }}" class="{{ $primaryClasses }}">
                    Crea il tuo account
                </a>
            @endif

            @if (Route::has('login'))
                <a href="{{ route('login') }}" class="{{ $secondaryClasses }}">
                    {{ $context === 'final' ? 'Accedi' : 'Ho già un account' }}
                </a>
            @endif
        @endauth
    </div>
@endif