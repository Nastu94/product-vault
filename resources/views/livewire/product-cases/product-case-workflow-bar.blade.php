@php
    $isResolving = $isResolving ?? false;
    $resolutionOutcome = $resolutionOutcome ?? '';
    $resolutionNotes = $resolutionNotes ?? null;
@endphp

<div>
    @if (
        in_array(
            $productCase->status,
            [
                \App\Models\ProductCase::STATUS_READY_TO_CONTACT,
                \App\Models\ProductCase::STATUS_CONTACTED,
                \App\Models\ProductCase::STATUS_RESOLVED,
            ],
            true
        )
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
                @elseif (
                    $productCase->status
                        === \App\Models\ProductCase::STATUS_CONTACTED
                )
                    <div class="{{ ($successMessage || $errorMessage) ? 'mt-5' : '' }}">
                        @if (! $isResolving)
                            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    <h2 class="text-sm font-semibold text-gray-900">
                                        Contatto registrato
                                    </h2>

                                    <p class="mt-1 text-sm leading-6 text-gray-600">
                                        Quando conosci l’esito effettivo, registralo per completare
                                        la fase di gestione della pratica.
                                    </p>
                                </div>

                                @can('update', $productCase)
                                    <button
                                        type="button"
                                        data-testid="start-product-case-resolution"
                                        wire:click="startResolution"
                                        wire:loading.attr="disabled"
                                        wire:target="startResolution"
                                        class="shrink-0 rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800 disabled:cursor-not-allowed disabled:opacity-60"
                                    >
                                        Registra esito
                                    </button>
                                @endcan
                            </div>
                        @else
                            <form
                                data-testid="product-case-resolution-form"
                                wire:submit.prevent="resolveProductCase"
                                class="space-y-5"
                            >
                                <div>
                                    <h2 class="text-sm font-semibold text-gray-900">
                                        Registra l’esito della pratica
                                    </h2>

                                    <p class="mt-1 text-sm leading-6 text-gray-600">
                                        L’esito è obbligatorio. Le note sono facoltative e possono
                                        contenere i dettagli comunicati dal venditore o dall’assistenza.
                                    </p>
                                </div>

                                <div>
                                    <label
                                        for="resolutionOutcome"
                                        class="block text-sm font-medium text-gray-700"
                                    >
                                        Esito
                                    </label>

                                    <select
                                        id="resolutionOutcome"
                                        data-testid="product-case-resolution-outcome"
                                        wire:model="resolutionOutcome"
                                        class="mt-2 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                    >
                                        <option value="">Seleziona un esito</option>
                                        <option value="repaired">Prodotto riparato</option>
                                        <option value="replaced">Prodotto sostituito</option>
                                        <option value="refunded">Importo rimborsato</option>
                                        <option value="rejected">Richiesta respinta</option>
                                        <option value="abandoned">Procedura abbandonata</option>
                                        <option value="other">Altro esito</option>
                                    </select>

                                    @error('resolutionOutcome')
                                        <p class="mt-2 text-xs text-red-600">
                                            {{ $message }}
                                        </p>
                                    @enderror
                                </div>

                                <div>
                                    <label
                                        for="resolutionNotes"
                                        class="block text-sm font-medium text-gray-700"
                                    >
                                        Note di risoluzione
                                    </label>

                                    <textarea
                                        id="resolutionNotes"
                                        data-testid="product-case-resolution-notes"
                                        wire:model="resolutionNotes"
                                        rows="5"
                                        maxlength="20000"
                                        class="mt-2 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                    ></textarea>

                                    @error('resolutionNotes')
                                        <p class="mt-2 text-xs text-red-600">
                                            {{ $message }}
                                        </p>
                                    @enderror
                                </div>

                                <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                                    <button
                                        type="button"
                                        wire:click="cancelResolution"
                                        wire:loading.attr="disabled"
                                        wire:target="cancelResolution,resolveProductCase"
                                        class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-60"
                                    >
                                        Annulla
                                    </button>

                                    <button
                                        type="submit"
                                        data-testid="resolve-product-case"
                                        wire:loading.attr="disabled"
                                        wire:target="cancelResolution,resolveProductCase"
                                        class="rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800 disabled:cursor-not-allowed disabled:opacity-60"
                                    >
                                        Segna come risolta
                                    </button>
                                </div>
                            </form>
                        @endif
                    </div>
                @elseif (
                    $productCase->status
                        === \App\Models\ProductCase::STATUS_RESOLVED
                )
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between {{ ($successMessage || $errorMessage) ? 'mt-5' : '' }}">
                        <div>
                            <h2 class="text-sm font-semibold text-gray-900">
                                Pratica risolta
                            </h2>

                            <p class="mt-1 text-sm leading-6 text-gray-600">
                                L’esito è stato registrato. Chiudi la pratica soltanto quando
                                non sono previste altre attività operative.
                            </p>
                        </div>

                        @can('update', $productCase)
                            <button
                                type="button"
                                data-testid="close-product-case"
                                wire:click="closeProductCase"
                                wire:confirm="Confermi di voler chiudere definitivamente la pratica? Dopo la chiusura non saranno disponibili altre transizioni."
                                wire:loading.attr="disabled"
                                wire:target="closeProductCase"
                                class="shrink-0 rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800 disabled:cursor-not-allowed disabled:opacity-60"
                            >
                                Chiudi pratica
                            </button>
                        @endcan
                    </div>
                @endif
            </section>
        </div>
    @endif
</div>
