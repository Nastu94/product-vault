<div>
    @if (
        $productCase->status
            === \App\Models\ProductCase::STATUS_READY_TO_CONTACT
        || $successMessage
        || $errorMessage
    )
        <div class="mx-auto max-w-7xl px-4 pt-6 sm:px-6 lg:px-8">
            <section
                data-testid="product-case-workflow-bar"
                class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-gray-200"
            >
                @if ($successMessage)
                    <div
                        data-testid="product-case-return-to-draft-success"
                        class="rounded-md bg-green-50 p-4 text-sm text-green-800 ring-1 ring-inset ring-green-600/20"
                    >
                        {{ $successMessage }}
                    </div>
                @endif

                @if ($errorMessage)
                    <div
                        data-testid="product-case-return-to-draft-error"
                        class="rounded-md bg-red-50 p-4 text-sm text-red-800 ring-1 ring-inset ring-red-600/20 {{ $successMessage ? 'mt-4' : '' }}"
                    >
                        {{ $errorMessage }}
                    </div>
                @endif

                @if (
                    $productCase->status
                        === \App\Models\ProductCase::STATUS_READY_TO_CONTACT
                )
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between {{ ($successMessage || $errorMessage) ? 'mt-5' : '' }}">
                        <div>
                            <h2 class="text-sm font-semibold text-gray-900">
                                Preparazione del contatto completata
                            </h2>

                            <p class="mt-1 text-sm leading-6 text-gray-600">
                                Puoi riportare la pratica in bozza per correggere dati,
                                evidenze o testo prima di registrare il contatto effettivo.
                            </p>
                        </div>

                        @can('update', $productCase)
                            <button
                                type="button"
                                data-testid="return-product-case-to-draft"
                                wire:click="returnToDraft"
                                wire:confirm="Confermi di voler riportare la pratica in bozza? I dati e le evidenze esistenti resteranno invariati."
                                wire:loading.attr="disabled"
                                wire:target="returnToDraft"
                                class="shrink-0 rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-60"
                            >
                                Torna alla bozza
                            </button>
                        @endcan
                    </div>
                @endif
            </section>
        </div>
    @endif
</div>
