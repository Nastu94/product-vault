@props([
    'id',
    'title' => 'Dettagli',
    'description' => null,
    'width' => 'max-w-2xl',
])

<div
    x-data="{ open: false }"
    x-on:open-drawer.window="if ($event.detail.id === '{{ $id }}') open = true"
    x-on:keydown.escape.window="open = false"
    x-show="open"
    x-cloak
    class="relative z-50"
    role="dialog"
    aria-modal="true"
    aria-labelledby="{{ $id }}-title"
>
    <div
        x-show="open"
        x-transition.opacity
        class="fixed inset-0 bg-gray-900/40"
        x-on:click="open = false"
    ></div>

    <div class="fixed inset-0 overflow-hidden">
        <div class="absolute inset-0 overflow-hidden">
            <div class="pointer-events-none fixed inset-y-0 right-0 flex max-w-full pl-6 sm:pl-10">
                <section
                    x-show="open"
                    x-transition:enter="transform transition ease-in-out duration-300"
                    x-transition:enter-start="translate-x-full"
                    x-transition:enter-end="translate-x-0"
                    x-transition:leave="transform transition ease-in-out duration-300"
                    x-transition:leave-start="translate-x-0"
                    x-transition:leave-end="translate-x-full"
                    class="pointer-events-auto w-screen {{ $width }}"
                >
                    <div class="flex h-full flex-col bg-white shadow-xl">
                        <div class="border-b border-gray-200 px-6 py-4">
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <h2
                                        id="{{ $id }}-title"
                                        class="text-base font-semibold text-gray-900"
                                    >
                                        {{ $title }}
                                    </h2>

                                    @if ($description)
                                        <p class="mt-1 text-sm text-gray-600">
                                            {{ $description }}
                                        </p>
                                    @endif
                                </div>

                                <button
                                    type="button"
                                    x-on:click="open = false"
                                    class="rounded-md text-gray-400 hover:text-gray-600 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                >
                                    <span class="sr-only">Chiudi pannello</span>
                                    <svg
                                        class="h-5 w-5"
                                        viewBox="0 0 20 20"
                                        fill="currentColor"
                                        aria-hidden="true"
                                    >
                                        <path
                                            fill-rule="evenodd"
                                            d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z"
                                            clip-rule="evenodd"
                                        />
                                    </svg>
                                </button>
                            </div>
                        </div>

                        <div class="flex-1 overflow-y-auto px-6 py-5">
                            {{ $slot }}
                        </div>

                        @isset($footer)
                            <div class="border-t border-gray-200 bg-gray-50 px-6 py-4">
                                {{ $footer }}
                            </div>
                        @endisset
                    </div>
                </section>
            </div>
        </div>
    </div>
</div>