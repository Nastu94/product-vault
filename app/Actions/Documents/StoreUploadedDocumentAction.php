<?php

namespace App\Actions\Documents;

use App\Jobs\ProcessDocumentJob;
use App\Models\Document;
use App\Models\Team;
use App\Services\Monetization\PlanLimitDecisionService;
use App\Services\Monetization\UsageMeter;
use App\Support\Monetization\MonetizationKeys;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class StoreUploadedDocumentAction
{
    public function __construct(
        private readonly PlanLimitDecisionService $limitDecisionService,
        private readonly UsageMeter $usageMeter
    ) {
    }

    public function handle(
        TemporaryUploadedFile|UploadedFile $file
    ): Document {
        $user = Auth::user();
        $teamId = $user->current_team_id ?? $user->currentTeam?->id;

        abort_unless($user && $teamId, 403, 'Nessun workspace attivo.');

        $team = Team::query()->findOrFail($teamId);
        $fileSize = max(0, (int) $file->getSize());
        $storageMbIncrement = $fileSize > 0
            ? (int) ceil($fileSize / 1024 / 1024)
            : 0;

        $this->limitDecisionService->ensureCanConsume(
            $team,
            MonetizationKeys::LIMIT_MAX_DOCUMENTS,
            1
        );

        if ($storageMbIncrement > 0) {
            $this->limitDecisionService->ensureCanConsume(
                $team,
                MonetizationKeys::LIMIT_MAX_STORAGE_MB,
                $storageMbIncrement
            );
        }

        $document = new Document();
        $document->team_id = $teamId;
        $document->uploaded_by_user_id = $user->id;
        $document->status = 'uploaded';
        $document->source = 'manual_upload';
        $document->original_filename = $file->getClientOriginalName();
        $document->mime_type = $file->getMimeType();
        $document->file_size = $fileSize;
        $document->save();

        $originalName = $file->getClientOriginalName();
        $baseName = pathinfo($originalName, PATHINFO_FILENAME);
        $safeBaseName = Str::slug(Str::ascii($baseName));

        if ($safeBaseName === '') {
            $safeBaseName = 'documento';
        }

        $extension = strtolower(
            $file->getClientOriginalExtension()
                ?: $file->guessExtension()
                ?: 'bin'
        );

        $storedFileName = $safeBaseName
            . '-'
            . now()->format('YmdHis')
            . '-'
            . Str::lower(Str::random(8))
            . '.'
            . $extension;

        $document
            ->addMedia($file->getRealPath())
            ->usingName($originalName)
            ->usingFileName($storedFileName)
            ->withCustomProperties([
                'original_client_filename' => $originalName,
                'uploaded_by_user_id' => $user->id,
                'team_id' => $teamId,
                'source' => 'manual_upload',
            ])
            ->toMediaCollection('original_file', 'local');

        $document = $document->refresh();

        $this->usageMeter->record(
            team: $team,
            eventKey: MonetizationKeys::EVENT_DOCUMENT_UPLOADED,
            quantity: 1,
            idempotencyKey: 'document:' . $document->id . ':uploaded',
            userId: (int) $user->id,
            subject: $document,
            metadata: [
                'original_filename' => $originalName,
                'mime_type' => $document->mime_type,
                'file_size' => $fileSize,
            ],
        );

        if ($fileSize > 0) {
            $this->usageMeter->record(
                team: $team,
                eventKey: MonetizationKeys::EVENT_STORAGE_BYTES_ADDED,
                quantity: $fileSize,
                idempotencyKey: 'document:' . $document->id . ':storage',
                userId: (int) $user->id,
                subject: $document,
                metadata: [
                    'storage_disk' => 'local',
                ],
            );
        }

        $document->update([
            'text_extraction_status' => 'pending',
        ]);

        ProcessDocumentJob::dispatch($document->id);

        return $document->refresh();
    }
}
