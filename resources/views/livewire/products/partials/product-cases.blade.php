<section
    data-testid="product-cases-section"
    class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-lg"
>
    <div class="p-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="text-lg font-medium text-gray-900">
                    Pratiche prodotto
                </h2>

                <p class="mt-1 text-sm text-gray-600">
                    Problemi segnalati e relativo stato operativo.
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <span class="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-700 ring-1 ring-inset ring-gray-500/20">
                    {{ count($productCases) }}
                </span>

                @can(
                    'create',
                    [
                        \App\Models\ProductCase::class,
                        $product,
                    ]
                )
                    @if (! $isCreatingProductCase)
                        <button
                            type="button"
                            data-testid="start-product-case"
                            wire:click="startProductCaseCreation"
                            class="rounded-md bg-gray-900 px-3 py-2 text-sm font-medium text-white hover:bg-gray-800"
                        >
                            Ho un problema
                        </button>
                    @endif
                @endcan
            </div>
        </div>

        @can(
            'create',
            [
                \App\Models\ProductCase::class,
                $product,
            ]
        )
            @if ($isCreatingProductCase)
                <form
                    data-testid="product-case-create-form"
                    wire:submit.prevent="createProductCase"
                    class="mt-6 rounded-lg border border-gray-200 bg-gray-50 p-5"
                >
                    <div>
                        <h3 class="text-sm font-semibold text-gray-900">
                            Descrivi il problema
                        </h3>

                        <p class="mt-1 text-xs leading-5 text-gray-600">
                            La pratica verrà salvata inizialmente come bozza.
                            Documenti, fotografie e testo della richiesta potranno
                            essere aggiunti nel dettaglio della pratica.
                        </p>
                    </div>

                    <div class="mt-5 grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <label
                                for="productCaseTitle"
                                class="block text-xs font-medium uppercase tracking-wider text-gray-500"
                            >
                                Titolo del problema
                            </label>

                            <input
                                id="productCaseTitle"
                                type="text"
                                maxlength="255"
                                wire:model.blur="productCaseTitle"
                                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                placeholder="Ad esempio: il prodotto non si accende"
                            >

                            @error('productCaseTitle')
                                <p class="mt-1 text-xs text-red-600">
                                    {{ $message }}
                                </p>
                            @enderror
                        </div>

                        <div class="sm:col-span-2">
                            <label
                                for="productCaseDescription"
                                class="block text-xs font-medium uppercase tracking-wider text-gray-500"
                            >
                                Descrizione
                            </label>

                            <textarea
                                id="productCaseDescription"
                                rows="5"
                                maxlength="20000"
                                wire:model.blur="productCaseDescription"
                                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                placeholder="Descrivi cosa è successo e quali sintomi presenta il prodotto..."
                            ></textarea>

                            @error('productCaseDescription')
                                <p class="mt-1 text-xs text-red-600">
                                    {{ $message }}
                                </p>
                            @enderror
                        </div>

                        <div>
                            <label
                                for="productCaseOccurredOn"
                                class="block text-xs font-medium uppercase tracking-wider text-gray-500"
                            >
                                Data del problema
                            </label>

                            <input
                                id="productCaseOccurredOn"
                                type="date"
                                max="{{ now()->toDateString() }}"
                                wire:model="productCaseOccurredOn"
                                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                            >

                            @error('productCaseOccurredOn')
                                <p class="mt-1 text-xs text-red-600">
                                    {{ $message }}
                                </p>
                            @enderror
                        </div>

                        <div>
                            <label
                                for="productCaseUsabilityStatus"
                                class="block text-xs font-medium uppercase tracking-wider text-gray-500"
                            >
                                Il prodotto è utilizzabile?
                            </label>

                            <select
                                id="productCaseUsabilityStatus"
                                wire:model="productCaseUsabilityStatus"
                                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                            >
                                <option value="unknown">
                                    Da verificare
                                </option>

                                <option value="usable">
                                    Sì, è utilizzabile
                                </option>

                                <option value="partially_usable">
                                    Solo parzialmente
                                </option>

                                <option value="unusable">
                                    No, non è utilizzabile
                                </option>
                            </select>

                            @error('productCaseUsabilityStatus')
                                <p class="mt-1 text-xs text-red-600">
                                    {{ $message }}
                                </p>
                            @enderror
                        </div>

                        <div class="sm:col-span-2">
                            <label
                                for="productCaseAccidentalDamageDeclared"
                                class="block text-xs font-medium uppercase tracking-wider text-gray-500"
                            >
                                Potrebbe esserci stato un danno accidentale?
                            </label>

                            <select
                                id="productCaseAccidentalDamageDeclared"
                                wire:model.live="productCaseAccidentalDamageDeclared"
                                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                            >
                                <option value="">
                                    Non specificato
                                </option>

                                <option value="0">
                                    No
                                </option>

                                <option value="1">
                                    Sì
                                </option>
                            </select>

                            @error('productCaseAccidentalDamageDeclared')
                                <p class="mt-1 text-xs text-red-600">
                                    {{ $message }}
                                </p>
                            @enderror
                        </div>

                        @if (
                            $productCaseAccidentalDamageDeclared
                                === '1'
                        )
                            <div class="sm:col-span-2">
                                <label
                                    for="productCaseAccidentalDamageNotes"
                                    class="block text-xs font-medium uppercase tracking-wider text-gray-500"
                                >
                                    Descrizione del possibile danno accidentale
                                </label>

                                <textarea
                                    id="productCaseAccidentalDamageNotes"
                                    rows="3"
                                    maxlength="10000"
                                    wire:model.blur="productCaseAccidentalDamageNotes"
                                    class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                    placeholder="Descrivi l’evento accidentale o il possibile danno..."
                                ></textarea>

                                @error('productCaseAccidentalDamageNotes')
                                    <p class="mt-1 text-xs text-red-600">
                                        {{ $message }}
                                    </p>
                                @enderror
                            </div>
                        @endif
                    </div>

                    <div class="mt-6 flex flex-wrap justify-end gap-3">
                        <button
                            type="button"
                            wire:click="cancelProductCaseCreation"
                            class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-white"
                        >
                            Annulla
                        </button>

                        <button
                            type="submit"
                            wire:loading.attr="disabled"
                            wire:target="createProductCase"
                            class="rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800 disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            Salva pratica
                        </button>
                    </div>
                </form>
            @endif
        @endcan

        @if ($productCases !== [])
            <div class="mt-5 space-y-3">
                @foreach ($productCases as $productCaseSummary)
                    <a
                        data-testid="product-case-link-{{ $productCaseSummary['id'] }}"
                        href="{{ route(
                            'product-cases.show',
                            [
                                'productCase' =>
                                    $productCaseSummary['id'],
                            ]
                        ) }}"
                        class="block rounded-md border border-gray-200 p-4 transition hover:border-gray-300 hover:bg-gray-50"
                    >
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="text-sm font-medium text-gray-900">
                                    {{ $productCaseSummary['title'] }}
                                </div>

                                <div class="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-xs text-gray-500">
                                    <span>
                                        Aperta:
                                        {{ $productCaseSummary['opened_at_label'] }}
                                    </span>

                                    <span>
                                        Problema:
                                        {{ $productCaseSummary['occurred_on_label'] }}
                                    </span>

                                    <span>
                                        Da:
                                        {{ $productCaseSummary['opened_by_name'] }}
                                    </span>
                                </div>
                            </div>

                            <span class="inline-flex shrink-0 items-center rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset {{ $productCaseSummary['status_badge_classes'] }}">
                                {{ $productCaseSummary['status_label'] }}
                            </span>
                        </div>
                    </a>
                @endforeach
            </div>
        @else
            <div class="mt-5 rounded-md bg-gray-50 p-4">
                <p class="text-sm text-gray-600">
                    Nessuna pratica associata a questo prodotto.
                </p>
            </div>
        @endif
    </div>
</section>