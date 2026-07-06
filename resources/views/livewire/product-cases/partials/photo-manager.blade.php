<div
    data-testid="product-case-photo-manager"
    class="mt-5 rounded-lg border border-gray-200 bg-gray-50 p-5"
>
    <div>
        <h4 class="text-sm font-semibold text-gray-900">
            Aggiungi una fotografia
        </h4>

        <p class="mt-1 text-xs leading-5 text-gray-600">
            Sono ammesse immagini JPG, PNG e WEBP fino a 10 MB.
            Il file viene conservato nell’archivio privato della pratica.
        </p>
    </div>

    @if (
        count($issuePhotos)
            < \App\Services\ProductCases\ProductCasePhotoManager::MAX_PHOTOS
    )
        <form
            wire:submit.prevent="uploadPhoto"
            class="mt-5 space-y-4"
        >
            <div>
                <label
                    for="photoUpload"
                    class="block text-xs font-medium uppercase tracking-wider text-gray-500"
                >
                    Fotografia
                </label>

                <input
                    id="photoUpload"
                    type="file"
                    accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                    wire:model="photoUpload"
                    class="mt-1 block w-full text-sm text-gray-700 file:mr-4 file:rounded-md file:border-0 file:bg-gray-900 file:px-4 file:py-2 file:text-sm file:font-medium file:text-white hover:file:bg-gray-800"
                >

                <div
                    wire:loading
                    wire:target="photoUpload"
                    class="mt-2 text-xs text-gray-500"
                >
                    Caricamento temporaneo in corso…
                </div>

                @error('photoUpload')
                    <p class="mt-2 text-xs text-red-600">
                        {{ $message }}
                    </p>
                @enderror
            </div>

            <div class="flex flex-wrap justify-end gap-3">
                <button
                    type="button"
                    wire:click="cancelPhotoManagement"
                    class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-white"
                >
                    Chiudi
                </button>

                <button
                    type="submit"
                    wire:loading.attr="disabled"
                    wire:target="photoUpload,uploadPhoto"
                    class="rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800 disabled:cursor-not-allowed disabled:opacity-60"
                >
                    Aggiungi fotografia
                </button>
            </div>
        </form>
    @else
        <div class="mt-5 rounded-md bg-white p-4 text-sm text-gray-600 ring-1 ring-inset ring-gray-200">
            È stato raggiunto il limite massimo di
            {{ \App\Services\ProductCases\ProductCasePhotoManager::MAX_PHOTOS }}
            fotografie.
        </div>

        <div class="mt-4 flex justify-end">
            <button
                type="button"
                wire:click="cancelPhotoManagement"
                class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-white"
            >
                Chiudi
            </button>
        </div>
    @endif
</div>