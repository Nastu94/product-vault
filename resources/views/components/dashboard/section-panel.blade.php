@props([
    'title',
    'description' => null,
    'href' => null,
    'linkLabel' => 'Vedi tutti',
    'emptyTitle' => null,
    'emptyMessage' => null,
])

<div {{ $attributes->merge(['class' => 'rounded-3xl border border-slate-200 bg-white p-6 shadow-sm']) }}>
    <div class="flex items-start justify-between gap-4">
        <div>
            <h2 class="text-lg font-bold text-slate-950">
                {{ $title }}
            </h2>

            @if ($description)
                <p class="mt-1 text-sm text-slate-500">
                    {{ $description }}
                </p>
            @endif
        </div>

        @if ($href)
            <a
                href="{{ $href }}"
                class="shrink-0 text-sm font-semibold text-slate-700 transition hover:text-slate-950"
            >
                {{ $linkLabel }}
            </a>
        @endif
    </div>

    <div class="mt-6">
        @if ($slot->isNotEmpty())
            {{ $slot }}
        @else
            <div class="rounded-2xl bg-slate-50 p-5">
                @if ($emptyTitle)
                    <p class="text-sm font-semibold text-slate-800">
                        {{ $emptyTitle }}
                    </p>
                @endif

                @if ($emptyMessage)
                    <p class="{{ $emptyTitle ? 'mt-1' : '' }} text-sm leading-6 text-slate-500">
                        {{ $emptyMessage }}
                    </p>
                @else
                    <p class="text-sm leading-6 text-slate-500">
                        Nessun elemento da mostrare.
                    </p>
                @endif
            </div>
        @endif
    </div>
</div>