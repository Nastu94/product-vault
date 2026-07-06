<form
    data-testid="product-case-request-draft-editor"
    wire:submit.prevent="saveRequestDraft"
    class="mt-6 rounded-lg border border-gray-200 bg-gray-50 p-5"
>
    <div>
        <label
            for="requestDraftBody"
            class="block text-sm font-semibold text-gray-900"
        >
            Testo della richiesta
        </label>

        <p class="mt-1 text-xs leading-5 text-gray-600">
            Il salvataggio manuale viene registrato nella timeline
            e protegge il testo dalla sovrascrittura automatica.
        </p>

        <textarea
            id="requestDraftBody"
            data-testid="product-case-request-draft-body"
            wire:model="requestDraftBody"
            rows="18"
            maxlength="{{ \App\Services\ProductCases\ProductCaseRequestDraftEditor::MAX_LENGTH }}"
            class="mt-4 block w-full rounded-md border-gray-300 font-mono text-sm leading-6 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
        ></textarea>

        @error('requestDraftBody')
            <p class="mt-2 text-xs text-red-600">
                {{ $message }}
            </p>
        @enderror
    </div>

    <div class="mt-5 flex flex-wrap justify-end gap-3">
        <button
            type="button"
            wire:click="cancelRequestDraftEdit"
            class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-white"
        >
            Annulla
        </button>

        <button
            type="submit"
            wire:loading.attr="disabled"
            wire:target="saveRequestDraft"
            class="rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800 disabled:cursor-not-allowed disabled:opacity-60"
        >
            Salva bozza
        </button>
    </div>
</form>