<?php

namespace App\Actions\Documents;

use App\Jobs\ProcessDocumentJob;
use App\Models\Document;
use App\Models\DocumentProcessingAttempt;
use App\Models\Team;
use App\Services\Monetization\PlanLimitDecisionService;
use App\Services\Monetization\UsageMeter;
use App\Services\Monetization\UsageSnapshotResolver;
use App\Support\Monetization\MonetizationKeys;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;

class StoreUploadedDocumentAction
{
    public function __construct(
        private readonly PlanLimitDecisionService $limitDecisionService,
        private readonly UsageSnapshotResolver $usageSnapshotResolver,
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
        $snapshot = $this->usageSnapshotResolver->resolve($team);
        $currentStorageBytes = (int) data_get(
            $snapshot,
            'raw.storage_bytes',
            0
        );
        $currentStorageMb = (int) data_get(
            $snapshot,
            'resources.'
            . MonetizationKeys::LIMIT_MAX_STORAGE_MB
            . '.used',
            0
        );
        $projectedStorageBytes = $currentStorageBytes + $fileSize;
        $projectedStorageMb = $projectedStorageBytes > 0
            ? (int) ceil($projectedStorageBytes / 1024 / 1024)
            : 0;
        $storageMbIncrement = max(
            0,
            $projectedStorageMb - $currentStorageMb
        );

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

        $document = null;
        $storedMediaPath = null;

        try {
            $document = DB::transaction(function () use (
                $file,
                $fileSize,
                $originalName,
                $storedFileName,
                $team,
                $teamId,
                $user,
                $projectedStorageMb,
                &$storedMediaPath,
                &$document
            ): Document {
                $document = new Document();
                $document->team_id = $teamId;
                $document->uploaded_by_user_id = $user->id;
                $document->status = 'uploaded';
                $document->source = 'manual_upload';
                $document->original_filename = $originalName;
                $document->mime_type = $file->getMimeType();
                $document->file_size = $fileSize;
                $document->save();

                $media = $document
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

                $storedMediaPath = $media->getPath();
                $document = $document->refresh();

                $this->usageMeter->record(
                    team: $team,
                    eventKey: MonetizationKeys::EVENT_DOCUMENT_UPLOADED,
                    quantity: 1,
                    idempotencyKey:
                        'document:' . $document->id . ':uploaded',
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
                        eventKey:
                            MonetizationKeys::EVENT_STORAGE_BYTES_ADDED,
                        quantity: $fileSize,
                        idempotencyKey:
                            'document:' . $document->id . ':storage',
                        userId: (int) $user->id,
                        subject: $document,
                        metadata: [
                            'storage_disk' => 'local',
                            'projected_storage_mb' => $projectedStorageMb,
                        ],
                    );
                }

                $document->update([
                    'text_extraction_status' => 'pending',
                ]);

                return $document->refresh();
            });
        } catch (Throwable $exception) {
            if (
                is_string($storedMediaPath)
                && $storedMediaPath !== ''
                && is_file($storedMediaPath)
            ) {
                File::delete($storedMediaPath);
            }

            if (
                $document instanceof Document
                && $document->getKey() !== null
            ) {
                Document::withTrashed()
                    ->whereKey($document->getKey())
                    ->get()
                    ->each(function (Document $storedDocument): void {
                        $storedDocument->clearMediaCollection(
                            'original_file'
                        );
                        $storedDocument->forceDelete();
                    });
            }

            Log::error('Document upload persistence failed.', [
                'team_id' => $teamId,
                'user_id' => $user->id,
                'original_filename' => $originalName,
                'exception_class' => $exception::class,
                'exception' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        try {
            ProcessDocumentJob::dispatch($document->id);
        } catch (Throwable $exception) {
            $document->update([
                'status' => 'failed',
                'text_extraction_status' => 'failed',
            ]);

            DocumentProcessingAttempt::query()->create([
                'document_id' => $document->id,
                'step' => 'dispatch',
                'status' => 'failed',
                'handler' => ProcessDocumentJob::class,
                'attempt_number' => 1,
                'error_message' => $exception->getMessage(),
                'exception_class' => $exception::class,
                'metadata' => [
                    'queue_connection' => config('queue.default'),
                    'original_filename' => $originalName,
                ],
                'started_at' => now(),
                'completed_at' => now(),
            ]);

            Log::error('Document processing dispatch failed.', [
                'document_id' => $document->id,
                'team_id' => $teamId,
                'exception_class' => $exception::class,
                'exception' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        return $document->refresh();
    }
}
