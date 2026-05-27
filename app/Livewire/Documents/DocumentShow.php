<?php

namespace App\Livewire\Documents;

use App\Models\Document;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class DocumentShow extends Component
{
    use AuthorizesRequests;

    /**
     * Documento mostrato nella pagina dettaglio.
     */
    public Document $document;

    /**
     * Inizializza il componente con route model binding.
     */
    public function mount(Document $document): void
    {
        $this->authorize('view', $document);

        $this->document = $document->load([
            'documentType',
            'merchant',
            'currency',
            'uploadedBy',
        ]);
    }

    /**
     * Etichetta leggibile dello stato documento.
     */
    public function getStatusLabelProperty(): string
    {
        return match ($this->document->status) {
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
            default => ucfirst(str_replace('_', ' ', $this->document->status ?? 'uploaded')),
        };
    }

    /**
     * Classi CSS del badge stato.
     */
    public function getStatusBadgeClassesProperty(): string
    {
        return match ($this->document->status) {
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
            default => 'bg-gray-100 text-gray-700 ring-gray-500/20',
        };
    }

    /**
     * Formatta una dimensione in byte in formato leggibile.
     */
    public function formatBytes(?int $bytes): string
    {
        if (! $bytes) {
            return '—';
        }

        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        if ($bytes < 1024 * 1024) {
            return number_format($bytes / 1024, 2, ',', '.') . ' KB';
        }

        return number_format($bytes / 1024 / 1024, 2, ',', '.') . ' MB';
    }

    /**
     * Mostra la pagina dettaglio documento.
     */
    public function render(): View
    {
        return view('livewire.documents.document-show')
            ->layout('layouts.app');
    }
}