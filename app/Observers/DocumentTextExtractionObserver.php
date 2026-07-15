<?php

namespace App\Observers;

use App\Models\Document;
use App\Models\DocumentTextExtraction;
use App\Models\Team;
use App\Services\Monetization\PlanLimitDecisionService;
use App\Services\Monetization\UsageMeter;
use App\Support\Monetization\MonetizationKeys;

final class DocumentTextExtractionObserver
{
    public function __construct(
        private readonly PlanLimitDecisionService $limitDecisionService,
        private readonly UsageMeter $usageMeter
    ) {
    }

    public function creating(
        DocumentTextExtraction $extraction
    ): void {
        if (! $this->isOcrEngine($extraction->engine)) {
            return;
        }

        [$document, $team] = $this->resolveContext($extraction);

        if ($document === null || $team === null) {
            return;
        }

        $this->limitDecisionService->ensureCanConsume(
            $team,
            MonetizationKeys::LIMIT_MAX_OCR_PER_MONTH,
            1
        );
    }

    public function created(
        DocumentTextExtraction $extraction
    ): void {
        if (! $this->isOcrEngine($extraction->engine)) {
            return;
        }

        [$document, $team] = $this->resolveContext($extraction);

        if ($document === null || $team === null) {
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

    private function isOcrEngine(?string $engine): bool
    {
        return str_contains(
            strtolower(trim((string) $engine)),
            'ocr'
        );
    }

    /**
     * @return array{0: Document|null, 1: Team|null}
     */
    private function resolveContext(
        DocumentTextExtraction $extraction
    ): array {
        $document = $extraction->document_id
            ? Document::query()->find($extraction->document_id)
            : null;

        if ($document === null || $document->team_id === null) {
            return [null, null];
        }

        return [
            $document,
            Team::query()->find($document->team_id),
        ];
    }
}
