<?php

namespace Database\Seeders;

use App\Models\Country;
use App\Models\Currency;
use App\Models\DocumentLineType;
use App\Models\DocumentRelationshipType;
use App\Models\DocumentType;
use App\Models\IdentificationStatus;
use App\Models\ProductEventType;
use App\Models\WarrantyType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class LookupSeeder extends Seeder
{
    /**
     * Popola le tabelle lookup di base del progetto.
     *
     * Questi dati servono per evitare stringhe sparse nel database
     * e mantenere coerenti tipi documento, stati, valute e classificazioni.
     */
    public function run(): void
    {
        DB::transaction(function () {
            $this->seedDocumentTypes();
            $this->seedDocumentLineTypes();
            $this->seedDocumentRelationshipTypes();
            $this->seedIdentificationStatuses();
            $this->seedWarrantyTypes();
            $this->seedProductEventTypes();
            $this->seedCurrencies();
            $this->seedCountries();
        });
    }

    /**
     * Tipologie di documento supportate o previste.
     */
    private function seedDocumentTypes(): void
    {
        $items = [
            [
                'code' => 'receipt',
                'name' => 'Scontrino',
                'description' => 'Documento fiscale o commerciale usato come prova di acquisto.',
            ],
            [
                'code' => 'invoice',
                'name' => 'Fattura',
                'description' => 'Fattura di acquisto, utile per prova fiscale, prodotto e garanzia.',
            ],
            [
                'code' => 'order_confirmation',
                'name' => 'Conferma ordine',
                'description' => 'Email, PDF o documento di conferma ordine proveniente da e-commerce o venditore.',
            ],
            [
                'code' => 'warranty_certificate',
                'name' => 'Certificato di garanzia',
                'description' => 'Documento che certifica una garanzia legale, commerciale o del produttore.',
            ],
            [
                'code' => 'extended_warranty',
                'name' => 'Garanzia estesa',
                'description' => 'Documento relativo a una garanzia aggiuntiva o estesa.',
            ],
            [
                'code' => 'manual',
                'name' => 'Manuale',
                'description' => 'Manuale, istruzioni o guida utente collegata a un prodotto.',
            ],
            [
                'code' => 'product_photo',
                'name' => 'Foto prodotto',
                'description' => 'Foto del prodotto, della scatola o di elementi utili alla sua identificazione.',
            ],
            [
                'code' => 'barcode_photo',
                'name' => 'Foto barcode',
                'description' => 'Foto contenente barcode, EAN, QR code o altri codici identificativi.',
            ],
            [
                'code' => 'serial_number_photo',
                'name' => 'Foto numero seriale',
                'description' => 'Foto contenente numero seriale, modello o codice prodotto.',
            ],
            [
                'code' => 'repair_document',
                'name' => 'Documento di riparazione',
                'description' => 'Documento relativo ad assistenza, riparazione, preventivo o intervento tecnico.',
            ],
            [
                'code' => 'service_quote',
                'name' => 'Preventivo assistenza',
                'description' => 'Preventivo collegato a manutenzione, assistenza o riparazione prodotto.',
            ],
            [
                'code' => 'delivery_note',
                'name' => 'Documento di consegna',
                'description' => 'Documento utile a ricostruire data di consegna o spedizione.',
            ],
            [
                'code' => 'unknown',
                'name' => 'Sconosciuto',
                'description' => 'Documento non ancora classificato.',
            ],
            [
                'code' => 'unsupported',
                'name' => 'Non supportato',
                'description' => 'Documento non pertinente o non gestibile nel flusso prodotto/garanzia.',
            ],
        ];

        foreach ($items as $item) {
            DocumentType::updateOrCreate(
                ['code' => $item['code']],
                $item + ['is_active' => true]
            );
        }
    }

    /**
     * Tipologie di righe estratte da documenti come scontrini e fatture.
     */
    private function seedDocumentLineTypes(): void
    {
        $items = [
            ['code' => 'product', 'name' => 'Prodotto', 'description' => 'Riga candidata a rappresentare un prodotto acquistato.'],
            ['code' => 'discount', 'name' => 'Sconto', 'description' => 'Riga relativa a sconti, promozioni o riduzioni.'],
            ['code' => 'tax', 'name' => 'Tassa / IVA', 'description' => 'Riga relativa a IVA, imposte o imponibile.'],
            ['code' => 'payment', 'name' => 'Pagamento', 'description' => 'Riga relativa al metodo di pagamento.'],
            ['code' => 'total', 'name' => 'Totale', 'description' => 'Riga contenente totale, subtotale o importo finale.'],
            ['code' => 'merchant_info', 'name' => 'Informazioni venditore', 'description' => 'Riga contenente dati del venditore, punto vendita, P.IVA o indirizzo.'],
            ['code' => 'unknown', 'name' => 'Sconosciuta', 'description' => 'Riga non classificata o non interpretabile.'],
        ];

        foreach ($items as $item) {
            DocumentLineType::updateOrCreate(
                ['code' => $item['code']],
                $item + ['is_active' => true]
            );
        }
    }

    /**
     * Tipi di relazione tra prodotto e documento.
     */
    private function seedDocumentRelationshipTypes(): void
    {
        $items = [
            ['code' => 'purchase_proof', 'name' => 'Prova di acquisto', 'description' => 'Documento usato come prova di acquisto del prodotto.'],
            ['code' => 'warranty_proof', 'name' => 'Prova di garanzia', 'description' => 'Documento usato come prova o certificato di garanzia.'],
            ['code' => 'manual', 'name' => 'Manuale', 'description' => 'Documento usato come manuale o istruzioni del prodotto.'],
            ['code' => 'repair_history', 'name' => 'Storico riparazione', 'description' => 'Documento collegato a riparazione, assistenza o manutenzione.'],
            ['code' => 'serial_number_evidence', 'name' => 'Prova numero seriale', 'description' => 'Documento o foto che aiuta a identificare seriale, modello o codice prodotto.'],
            ['code' => 'other', 'name' => 'Altro', 'description' => 'Relazione generica tra documento e prodotto.'],
        ];

        foreach ($items as $item) {
            DocumentRelationshipType::updateOrCreate(
                ['code' => $item['code']],
                $item + ['is_active' => true]
            );
        }
    }

    /**
     * Stati di identificazione del prodotto.
     */
    private function seedIdentificationStatuses(): void
    {
        $items = [
            ['code' => 'unknown', 'name' => 'Sconosciuto', 'description' => 'Prodotto non ancora identificato.'],
            ['code' => 'partial', 'name' => 'Parziale', 'description' => 'Prodotto identificato solo in parte.'],
            ['code' => 'probable', 'name' => 'Probabile', 'description' => 'Prodotto identificato con buona probabilità ma non ancora confermato.'],
            ['code' => 'user_confirmed', 'name' => 'Confermato dall’utente', 'description' => 'Prodotto confermato manualmente dall’utente.'],
            ['code' => 'merchant_verified', 'name' => 'Verificato dal venditore', 'description' => 'Prodotto verificato tramite fonte venditore o integrazione futura.'],
        ];

        foreach ($items as $item) {
            IdentificationStatus::updateOrCreate(
                ['code' => $item['code']],
                $item + ['is_active' => true]
            );
        }
    }

    /**
     * Tipologie di garanzia.
     */
    private function seedWarrantyTypes(): void
    {
        $items = [
            ['code' => 'legal', 'name' => 'Garanzia legale', 'description' => 'Garanzia legale prevista dal paese o mercato di riferimento.'],
            ['code' => 'commercial', 'name' => 'Garanzia commerciale', 'description' => 'Garanzia offerta dal produttore o dal venditore.'],
            ['code' => 'extended', 'name' => 'Garanzia estesa', 'description' => 'Garanzia aggiuntiva acquistata o attivata separatamente.'],
            ['code' => 'repair_extension', 'name' => 'Estensione da riparazione', 'description' => 'Estensione o copertura collegata a riparazione o sostituzione.'],
            ['code' => 'unknown', 'name' => 'Sconosciuta', 'description' => 'Tipo di garanzia non ancora determinato.'],
        ];

        foreach ($items as $item) {
            WarrantyType::updateOrCreate(
                ['code' => $item['code']],
                $item + ['is_active' => true]
            );
        }
    }

    /**
     * Tipologie di eventi nello storico prodotto.
     */
    private function seedProductEventTypes(): void
    {
        $items = [
            ['code' => 'purchase', 'name' => 'Acquisto', 'description' => 'Evento di acquisto del prodotto.'],
            ['code' => 'repair', 'name' => 'Riparazione', 'description' => 'Evento di riparazione del prodotto.'],
            ['code' => 'service', 'name' => 'Assistenza', 'description' => 'Evento di assistenza o manutenzione.'],
            ['code' => 'warranty_update', 'name' => 'Aggiornamento garanzia', 'description' => 'Aggiornamento di una garanzia collegata al prodotto.'],
            ['code' => 'manual_added', 'name' => 'Manuale aggiunto', 'description' => 'Aggiunta di un manuale al prodotto.'],
            ['code' => 'document_added', 'name' => 'Documento aggiunto', 'description' => 'Aggiunta di un documento generico al prodotto.'],
            ['code' => 'sold', 'name' => 'Venduto', 'description' => 'Prodotto venduto o ceduto.'],
            ['code' => 'disposed', 'name' => 'Dismesso', 'description' => 'Prodotto eliminato, smaltito o non più posseduto.'],
            ['code' => 'unknown', 'name' => 'Sconosciuto', 'description' => 'Evento non classificato.'],
        ];

        foreach ($items as $item) {
            ProductEventType::updateOrCreate(
                ['code' => $item['code']],
                $item + ['is_active' => true]
            );
        }
    }

    /**
     * Valute iniziali supportate.
     */
    private function seedCurrencies(): void
    {
        $items = [
            ['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'decimal_places' => 2],
            ['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$', 'decimal_places' => 2],
            ['code' => 'GBP', 'name' => 'British Pound', 'symbol' => '£', 'decimal_places' => 2],
        ];

        foreach ($items as $item) {
            Currency::updateOrCreate(
                ['code' => $item['code']],
                $item + ['is_active' => true]
            );
        }
    }

    /**
     * Paesi iniziali supportati.
     */
    private function seedCountries(): void
    {
        $items = [
            ['code' => 'IT', 'name' => 'Italy'],
            ['code' => 'US', 'name' => 'United States'],
            ['code' => 'GB', 'name' => 'United Kingdom'],
            ['code' => 'FR', 'name' => 'France'],
            ['code' => 'DE', 'name' => 'Germany'],
            ['code' => 'ES', 'name' => 'Spain'],
        ];

        foreach ($items as $item) {
            Country::updateOrCreate(
                ['code' => $item['code']],
                $item + ['is_active' => true]
            );
        }
    }
}