@props([
    'context' => 'desktop',
])

@php
    $links = [
        [
            'label' => 'Come funziona',
            'href' => '#come-funziona',
        ],
        [
            'label' => 'Benefici',
            'href' => '#benefici',
        ],
        [
            'label' => 'Piani',
            'href' => '#piani',
        ],
        [
            'label' => 'Sicurezza',
            'href' => '#sicurezza',
        ],
    ];

    $wrapperClasses = $context === 'mobile'
        ? 'flex flex-col gap-1'
        : 'hidden items-center gap-6 lg:flex';

    $linkClasses = $context === 'mobile'
        ? 'rounded-xl px-3 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50 hover:text-slate-950'
        : 'text-sm font-medium text-slate-600 transition hover:text-slate-950';
@endphp

<nav class="{{ $wrapperClasses }}" aria-label="Sezioni della pagina">
    @foreach ($links as $link)
        <a href="{{ $link['href'] }}" class="{{ $linkClasses }}">
            {{ $link['label'] }}
        </a>
    @endforeach
</nav>
