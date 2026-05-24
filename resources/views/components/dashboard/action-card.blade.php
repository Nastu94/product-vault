@props([
    'title',
    'description',
    'href' => null,
    'cta' => null,
    'status' => null,
    'icon' => 'upload',
    'tone' => 'default',
])

@php
    /*
    |--------------------------------------------------------------------------
    | Dashboard Action Card
    |--------------------------------------------------------------------------
    |
    | Card riutilizzabile per le azioni consigliate della dashboard.
    |
    | Se href è valorizzato, la card è cliccabile.
    | Se href è null, la card è informativa/disabilitata.
    |
    | icon:
    | - upload
    | - review
    | - warranty
    |
    | tone:
    | - default
    | - warning
    | - muted
    |
    */

    $isClickable = filled($href);

    $cardClasses = $isClickable
        ? 'group rounded-2xl border border-slate-200 bg-slate-50 p-6 transition hover:-translate-y-1 hover:border-slate-300 hover:bg-white hover:shadow-lg'
        : 'rounded-2xl border border-dashed border-slate-300 bg-white p-6 opacity-80';

    $iconClasses = match ($tone) {
        'warning' => 'bg-amber-100 text-amber-700',
        'muted' => 'bg-slate-100 text-slate-700',
        default => 'bg-slate-900 text-white',
    };
@endphp

@if ($isClickable)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $cardClasses]) }}>
@else
    <div {{ $attributes->merge(['class' => $cardClasses]) }}>
@endif
        <div class="flex h-12 w-12 items-center justify-center rounded-2xl {{ $iconClasses }}">
            @if ($icon === 'review')
                <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 11h6M9 15h4" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M7 3h7l4 4v14H7V3Z" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M14 3v5h5" />
                </svg>
            @elseif ($icon === 'warranty')
                <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 3 5 6v6c0 4.5 3 7.5 7 9 4-1.5 7-4.5 7-9V6l-7-3Z" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="m9 12 2 2 4-4" />
                </svg>
            @else
                <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14M5 12h14" />
                </svg>
            @endif
        </div>

        <h3 class="mt-5 text-lg font-semibold text-slate-950">
            {{ $title }}
        </h3>

        <p class="mt-3 text-sm leading-6 text-slate-600">
            {{ $description }}
        </p>

        @if ($cta)
            <p class="mt-5 text-sm font-semibold text-slate-900">
                {{ $cta }} →
            </p>
        @endif

        @if ($status)
            <p class="mt-5 text-sm font-semibold text-slate-400">
                {{ $status }}
            </p>
        @endif
@if ($isClickable)
    </a>
@else
    </div>
@endif