<section
    data-testid="product-cases-section"
    class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-lg"
>
    <div class="p-6">
        <div class="flex items-start justify-between gap-3">
            <div>
                <h2 class="text-lg font-medium text-gray-900">
                    Pratiche prodotto
                </h2>

                <p class="mt-1 text-sm text-gray-600">
                    Problemi segnalati e relativo stato operativo.
                </p>
            </div>

            <span class="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-700 ring-1 ring-inset ring-gray-500/20">
                {{ count($productCases) }}
            </span>
        </div>

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