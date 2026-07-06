<div
    data-testid="product-case-document-manager"
    class="mt-5 rounded-lg border border-gray-200 bg-gray-50 p-5"
>
    <div>
        <h4 class="text-sm font-semibold text-gray-900">
            Aggiungi un documento
        </h4>

        <p class="mt-1 text-xs leading-5 text-gray-600">
            Sono disponibili soltanto i documenti già collegati
            alla scheda del prodotto.
        </p>
    </div>

    @if ($selectableDocuments !== [])
        <form
            wire:submit.prevent="selectDocument"
            class="mt-5 space-y-4"
        >
            <div>
                <label
                    for="documentToSelectId"
                    class="block text-xs font-medium uppercase tracking-wider text-gray-500"
                >
                    Documento
                </label>

                <select
                    id="documentToSelectId"
                    wire:model="documentToSelectId"
                    class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                >
                    <option value="">
                        Seleziona un documento
                    </option>

                    @foreach ($selectableDocuments as $document)
                        <option value="{{ $document['id'] }}">
                            {{ $document['original_filename'] }}
                            — {{ $document['document_type'] }}
                        </option>
                    @endforeach
                </select>

                @error('documentToSelectId')
                    <p class="mt-1 text-xs text-red-600">
                        {{ $message }}
                    </p>
                @enderror
            </div>

            <div>
                <label
                    for="documentSelectionNotes"
                    class="block text-xs font-medium uppercase tracking-wider text-gray-500"
                >
                    Nota facoltativa
                </label>

                <textarea
                    id="documentSelectionNotes"
                    rows="3"
                    maxlength="10000"
                    wire:model.blur="documentSelectionNotes"
                    class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                ></textarea>

                @error('documentSelectionNotes')
                    <p class="mt-1 text-xs text-red-600">
                        {{ $message }}
                    </p>
                @enderror
            </div>

            <div class="flex flex-wrap justify-end gap-3">
                <button
                    type="button"
                    wire:click="cancelDocumentManagement"
                    class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-white"
                >
                    Chiudi
                </button>

                <button
                    type="submit"
                    wire:loading.attr="disabled"
                    wire:target="selectDocument"
                    class="rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800 disabled:cursor-not-allowed disabled:opacity-60"
                >
                    Aggiungi documento
                </button>
            </div>
        </form>
    @else
        <div class="mt-5 rounded-md bg-white p-4 text-sm text-gray-600 ring-1 ring-inset ring-gray-200">
            Tutti i documenti collegati al prodotto sono già
            selezionati nella pratica.
        </div>

        <div class="mt-4 flex justify-end">
            <button
                type="button"
                wire:click="cancelDocumentManagement"
                class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-white"
            >
                Chiudi
            </button>
        </div>
    @endif
</div>