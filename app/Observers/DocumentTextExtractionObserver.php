<?php

namespace App\Observers;

use App\Models\DocumentTextExtraction;
use App\Models\Team;
use App\Services\Monetization\UsageMeter;
use App\Support\Monetization\MonetizationKeys;

final class DocumentTextExtractionObserver
{
    public function __construct(
        private readonly UsageMeter $usageMeter
    ) {
    }

    public function created(
        DocumentTextExtraction $extraction
    ): void {
        $engine = strtolower(trim((string) $extraction->engine));

        if (! str_contains($engine, 'ocr')) {
            return;
        }

        $document = $extraction->document;

        if ($document === null || $document->team_id === null) {
            return;
        }

        $team = Team::query()->find($document->team_id);

        if ($team === null) {
            return;
        }

        $this->usageMeter->record(
            team: $team,
            eventKey: MonetizationKeys::EVENT_OCR_RUN,
            quantity: 1,
            idempotencyKey:
                'text-extraction:' . $extraction->id . ':ocr',
            userId: $document->uploaded_by_user_id
                ? (int) $document->uploaded_by_user_id
                : null,
            subject: $extraction,
            metadata: [
                'document_id' => (int) $document->id,
                'engine' => $extraction->engine,
            ],
        );
    }
}
