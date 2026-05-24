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
            <a
                href="{{ route('documents.upload') }}"
                class="inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
            >
                Carica documento
            </a>
        </div>

        @if (session()->has('success'))
            <div class="mb-4 rounded-md bg-green-50 p-4 text-sm text-green-700">
                {{ session('success') }}
            </div>
        @endif

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

                                    <td class="px-6 py-4 whitespace-nowrap text-sm">
                                        @php
                                            $status = $document->status ?? 'uploaded';

                                            $statusLabels = [
                                                'uploaded' => 'Caricato',
                                                'processing' => 'In elaborazione',
                                                'text_extracted' => 'Testo estratto',
                                                'classified' => 'Classificato',
                                                'parsed' => 'Analizzato',
                                                'needs_review' => 'Da revisionare',
                                                'low_confidence' => 'Bassa affidabilità',
                                                'linked_to_product' => 'Collegato a prodotto',
                                                'unsupported' => 'Non supportato',
                                                'failed' => 'Fallito',
                                            ];

                                            $statusClasses = [
                                                'uploaded' => 'bg-blue-50 text-blue-700 ring-blue-600/20',
                                                'processing' => 'bg-yellow-50 text-yellow-800 ring-yellow-600/20',
                                                'text_extracted' => 'bg-indigo-50 text-indigo-700 ring-indigo-600/20',
                                                'classified' => 'bg-purple-50 text-purple-700 ring-purple-600/20',
                                                'parsed' => 'bg-cyan-50 text-cyan-700 ring-cyan-600/20',
                                                'needs_review' => 'bg-orange-50 text-orange-700 ring-orange-600/20',
                                                'low_confidence' => 'bg-amber-50 text-amber-800 ring-amber-600/20',
                                                'linked_to_product' => 'bg-green-50 text-green-700 ring-green-600/20',
                                                'unsupported' => 'bg-gray-100 text-gray-700 ring-gray-500/20',
                                                'failed' => 'bg-red-50 text-red-700 ring-red-600/20',
                                            ];

                                            $label = $statusLabels[$status] ?? ucfirst(str_replace('_', ' ', $status));
                                            $classes = $statusClasses[$status] ?? 'bg-gray-100 text-gray-700 ring-gray-500/20';
                                        @endphp

                                        <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset {{ $classes }}">
                                            {{ $label }}
                                        </span>
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