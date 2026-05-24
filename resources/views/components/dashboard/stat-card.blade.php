@props([
    'title',
    'value' => 0,
    'description' => null,
    'tone' => 'default',
])

@php
    /*
    |--------------------------------------------------------------------------
    | Dashboard Stat Card
    |--------------------------------------------------------------------------
    |
    | Componente riutilizzabile per mostrare una statistica sintetica
    | nella dashboard applicativa.
    |
    | tone:
    | - default: testo principale scuro
    | - warning: valore evidenziato in ambra
    | - success: valore evidenziato in verde
    | - danger: valore evidenziato in rosso
    |
    */

    $valueClasses = match ($tone) {
        'warning' => 'text-amber-600',
        'success' => 'text-emerald-600',
        'danger' => 'text-red-600',
        default => 'text-slate-950',
    };
@endphp

<div {{ $attributes->merge(['class' => 'rounded-2xl border border-slate-200 bg-white p-4 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md sm:p-5']) }}>
    <p class="text-sm font-medium text-slate-500">
        {{ $title }}
    </p>

    <p class="mt-2 text-2xl font-bold {{ $valueClasses }} sm:text-3xl">
        {{ $value }}
    </p>

    @if ($description)
        <p class="mt-1 text-xs leading-5 text-slate-500">
            {{ $description }}
        </p>
    @endif
</div>