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
                        data-testid="product-case-workflow-bar-success"
                        class="rounded-md bg-green-50 p-4 text-sm text-green-800 ring-1 ring-inset ring-green-600/20"
                    >
                        {{ $successMessage }}
                    </div>
                @endif

                @if ($errorMessage)
                    <div
                        data-testid="product-case-workflow-bar-error"
                        class="rounded-md bg-red-50 p-4 text-sm text-red-800 ring-1 ring-inset ring-red-600/20 {{ $successMessage ? 'mt-4' : '' }}"
                    >
                        {{ $errorMessage }}
                    </div>
                @endif

                @if (
                    $productCase->status
                        === \App\Models\ProductCase::STATUS_READY_TO_CONTACT
                )
                    <div class="flex flex-col gap-4 {{ ($successMessage || $errorMessage) ? 'mt-5' : '' }}">
                        <div>
                            <h2 class="text-sm font-semibold text-gray-900">
                                Preparazione del contatto completata
                            </h2>

                            <p class="mt-1 text-sm leading-6 text-gray-600">
                                Registra il contatto soltanto dopo averlo effettuato realmente.
                                Product Vault non invierà messaggi e non contatterà servizi esterni.
                            </p>
                        </div>

                        @can('update', $productCase)
                            <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                                <button
                                    type="button"
                                    data-testid="return-product-case-to-draft"
                                    wire:click="returnToDraft"
                                    wire:confirm="Confermi di voler riportare la pratica in bozza? I dati e le evidenze esistenti resteranno invariati."
                                    wire:loading.attr="disabled"
                                    wire:target="returnToDraft,markContacted"
                                    class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    Torna alla bozza
                                </button>

                                <button
                                    type="button"
                                    data-testid="mark-product-case-contacted"
                                    wire:click="markContacted"
                                    wire:confirm="Confermi che il contatto con venditore o assistenza è stato realmente effettuato? Questa azione registra il momento del contatto ma non invia alcun messaggio."
                                    wire:loading.attr="disabled"
                                    wire:target="returnToDraft,markContacted"
                                    class="rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800 disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    Registra contatto effettuato
                                </button>
                            </div>
                        @endcan
                    </div>
                @endif
            </section>
        </div>
    @endif
</div>
