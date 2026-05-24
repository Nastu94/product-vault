{{-- resources/views/livewire/documents/document-index.blade.php --}}

<div class="py-8">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <div class="flex items-center justify-between mb-6">
            <div>
                <h1 class="text-2xl font-semibold text-gray-900">
                    Documenti
                </h1>

                <p class="mt-1 text-sm text-gray-600">
                    Qui troverai scontrini, fatture, garanzie, manuali e documenti collegati ai tuoi prodotti.
                </p>
            </div>

            {{-- Il link verrà attivato nello step successivo, quando creeremo DocumentUpload. --}}
            <button
                type="button"
                disabled
                class="inline-flex items-center px-4 py-2 bg-gray-300 border border-transparent rounded-md font-semibold text-xs text-gray-600 uppercase tracking-widest cursor-not-allowed"
            >
                Carica documento
            </button>
        </div>

        <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg">
            @if ($documents->isEmpty())
                <div class="p-8 text-center">
                    <h2 class="text-lg font-medium text-gray-900">
                        Nessun documento caricato
                    </h2>

                    <p class="mt-2 text-sm text-gray-600">
                        Quando caricherai il primo file, lo vedrai comparire qui.
                    </p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    File
                                </th>

                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Tipo
                                </th>

                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Stato
                                </th>

                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Venditore
                                </th>

                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Data acquisto
                                </th>

                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Totale
                                </th>

                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Caricato il
                                </th>
                            </tr>
                        </thead>

                        <tbody class="bg-white divide-y divide-gray-200">
                            @foreach ($documents as $document)
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                        {{ $document->original_filename ?? 'Documento #' . $document->id }}
                                    </td>

                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                        {{ $document->documentType?->name ?? 'Non classificato' }}
                                    </td>

                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                        {{ $document->status ?? 'uploaded' }}
                                    </td>

                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                        {{ $document->merchant?->name ?? '—' }}
                                    </td>

                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                        {{ $document->purchase_date?->format('d/m/Y') ?? '—' }}
                                    </td>

                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                        @if ($document->total_amount)
                                            {{ number_format($document->total_amount, 2, ',', '.') }}
                                            {{ $document->currency?->code }}
                                        @else
                                            —
                                        @endif
                                    </td>

                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                        {{ $document->created_at?->format('d/m/Y H:i') }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="p-4">
                    {{ $documents->links() }}
                </div>
            @endif
        </div>
    </div>
</div>