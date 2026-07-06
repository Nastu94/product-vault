<div>
    @if (
        in_array(
            $productCase->status,
            [
                \App\Models\ProductCase::STATUS_DRAFT,
                \App\Models\ProductCase::STATUS_READY_TO_CONTACT,
                \App\Models\ProductCase::STATUS_CONTACTED,
            ],
            true
        )
    )
        <div class="mx-auto max-w-7xl px-4 pt-4 sm:px-6 lg:px-8">
            <section
                data-testid="product-case-stop-bar"
                class="rounded-lg border border-red-200 bg-red-50 p-5"
            >
                <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h2 class="text-sm font-semibold text-red-900">
                            Interrompi la pratica
                        </h2>

                        <p class="mt-1 text-sm leading-6 text-red-800">
                            Usa questa azione soltanto quando non intendi più proseguire.
                            Documenti, fotografie, bozza e storico resteranno conservati.
                        </p>
                    </div>

                    @can('update', $productCase)
                        <button
                            type="button"
                            data-testid="stop-product-case"
                            wire:click="stopWorkflow"
                            wire:confirm="Confermi di voler annullare la pratica? L’operazione è definitiva e lo storico resterà disponibile."
                            wire:loading.attr="disabled"
                            wire:target="stopWorkflow"
                            class="shrink-0 rounded-md border border-red-300 bg-white px-4 py-2 text-sm font-medium text-red-700 hover:bg-red-100 disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            Annulla pratica
                        </button>
                    @endcan
                </div>
            </section>
        </div>
    @endif
</div>
