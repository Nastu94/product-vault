{{-- resources/views/livewire/products/product-show.blade.php --}}

<div class="py-8">
    <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
        <div class="mb-6">
            <a
                href="{{ route('products.index') }}"
                class="text-sm text-gray-600 hover:text-gray-900"
            >
                ← Torna ai prodotti
            </a>

            <div class="mt-4 flex flex-wrap items-center gap-3">
                <h1 class="text-2xl font-semibold text-gray-900">
                    {{ $product->name }}
                </h1>

                @if ($product->reliability_score !== null)
                    <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset {{ $this->reliabilityBadgeClasses }}">
                        Affidabilità {{ $product->reliability_score }}/100
                    </span>
                @endif
            </div>

            <p class="mt-2 text-sm text-gray-600">
                Scheda prodotto creata da documento e confermata dall’utente.
            </p>
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <div class="space-y-6 lg:col-span-2">
                <section class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-lg">
                    <div class="p-6">
                        <h2 class="text-lg font-medium text-gray-900">
                            Dati prodotto
                        </h2>

                        <dl class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                    Nome
                                </dt>
                                <dd class="mt-1 text-sm text-gray-900">
                                    {{ $product->name }}
                                </dd>
                            </div>

                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                    Modello
                                </dt>
                                <dd class="mt-1 text-sm text-gray-900">
                                    {{ $product->model ?? '—' }}
                                </dd>
                            </div>

                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                    EAN
                                </dt>
                                <dd class="mt-1 text-sm text-gray-900">
                                    {{ $product->ean_code ?? '—' }}
                                </dd>
                            </div>

                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                    Numero seriale
                                </dt>
                                <dd class="mt-1 text-sm text-gray-900">
                                    {{ $product->serial_number ?? '—' }}
                                </dd>
                            </div>

                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                    Stato identificazione
                                </dt>
                                <dd class="mt-1 text-sm text-gray-900">
                                    {{ $this->identificationStatusLabel }}
                                </dd>
                            </div>

                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                    Creato da
                                </dt>
                                <dd class="mt-1 text-sm text-gray-900">
                                    {{ $product->createdBy?->name ?? '—' }}
                                </dd>
                            </div>
                        </dl>
                    </div>
                </section>

                <section class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-lg">
                    <div class="p-6">
                        <h2 class="text-lg font-medium text-gray-900">
                            Acquisto
                        </h2>

                        <dl class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                    Data acquisto
                                </dt>
                                <dd class="mt-1 text-sm text-gray-900">
                                    {{ $product->purchase_date?->format('d/m/Y') ?? '—' }}
                                </dd>
                            </div>

                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                    Prezzo unitario
                                </dt>
                                <dd class="mt-1 text-sm text-gray-900">
                                    @if ($product->purchase_price)
                                        {{ number_format($product->purchase_price, 2, ',', '.') }}
                                        {{ $product->currency?->code }}
                                    @else
                                        —
                                    @endif
                                </dd>
                            </div>

                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                    Venditore
                                </dt>
                                <dd class="mt-1 text-sm text-gray-900">
                                    {{ $product->merchant?->name ?? '—' }}
                                </dd>
                            </div>

                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                    Valuta
                                </dt>
                                <dd class="mt-1 text-sm text-gray-900">
                                    {{ $product->currency?->code ?? '—' }}
                                </dd>
                            </div>
                        </dl>
                    </div>
                </section>

                <section class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-lg">
                    <div class="p-6">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h2 class="text-lg font-medium text-gray-900">
                                    Garanzia
                                </h2>

                                <p class="mt-1 text-sm text-gray-600">
                                    Garanzia stimata in base alla data di acquisto e alle regole configurate.
                                </p>
                            </div>

                            <div class="flex items-center gap-2">
                                <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset {{ $this->warrantyStatusBadgeClasses }}">
                                    {{ $this->warrantyStatusLabel }}
                                </span>

                                @if ($this->primaryWarranty && ! $isEditingWarranty)
                                    <button
                                        type="button"
                                        wire:click="editWarranty"
                                        class="rounded-md border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50"
                                    >
                                        Modifica
                                    </button>
                                @endif
                            </div>
                        </div>

                        @if (session('status'))
                            <div class="mt-4 rounded-md bg-green-50 p-3 text-sm text-green-700">
                                {{ session('status') }}
                            </div>
                        @endif

                        @error('warranty')
                            <div class="mt-4 rounded-md bg-red-50 p-3 text-sm text-red-700">
                                {{ $message }}
                            </div>
                        @enderror

                        @if ($this->primaryWarranty)

                            <dl class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div>
                                    <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Tipo
                                    </dt>
                                    <dd class="mt-1 text-sm text-gray-900">
                                        {{ $this->primaryWarranty->warrantyType?->name ?? '—' }}
                                    </dd>
                                </div>

                                <div>
                                    <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Durata
                                    </dt>
                                    <dd class="mt-1 text-sm text-gray-900">
                                        @if ($this->primaryWarranty->duration_months)
                                            {{ $this->primaryWarranty->duration_months }} mesi
                                        @else
                                            —
                                        @endif
                                    </dd>
                                </div>

                                <div>
                                    <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Inizio
                                    </dt>
                                    <dd class="mt-1 text-sm text-gray-900">
                                        {{ $this->primaryWarranty->starts_at?->format('d/m/Y') ?? '—' }}
                                    </dd>
                                </div>

                                <div>
                                    <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Scadenza
                                    </dt>
                                    <dd class="mt-1 text-sm text-gray-900">
                                        {{ $this->primaryWarranty->ends_at?->format('d/m/Y') ?? '—' }}
                                    </dd>
                                </div>

                                <div>
                                    <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Giorni residui
                                    </dt>
                                    <dd class="mt-1 text-sm text-gray-900">
                                        @if ($this->warrantyRemainingDays === null)
                                            —
                                        @elseif ($this->warrantyRemainingDays < 0)
                                            Scaduta da {{ abs($this->warrantyRemainingDays) }} giorni
                                        @else
                                            {{ $this->warrantyRemainingDays }} giorni
                                        @endif
                                    </dd>
                                </div>

                                <div>
                                    <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Fonte
                                    </dt>
                                    <dd class="mt-1 text-sm text-gray-900">
                                        @switch($this->primaryWarranty->source)
                                            @case('calculated')
                                                Calcolata automaticamente
                                                @break

                                            @case('manual')
                                                Modificata manualmente
                                                @break

                                            @default
                                                {{ $this->primaryWarranty->source }}
                                        @endswitch
                                    </dd>
                                </div>

                                <div>
                                    <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Affidabilità
                                    </dt>
                                    <dd class="mt-1 text-sm text-gray-900">
                                        @if ($this->primaryWarranty->confidence_score !== null)
                                            {{ $this->primaryWarranty->confidence_score }}/100
                                        @else
                                            —
                                        @endif
                                    </dd>
                                </div>

                                <div>
                                    <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Documento sorgente
                                    </dt>
                                    <dd class="mt-1 text-sm text-gray-900">
                                        @if ($this->primaryWarranty->sourceDocument)
                                            <a
                                                href="{{ route('documents.show', $this->primaryWarranty->sourceDocument) }}"
                                                class="text-indigo-600 hover:text-indigo-900"
                                            >
                                                {{ $this->primaryWarranty->sourceDocument->original_filename }}
                                            </a>
                                        @else
                                            —
                                        @endif
                                    </dd>
                                </div>
                            </dl>

                            @if ($isEditingWarranty)
                                <form wire:submit.prevent="saveWarranty" class="mt-6 space-y-5">
                                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                                        <div>
                                            <label for="warrantyStartsAt" class="block text-xs font-medium uppercase tracking-wider text-gray-500">
                                                Inizio
                                            </label>
                                            <input
                                                id="warrantyStartsAt"
                                                type="date"
                                                wire:model="warrantyStartsAt"
                                                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                            >
                                            @error('warrantyStartsAt')
                                                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                                            @enderror
                                        </div>

                                        <div>
                                            <label for="warrantyEndsAt" class="block text-xs font-medium uppercase tracking-wider text-gray-500">
                                                Scadenza
                                            </label>
                                            <input
                                                id="warrantyEndsAt"
                                                type="date"
                                                wire:model="warrantyEndsAt"
                                                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                            >
                                            @error('warrantyEndsAt')
                                                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                                            @enderror
                                        </div>

                                        <div>
                                            <label for="warrantyDurationMonths" class="block text-xs font-medium uppercase tracking-wider text-gray-500">
                                                Durata mesi
                                            </label>
                                            <input
                                                id="warrantyDurationMonths"
                                                type="number"
                                                min="1"
                                                max="600"
                                                wire:model="warrantyDurationMonths"
                                                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                            >
                                            @error('warrantyDurationMonths')
                                                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                                            @enderror
                                        </div>
                                    </div>

                                    <div class="rounded-lg border border-gray-200 bg-gray-50 p-4">
                                        <div>
                                            <h4 class="text-sm font-semibold text-gray-900">
                                                Contesto della copertura
                                            </h4>

                                            <p class="mt-1 text-xs leading-5 text-gray-600">
                                                Queste informazioni aiutano a distinguere una stima generale
                                                dalla copertura applicabile al caso concreto. I campi possono
                                                restare non specificati quando l’informazione non è disponibile.
                                            </p>
                                        </div>

                                        <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                            <div>
                                                <label
                                                    for="warrantyPurchaseUse"
                                                    class="block text-xs font-medium uppercase tracking-wider text-gray-500"
                                                >
                                                    Uso dell’acquisto
                                                </label>

                                                <select
                                                    id="warrantyPurchaseUse"
                                                    wire:model="warrantyPurchaseUse"
                                                    class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                                >
                                                    <option value="unknown">
                                                        Non specificato
                                                    </option>

                                                    <option value="personal">
                                                        Uso personale
                                                    </option>

                                                    <option value="business">
                                                        Uso professionale o aziendale
                                                    </option>
                                                </select>

                                                @error('warrantyPurchaseUse')
                                                    <p class="mt-1 text-xs text-red-600">
                                                        {{ $message }}
                                                    </p>
                                                @enderror
                                            </div>

                                            <div>
                                                <label
                                                    for="warrantySellerType"
                                                    class="block text-xs font-medium uppercase tracking-wider text-gray-500"
                                                >
                                                    Tipo di venditore
                                                </label>

                                                <select
                                                    id="warrantySellerType"
                                                    wire:model="warrantySellerType"
                                                    class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                                >
                                                    <option value="unknown">
                                                        Non specificato
                                                    </option>

                                                    <option value="professional">
                                                        Venditore professionale
                                                    </option>

                                                    <option value="private">
                                                        Venditore privato
                                                    </option>
                                                </select>

                                                @error('warrantySellerType')
                                                    <p class="mt-1 text-xs text-red-600">
                                                        {{ $message }}
                                                    </p>
                                                @enderror
                                            </div>

                                            <div>
                                                <label
                                                    for="warrantyProductCondition"
                                                    class="block text-xs font-medium uppercase tracking-wider text-gray-500"
                                                >
                                                    Condizione del prodotto
                                                </label>

                                                <select
                                                    id="warrantyProductCondition"
                                                    wire:model="warrantyProductCondition"
                                                    class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                                >
                                                    <option value="unknown">
                                                        Non specificata
                                                    </option>

                                                    <option value="new">
                                                        Nuovo
                                                    </option>

                                                    <option value="used">
                                                        Usato
                                                    </option>

                                                    <option value="refurbished">
                                                        Ricondizionato
                                                    </option>
                                                </select>

                                                @error('warrantyProductCondition')
                                                    <p class="mt-1 text-xs text-red-600">
                                                        {{ $message }}
                                                    </p>
                                                @enderror
                                            </div>

                                            <div>
                                                <label
                                                    for="warrantyCountryCode"
                                                    class="block text-xs font-medium uppercase tracking-wider text-gray-500"
                                                >
                                                    Paese rilevante
                                                </label>

                                                <input
                                                    id="warrantyCountryCode"
                                                    type="text"
                                                    maxlength="2"
                                                    autocomplete="country"
                                                    wire:model.blur="warrantyCountryCode"
                                                    class="mt-1 block w-full rounded-md border-gray-300 text-sm uppercase shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                                    placeholder="IT"
                                                >

                                                <p class="mt-1 text-xs text-gray-500">
                                                    Codice di due lettere, ad esempio IT.
                                                </p>

                                                @error('warrantyCountryCode')
                                                    <p class="mt-1 text-xs text-red-600">
                                                        {{ $message }}
                                                    </p>
                                                @enderror
                                            </div>

                                            <div>
                                                <label
                                                    for="warrantyDeliveredAt"
                                                    class="block text-xs font-medium uppercase tracking-wider text-gray-500"
                                                >
                                                    Data di consegna
                                                </label>

                                                <input
                                                    id="warrantyDeliveredAt"
                                                    type="date"
                                                    wire:model="warrantyDeliveredAt"
                                                    class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                                >

                                                <p class="mt-1 text-xs text-gray-500">
                                                    Indicala solo quando è conosciuta.
                                                </p>

                                                @error('warrantyDeliveredAt')
                                                    <p class="mt-1 text-xs text-red-600">
                                                        {{ $message }}
                                                    </p>
                                                @enderror
                                            </div>

                                            <div>
                                                <label
                                                    for="warrantyDeclaredCoverage"
                                                    class="block text-xs font-medium uppercase tracking-wider text-gray-500"
                                                >
                                                    Copertura dichiarata
                                                </label>

                                                <select
                                                    id="warrantyDeclaredCoverage"
                                                    wire:model="warrantyDeclaredCoverage"
                                                    class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                                >
                                                    <option value="">
                                                        Non specificata
                                                    </option>

                                                    <option value="1">
                                                        Sì, indicata nel documento
                                                    </option>

                                                    <option value="0">
                                                        No, non indicata nel documento
                                                    </option>
                                                </select>

                                                @error('warrantyDeclaredCoverage')
                                                    <p class="mt-1 text-xs text-red-600">
                                                        {{ $message }}
                                                    </p>
                                                @enderror
                                            </div>
                                        </div>

                                        <div class="mt-4 rounded-md bg-white px-3 py-2 ring-1 ring-inset ring-gray-200">
                                            <p class="text-xs leading-5 text-gray-600">
                                                Salvando, confermi le date e le informazioni inserite.
                                                La conferma non trasforma automaticamente la copertura in una
                                                verifica legale o del venditore.
                                            </p>
                                        </div>
                                    </div>

                                    <div>
                                        <label for="warrantyNotes" class="block text-xs font-medium uppercase tracking-wider text-gray-500">
                                            Note
                                        </label>
                                        <textarea
                                            id="warrantyNotes"
                                            rows="3"
                                            wire:model="warrantyNotes"
                                            class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                            placeholder="Aggiungi una nota sulla garanzia..."
                                        ></textarea>
                                        @error('warrantyNotes')
                                            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                                        @enderror
                                    </div>

                                    <div class="flex justify-end gap-3">
                                        <button
                                            type="button"
                                            wire:click="cancelWarrantyEdit"
                                            class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                                        >
                                            Annulla
                                        </button>

                                        <button
                                            type="submit"
                                            class="rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800"
                                        >
                                            Salva garanzia
                                        </button>
                                    </div>
                                </form>
                            @else
                                @php
                                    $warrantySourceNote = data_get($this->primaryWarranty->metadata, 'source_note');
                                    $warrantyManualNote = $this->primaryWarranty->notes;
                                @endphp

                                @if ($warrantySourceNote)
                                    <div class="mt-6 rounded-md bg-gray-50 p-4">
                                        <div class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                            Nota regola
                                        </div>

                                        <p class="mt-1 text-sm text-gray-700">
                                            {{ $warrantySourceNote }}
                                        </p>
                                    </div>
                                @endif

                                @if ($warrantyManualNote)
                                    <div class="mt-6 rounded-md bg-gray-50 p-4">
                                        <div class="text-xs font-medium uppercase tracking-wider text-gray-500">
                                            Note garanzia
                                        </div>

                                        <p class="mt-1 whitespace-pre-line text-sm text-gray-700">
                                            {{ $warrantyManualNote }}
                                        </p>
                                    </div>
                                @endif
                            @endif
                        @else
                            @if ($isCreatingWarranty)
                                <form wire:submit.prevent="saveWarranty" class="mt-6 space-y-5">
                                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                                        <div>
                                            <label for="warrantyStartsAt" class="block text-xs font-medium uppercase tracking-wider text-gray-500">
                                                Inizio
                                            </label>
                                            <input
                                                id="warrantyStartsAt"
                                                type="date"
                                                wire:model="warrantyStartsAt"
                                                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                            >
                                            @error('warrantyStartsAt')
                                                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                                            @enderror
                                        </div>

                                        <div>
                                            <label for="warrantyEndsAt" class="block text-xs font-medium uppercase tracking-wider text-gray-500">
                                                Scadenza
                                            </label>
                                            <input
                                                id="warrantyEndsAt"
                                                type="date"
                                                wire:model="warrantyEndsAt"
                                                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                            >
                                            @error('warrantyEndsAt')
                                                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                                            @enderror
                                        </div>

                                        <div>
                                            <label for="warrantyDurationMonths" class="block text-xs font-medium uppercase tracking-wider text-gray-500">
                                                Durata mesi
                                            </label>
                                            <input
                                                id="warrantyDurationMonths"
                                                type="number"
                                                min="1"
                                                max="600"
                                                wire:model="warrantyDurationMonths"
                                                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                            >
                                            @error('warrantyDurationMonths')
                                                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                                            @enderror
                                        </div>
                                    </div>

                                    <div class="rounded-lg border border-gray-200 bg-gray-50 p-4">
                                        <div>
                                            <h4 class="text-sm font-semibold text-gray-900">
                                                Contesto della copertura
                                            </h4>

                                            <p class="mt-1 text-xs leading-5 text-gray-600">
                                                Queste informazioni aiutano a distinguere una stima generale
                                                dalla copertura applicabile al caso concreto. I campi possono
                                                restare non specificati quando l’informazione non è disponibile.
                                            </p>
                                        </div>

                                        <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                            <div>
                                                <label
                                                    for="warrantyPurchaseUse"
                                                    class="block text-xs font-medium uppercase tracking-wider text-gray-500"
                                                >
                                                    Uso dell’acquisto
                                                </label>

                                                <select
                                                    id="warrantyPurchaseUse"
                                                    wire:model="warrantyPurchaseUse"
                                                    class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                                >
                                                    <option value="unknown">
                                                        Non specificato
                                                    </option>

                                                    <option value="personal">
                                                        Uso personale
                                                    </option>

                                                    <option value="business">
                                                        Uso professionale o aziendale
                                                    </option>
                                                </select>

                                                @error('warrantyPurchaseUse')
                                                    <p class="mt-1 text-xs text-red-600">
                                                        {{ $message }}
                                                    </p>
                                                @enderror
                                            </div>

                                            <div>
                                                <label
                                                    for="warrantySellerType"
                                                    class="block text-xs font-medium uppercase tracking-wider text-gray-500"
                                                >
                                                    Tipo di venditore
                                                </label>

                                                <select
                                                    id="warrantySellerType"
                                                    wire:model="warrantySellerType"
                                                    class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                                >
                                                    <option value="unknown">
                                                        Non specificato
                                                    </option>

                                                    <option value="professional">
                                                        Venditore professionale
                                                    </option>

                                                    <option value="private">
                                                        Venditore privato
                                                    </option>
                                                </select>

                                                @error('warrantySellerType')
                                                    <p class="mt-1 text-xs text-red-600">
                                                        {{ $message }}
                                                    </p>
                                                @enderror
                                            </div>

                                            <div>
                                                <label
                                                    for="warrantyProductCondition"
                                                    class="block text-xs font-medium uppercase tracking-wider text-gray-500"
                                                >
                                                    Condizione del prodotto
                                                </label>

                                                <select
                                                    id="warrantyProductCondition"
                                                    wire:model="warrantyProductCondition"
                                                    class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                                >
                                                    <option value="unknown">
                                                        Non specificata
                                                    </option>

                                                    <option value="new">
                                                        Nuovo
                                                    </option>

                                                    <option value="used">
                                                        Usato
                                                    </option>

                                                    <option value="refurbished">
                                                        Ricondizionato
                                                    </option>
                                                </select>

                                                @error('warrantyProductCondition')
                                                    <p class="mt-1 text-xs text-red-600">
                                                        {{ $message }}
                                                    </p>
                                                @enderror
                                            </div>

                                            <div>
                                                <label
                                                    for="warrantyCountryCode"
                                                    class="block text-xs font-medium uppercase tracking-wider text-gray-500"
                                                >
                                                    Paese rilevante
                                                </label>

                                                <input
                                                    id="warrantyCountryCode"
                                                    type="text"
                                                    maxlength="2"
                                                    autocomplete="country"
                                                    wire:model.blur="warrantyCountryCode"
                                                    class="mt-1 block w-full rounded-md border-gray-300 text-sm uppercase shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                                    placeholder="IT"
                                                >

                                                <p class="mt-1 text-xs text-gray-500">
                                                    Codice di due lettere, ad esempio IT.
                                                </p>

                                                @error('warrantyCountryCode')
                                                    <p class="mt-1 text-xs text-red-600">
                                                        {{ $message }}
                                                    </p>
                                                @enderror
                                            </div>

                                            <div>
                                                <label
                                                    for="warrantyDeliveredAt"
                                                    class="block text-xs font-medium uppercase tracking-wider text-gray-500"
                                                >
                                                    Data di consegna
                                                </label>

                                                <input
                                                    id="warrantyDeliveredAt"
                                                    type="date"
                                                    wire:model="warrantyDeliveredAt"
                                                    class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                                >

                                                <p class="mt-1 text-xs text-gray-500">
                                                    Indicala solo quando è conosciuta.
                                                </p>

                                                @error('warrantyDeliveredAt')
                                                    <p class="mt-1 text-xs text-red-600">
                                                        {{ $message }}
                                                    </p>
                                                @enderror
                                            </div>

                                            <div>
                                                <label
                                                    for="warrantyDeclaredCoverage"
                                                    class="block text-xs font-medium uppercase tracking-wider text-gray-500"
                                                >
                                                    Copertura dichiarata
                                                </label>

                                                <select
                                                    id="warrantyDeclaredCoverage"
                                                    wire:model="warrantyDeclaredCoverage"
                                                    class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                                >
                                                    <option value="">
                                                        Non specificata
                                                    </option>

                                                    <option value="1">
                                                        Sì, indicata nel documento
                                                    </option>

                                                    <option value="0">
                                                        No, non indicata nel documento
                                                    </option>
                                                </select>

                                                @error('warrantyDeclaredCoverage')
                                                    <p class="mt-1 text-xs text-red-600">
                                                        {{ $message }}
                                                    </p>
                                                @enderror
                                            </div>
                                        </div>

                                        <div class="mt-4 rounded-md bg-white px-3 py-2 ring-1 ring-inset ring-gray-200">
                                            <p class="text-xs leading-5 text-gray-600">
                                                Salvando, confermi le date e le informazioni inserite.
                                                La conferma non trasforma automaticamente la copertura in una
                                                verifica legale o del venditore.
                                            </p>
                                        </div>
                                    </div>

                                    <div>
                                        <label for="warrantyNotes" class="block text-xs font-medium uppercase tracking-wider text-gray-500">
                                            Note
                                        </label>
                                        <textarea
                                            id="warrantyNotes"
                                            rows="3"
                                            wire:model="warrantyNotes"
                                            class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                            placeholder="Aggiungi una nota sulla garanzia..."
                                        ></textarea>
                                        @error('warrantyNotes')
                                            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                                        @enderror
                                    </div>

                                    <div class="flex justify-end gap-3">
                                        <button
                                            type="button"
                                            wire:click="cancelWarrantyEdit"
                                            class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                                        >
                                            Annulla
                                        </button>

                                        <button
                                            type="submit"
                                            class="rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800"
                                        >
                                            Crea garanzia
                                        </button>
                                    </div>
                                </form>
                            @else
                                <div class="mt-6 rounded-md bg-gray-50 p-4">
                                    <p class="text-sm text-gray-700">
                                        Nessuna garanzia calcolata per questo prodotto.
                                    </p>

                                    <p class="mt-1 text-xs text-gray-500">
                                        Puoi crearne una manualmente indicando inizio, scadenza e durata.
                                    </p>

                                    <div class="mt-4">
                                        <button
                                            type="button"
                                            wire:click="createWarranty"
                                            class="rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800"
                                        >
                                            Crea garanzia manuale
                                        </button>
                                    </div>
                                </div>
                            @endif
                        @endif
                    </div>
                </section>

                <section class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-lg">
                    <div class="p-6">
                        <h2 class="text-lg font-medium text-gray-900">
                            Storico
                        </h2>

                        <p class="mt-1 text-sm text-gray-600">
                            Eventi principali del ciclo di vita del prodotto.
                        </p>

                        @if ($product->events->isNotEmpty())
                            <div class="mt-6 flow-root">
                                <ul role="list" class="-mb-8">
                                    @foreach ($product->events->sortByDesc('created_at') as $event)
                                        <li>
                                            <div class="relative pb-8">
                                                @if (! $loop->last)
                                                    <span class="absolute left-4 top-4 -ml-px h-full w-0.5 bg-gray-200" aria-hidden="true"></span>
                                                @endif

                                                <div class="relative flex space-x-3">
                                                    <div>
                                                        <span class="flex h-8 w-8 items-center justify-center rounded-full bg-gray-100 ring-8 ring-white">
                                                            <span class="text-xs font-semibold text-gray-700">
                                                                {{ strtoupper(substr($event->productEventType?->code ?? 'E', 0, 1)) }}
                                                            </span>
                                                        </span>
                                                    </div>

                                                    <div class="flex min-w-0 flex-1 justify-between space-x-4 pt-1.5">
                                                        <div>
                                                            <p class="text-sm font-medium text-gray-900">
                                                                {{ $event->title }}
                                                            </p>

                                                            @if ($event->description)
                                                                <p class="mt-1 text-sm text-gray-600">
                                                                    {{ $event->description }}
                                                                </p>
                                                            @endif

                                                            <div class="mt-2 flex flex-wrap gap-2 text-xs text-gray-500">
                                                                @if ($event->productEventType)
                                                                    <span>{{ $event->productEventType->name }}</span>
                                                                @endif

                                                                @if ($event->source)
                                                                    <span>Fonte: {{ $event->source }}</span>
                                                                @endif

                                                                @if ($event->confidence_score !== null)
                                                                    <span>Affidabilità {{ $event->confidence_score }}/100</span>
                                                                @endif
                                                            </div>

                                                            @if ($event->document)
                                                                <div class="mt-2">
                                                                    <a
                                                                        href="{{ route('documents.show', $event->document) }}"
                                                                        class="text-xs font-medium text-indigo-600 hover:text-indigo-900"
                                                                    >
                                                                        Documento: {{ $event->document->original_filename }}
                                                                    </a>
                                                                </div>
                                                            @endif
                                                        </div>

                                                        <div class="whitespace-nowrap text-right text-xs text-gray-500">
                                                            <div>
                                                                {{ $event->event_date?->format('d/m/Y') ?? $event->created_at?->format('d/m/Y') }}
                                                            </div>

                                                            @if ($event->createdBy)
                                                                <div class="mt-1">
                                                                    {{ $event->createdBy->name }}
                                                                </div>
                                                            @endif
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @else
                            <div class="mt-6 rounded-md bg-gray-50 p-4">
                                <p class="text-sm text-gray-700">
                                    Nessun evento registrato per questo prodotto.
                                </p>
                            </div>
                        @endif
                    </div>
                </section>
            </div>

            <aside class="space-y-6">
                <section class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-lg">
                    <div class="p-6">
                        <h2 class="text-lg font-medium text-gray-900">
                            Documenti collegati
                        </h2>

                        @if ($product->documents->isNotEmpty())
                            <div class="mt-5 space-y-3">
                                @foreach ($product->documents as $document)
                                    <a
                                        href="{{ route('documents.show', $document) }}"
                                        class="block rounded-md border border-gray-200 p-4 hover:bg-gray-50"
                                    >
                                        <div class="text-sm font-medium text-gray-900">
                                            {{ $document->original_filename }}
                                        </div>

                                        <div class="mt-1 text-xs text-gray-500">
                                            {{ $document->documentType?->name ?? 'Documento' }}
                                            · {{ $document->created_at?->format('d/m/Y H:i') }}
                                        </div>
                                    </a>
                                @endforeach
                            </div>
                        @else
                            <p class="mt-4 text-sm text-gray-600">
                                Nessun documento collegato.
                            </p>
                        @endif
                    </div>
                </section>
            </aside>
        </div>
    </div>
</div>