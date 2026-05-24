{{-- resources/views/livewire/documents/document-upload.blade.php --}}

<div class="py-8">
    <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
        <div class="mb-6">
            <a
                href="{{ route('documents.index') }}"
                class="text-sm text-gray-600 hover:text-gray-900"
            >
                ← Torna ai documenti
            </a>

            <h1 class="mt-4 text-2xl font-semibold text-gray-900">
                Carica documento
            </h1>

            <p class="mt-1 text-sm text-gray-600">
                Puoi caricare PDF o immagini collegati a prodotti, garanzie, acquisti, barcode, seriali o assistenza.
            </p>
        </div>

        <div class="bg-white shadow-xl sm:rounded-lg">
            <div class="p-6">
                @if (session()->has('success'))
                    <div class="mb-4 rounded-md bg-green-50 p-4 text-sm text-green-700">
                        {{ session('success') }}
                    </div>
                @endif

                {{-- Form per il caricamento del file --}}
                <form wire:submit.prevent="store" class="space-y-6">
                    <div>
                        <label for="file" class="block text-sm font-medium text-gray-700">
                            File documento
                        </label>

                        <div class="mt-2">
                            <input
                                id="file"
                                type="file"
                                wire:model="file"
                                accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/jpeg,image/png,image/webp"
                                class="block w-full text-sm text-gray-700 border border-gray-300 rounded-md shadow-sm cursor-pointer focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                            >
                        </div>

                        <p class="mt-2 text-xs text-gray-500">
                            Formati ammessi: PDF, JPG, JPEG, PNG, WEBP. Dimensione massima: 10 MB.
                        </p>

                        @error('file')
                            <p class="mt-2 text-sm text-red-600">
                                {{ $message }}
                            </p>
                        @enderror
                    </div>

                    <div wire:loading wire:target="file" class="text-sm text-gray-600">
                        Caricamento temporaneo del file...
                    </div>

                    @if ($file)
                        <div class="rounded-md border border-gray-200 bg-gray-50 p-4">
                            <h2 class="text-sm font-medium text-gray-900">
                                File selezionato
                            </h2>

                            <div class="mt-3 flex items-start gap-4">
                                @if (str_starts_with($file->getMimeType(), 'image/'))
                                    <button
                                        type="button"
                                        wire:click="openPreview"
                                        class="group relative h-28 w-28 overflow-hidden rounded-lg border border-gray-300 bg-white shadow-sm"
                                        title="Apri anteprima"
                                    >
                                        <img
                                            src="{{ $file->temporaryUrl() }}"
                                            alt="Anteprima {{ $file->getClientOriginalName() }}"
                                            class="h-full w-full object-cover transition group-hover:scale-105"
                                        >

                                        <span class="absolute inset-x-0 bottom-0 bg-black/60 px-2 py-1 text-[11px] text-white">
                                            Apri
                                        </span>
                                    </button>
                                @else
                                    <div class="flex h-28 w-28 items-center justify-center rounded-lg border border-gray-300 bg-white text-xs font-semibold uppercase text-gray-500">
                                        PDF
                                    </div>
                                @endif

                                <div>
                                    <p class="text-sm text-gray-700">
                                        {{ $file->getClientOriginalName() }}
                                    </p>

                                    <p class="mt-1 text-xs text-gray-500">
                                        Dimensione:
                                        {{ number_format($file->getSize() / 1024 / 1024, 2, ',', '.') }} MB
                                    </p>

                                    <p class="mt-1 text-xs text-gray-500">
                                        Tipo:
                                        {{ $file->getMimeType() }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    @endif

                    @if ($file)
                        <div class="rounded-md border border-blue-200 bg-blue-50 p-4">
                            <p class="text-sm text-blue-700">
                                File accettato tecnicamente. Dopo il salvataggio verrà classificato come scontrino, fattura, manuale, foto prodotto o documento non riconosciuto.
                            </p>
                        </div>
                    @endif

                    <div class="flex items-center justify-end gap-3">
                        <a
                            href="{{ route('documents.index') }}"
                            class="inline-flex items-center px-4 py-2 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest shadow-sm hover:bg-gray-50"
                        >
                            Annulla
                        </a>

                        <button
                            type="submit"
                            wire:loading.attr="disabled"
                            wire:target="file,store"
                            class="inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700 disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            Salva documento
                        </button>
                    </div>
                </form>

                {{-- Preview a schermo intero per immagini --}}
                @if ($previewOpen && $file && str_starts_with($file->getMimeType(), 'image/'))
                    <div
                        class="fixed inset-0 z-50 flex items-center justify-center bg-black/80 p-4"
                        wire:click="closePreview"
                    >
                        <div class="relative max-h-full max-w-6xl" wire:click.stop>
                            <button
                                type="button"
                                wire:click="closePreview"
                                class="absolute -top-10 right-0 rounded-md bg-white px-3 py-1 text-sm font-medium text-gray-900 shadow"
                            >
                                Chiudi
                            </button>

                            <img
                                src="{{ $file->temporaryUrl() }}"
                                alt="Anteprima completa {{ $file->getClientOriginalName() }}"
                                class="max-h-[85vh] max-w-full rounded-lg bg-white object-contain shadow-2xl"
                            >
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>