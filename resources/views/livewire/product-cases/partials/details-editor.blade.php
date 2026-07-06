<form
    data-testid="product-case-details-edit-form"
    wire:submit.prevent="saveDetails"
    class="mt-6 rounded-lg border border-gray-200 bg-gray-50 p-5"
>
    <div>
        <h3 class="text-sm font-semibold text-gray-900">
            Modifica i dati del problema
        </h3>

        <p class="mt-1 text-xs leading-5 text-gray-600">
            La descrizione originale registrata all’apertura viene
            conservata nello storico della pratica.
        </p>
    </div>

    <div class="mt-5 grid grid-cols-1 gap-5 sm:grid-cols-2">
        <div class="sm:col-span-2">
            <label
                for="detailsTitle"
                class="block text-xs font-medium uppercase tracking-wider text-gray-500"
            >
                Titolo
            </label>

            <input
                id="detailsTitle"
                type="text"
                maxlength="255"
                wire:model.blur="detailsTitle"
                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
            >

            @error('detailsTitle')
                <p class="mt-1 text-xs text-red-600">
                    {{ $message }}
                </p>
            @enderror
        </div>

        <div class="sm:col-span-2">
            <label
                for="detailsDescription"
                class="block text-xs font-medium uppercase tracking-wider text-gray-500"
            >
                Descrizione corrente
            </label>

            <textarea
                id="detailsDescription"
                rows="5"
                maxlength="20000"
                wire:model.blur="detailsDescription"
                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
            ></textarea>

            @error('detailsDescription')
                <p class="mt-1 text-xs text-red-600">
                    {{ $message }}
                </p>
            @enderror
        </div>

        <div>
            <label
                for="detailsOccurredOn"
                class="block text-xs font-medium uppercase tracking-wider text-gray-500"
            >
                Data del problema
            </label>

            <input
                id="detailsOccurredOn"
                type="date"
                max="{{ now()->toDateString() }}"
                wire:model="detailsOccurredOn"
                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
            >

            @error('detailsOccurredOn')
                <p class="mt-1 text-xs text-red-600">
                    {{ $message }}
                </p>
            @enderror
        </div>

        <div>
            <label
                for="detailsUsabilityStatus"
                class="block text-xs font-medium uppercase tracking-wider text-gray-500"
            >
                Utilizzabilità
            </label>

            <select
                id="detailsUsabilityStatus"
                wire:model="detailsUsabilityStatus"
                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
            >
                <option value="unknown">
                    Da verificare
                </option>

                <option value="usable">
                    Utilizzabile
                </option>

                <option value="partially_usable">
                    Parzialmente utilizzabile
                </option>

                <option value="unusable">
                    Non utilizzabile
                </option>
            </select>

            @error('detailsUsabilityStatus')
                <p class="mt-1 text-xs text-red-600">
                    {{ $message }}
                </p>
            @enderror
        </div>

        <div class="sm:col-span-2">
            <label
                for="detailsAccidentalDamageDeclared"
                class="block text-xs font-medium uppercase tracking-wider text-gray-500"
            >
                Possibile danno accidentale
            </label>

            <select
                id="detailsAccidentalDamageDeclared"
                wire:model.live="detailsAccidentalDamageDeclared"
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

            @error('detailsAccidentalDamageDeclared')
                <p class="mt-1 text-xs text-red-600">
                    {{ $message }}
                </p>
            @enderror
        </div>

        @if (
            $detailsAccidentalDamageDeclared
                === '1'
        )
            <div class="sm:col-span-2">
                <label
                    for="detailsAccidentalDamageNotes"
                    class="block text-xs font-medium uppercase tracking-wider text-gray-500"
                >
                    Note sul possibile danno
                </label>

                <textarea
                    id="detailsAccidentalDamageNotes"
                    rows="3"
                    maxlength="10000"
                    wire:model.blur="detailsAccidentalDamageNotes"
                    class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                ></textarea>

                @error('detailsAccidentalDamageNotes')
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
            wire:click="cancelDetailsEdit"
            class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-white"
        >
            Annulla
        </button>

        <button
            type="submit"
            wire:loading.attr="disabled"
            wire:target="saveDetails"
            class="rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800 disabled:cursor-not-allowed disabled:opacity-60"
        >
            Salva modifiche
        </button>
    </div>
</form>