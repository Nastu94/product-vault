<?php

namespace App\Services\ProductCases;

use App\Models\ProductCase;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use RuntimeException;
use Throwable;

final class ProductCasePhotoManager
{
    /**
     * @throws Throwable
     */
    public function __construct(
        private readonly ProductCaseEventRecorder $eventRecorder
    ) {
    }

    public const VERSION =
        'product_case_photo_manager_v1';

    public const MAX_PHOTOS = 8;

    public const MAX_PHOTO_SIZE_KB = 10240;

    /**
     * Carica una fotografia privata nella pratica.
     *
     * Se lo stesso contenuto è già presente, restituisce il media
     * esistente senza creare un duplicato.
     */
    public function addPhoto(
        ProductCase $productCase,
        User $uploadedBy,
        UploadedFile $photo
    ): Media {
        $productCaseId = $productCase->getKey();
        $userId = $uploadedBy->getKey();

        $this->ensurePersistedIdentifiers(
            productCaseId: $productCaseId,
            userId: $userId,
        );

        $this->validatePhoto($photo);

        $realPath = $photo->getRealPath();

        if (
            ! is_string($realPath)
            || ! is_file($realPath)
        ) {
            throw ValidationException::withMessages([
                'photo' =>
                    'Il file della fotografia non è disponibile.',
            ]);
        }

        $sha256 = hash_file(
            'sha256',
            $realPath
        );

        if (! is_string($sha256)) {
            throw ValidationException::withMessages([
                'photo' =>
                    'Non è stato possibile verificare la fotografia.',
            ]);
        }

        return DB::transaction(function () use (
            $productCaseId,
            $userId,
            $photo,
            $sha256
        ): Media {
            $context = $this->loadContext(
                productCaseId: $productCaseId,
                userId: $userId,
            );

            $productCase =
                $context['product_case'];

            $uploadedBy =
                $context['user'];

            $this->ensureUserCanManageCase(
                productCase: $productCase,
                user: $uploadedBy,
            );

            $this->ensurePhotosAreMutable(
                $productCase
            );

            /*
             * Il lock sulla pratica serializza upload concorrenti.
             * Un retry con lo stesso contenuto non crea un secondo media.
             */
            $existingMedia = $productCase
                ->getMedia(
                    ProductCase::MEDIA_COLLECTION_ISSUE_PHOTOS
                )
                ->first(function (
                    Media $media
                ) use ($sha256): bool {
                    $storedHash =
                        $media->getCustomProperty(
                            'sha256'
                        );

                    return is_string($storedHash)
                        && hash_equals(
                            $storedHash,
                            $sha256
                        );
                });

            if ($existingMedia instanceof Media) {
                return $existingMedia;
            }

            $currentCount = $productCase
                ->getMedia(
                    ProductCase::MEDIA_COLLECTION_ISSUE_PHOTOS
                )
                ->count();

            if ($currentCount >= self::MAX_PHOTOS) {
                throw ValidationException::withMessages([
                    'photo' =>
                        'La pratica può contenere al massimo '
                        . self::MAX_PHOTOS
                        . ' fotografie.',
                ]);
            }

            $mimeType = $photo->getMimeType();

            $extension = match ($mimeType) {
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',

                default => throw ValidationException::withMessages([
                    'photo' =>
                        'Il formato della fotografia non è supportato.',
                ]),
            };

            $originalFilename = trim(
                $photo->getClientOriginalName()
            );

            $displayName = trim(
                pathinfo(
                    $originalFilename,
                    PATHINFO_FILENAME
                )
            );

            if ($displayName === '') {
                $displayName = 'Fotografia problema';
            }

            $displayName = Str::limit(
                $displayName,
                255,
                ''
            );

            $storedFilename =
                'issue-photo-'
                . Str::uuid()
                . '.'
                . $extension;

            $storedMedia = $productCase
                ->addMedia($photo)
                ->usingName($displayName)
                ->usingFileName($storedFilename)
                ->withCustomProperties([
                    'version' =>
                        self::VERSION,

                    'uploaded_by_user_id' =>
                        (int) $uploadedBy->id,

                    'team_id' =>
                        (int) $productCase->team_id,

                    'original_filename' =>
                        $originalFilename,

                    'sha256' =>
                        $sha256,

                    'uploaded_at' =>
                        now()->toISOString(),
                ])
                ->toMediaCollection(
                    ProductCase::MEDIA_COLLECTION_ISSUE_PHOTOS,
                    'local'
                );

            try {
                $this->eventRecorder
                    ->recordPhotoAdded(
                        productCase:
                            $productCase,

                        actor:
                            $uploadedBy,

                        media:
                            $storedMedia,
                    );
            } catch (Throwable $exception) {
                /*
                 * Il rollback SQL non rimuove automaticamente il file fisico.
                 * Se la timeline fallisce, eliminiamo quindi anche il media.
                 */
                $storedMedia->delete();

                throw $exception;
            }

            return $storedMedia;
        });
    }

    /**
     * Rimuove una fotografia dalla pratica.
     *
     * Restituisce false quando il media era già stato eliminato.
     */
    public function removePhoto(
        ProductCase $productCase,
        User $removedBy,
        Media $media
    ): bool {
        $productCaseId = $productCase->getKey();
        $userId = $removedBy->getKey();
        $mediaId = $media->getKey();

        $this->ensurePersistedIdentifiers(
            productCaseId: $productCaseId,
            userId: $userId,
        );

        if ($mediaId === null) {
            throw new RuntimeException(
                'La fotografia deve essere persistita prima della rimozione.'
            );
        }

        return DB::transaction(function () use (
            $productCaseId,
            $userId,
            $mediaId
        ): bool {
            $context = $this->loadContext(
                productCaseId: $productCaseId,
                userId: $userId,
            );

            $productCase =
                $context['product_case'];

            $removedBy =
                $context['user'];

            $this->ensureUserCanManageCase(
                productCase: $productCase,
                user: $removedBy,
            );

            $this->ensurePhotosAreMutable(
                $productCase
            );

            $storedMedia = Media::query()
                ->lockForUpdate()
                ->find($mediaId);

            if ($storedMedia === null) {
                return false;
            }

            if (
                $storedMedia->model_type
                    !== $productCase->getMorphClass()
                || (int) $storedMedia->model_id
                    !== (int) $productCase->id
                || $storedMedia->collection_name
                    !== ProductCase::MEDIA_COLLECTION_ISSUE_PHOTOS
            ) {
                throw new RuntimeException(
                    'La fotografia non appartiene alla pratica.'
                );
            }

            /*
             * Registriamo lo snapshot prima della cancellazione del media.
             *
             * Evento e record media sono nella stessa transazione database.
             * I dati tecnici della fotografia restano così nella timeline.
             */
            $this->eventRecorder
                ->recordPhotoRemoved(
                    productCase:
                        $productCase,

                    actor:
                        $removedBy,

                    media:
                        $storedMedia,
                );

            $storedMedia->delete();

            return true;
        });
    }

    /**
     * @return array{
     *     product_case: ProductCase,
     *     user: User
     * }
     */
    private function loadContext(
        int $productCaseId,
        int $userId
    ): array {
        $productCase = ProductCase::query()
            ->with('team')
            ->lockForUpdate()
            ->find($productCaseId);

        if ($productCase === null) {
            throw new RuntimeException(
                'La pratica non è più disponibile.'
            );
        }

        $user = User::query()
            ->lockForUpdate()
            ->find($userId);

        if ($user === null) {
            throw new RuntimeException(
                'L’utente non è più disponibile.'
            );
        }

        return [
            'product_case' => $productCase,
            'user' => $user,
        ];
    }

    private function ensureUserCanManageCase(
        ProductCase $productCase,
        User $user
    ): void {
        if (
            $productCase->team_id === null
            || $productCase->team === null
        ) {
            throw new RuntimeException(
                'La pratica non appartiene a un team valido.'
            );
        }

        if (
            (int) $user->current_team_id
                !== (int) $productCase->team_id
            || ! $user->belongsToTeam(
                $productCase->team
            )
        ) {
            throw new RuntimeException(
                'L’utente non può gestire le fotografie di una pratica appartenente a un altro team.'
            );
        }
    }

    private function ensurePhotosAreMutable(
        ProductCase $productCase
    ): void {
        if (
            ! in_array(
                $productCase->status,
                ProductCase::STATUSES,
                true
            )
        ) {
            throw new RuntimeException(
                'Lo stato corrente della pratica non è valido.'
            );
        }

        if (
            in_array(
                $productCase->status,
                [
                    ProductCase::STATUS_CLOSED,
                    ProductCase::STATUS_CANCELLED,
                ],
                true
            )
        ) {
            throw new RuntimeException(
                'Le fotografie non possono essere modificate quando la pratica è chiusa o annullata.'
            );
        }
    }

    private function ensurePersistedIdentifiers(
        mixed $productCaseId,
        mixed $userId
    ): void {
        if ($productCaseId === null) {
            throw new RuntimeException(
                'La pratica deve essere persistita prima di gestire fotografie.'
            );
        }

        if ($userId === null) {
            throw new RuntimeException(
                'L’utente deve essere persistito prima di gestire fotografie.'
            );
        }
    }

    private function validatePhoto(
        UploadedFile $photo
    ): void {
        Validator::make(
            [
                'photo' => $photo,
            ],
            [
                'photo' => [
                    'required',
                    'file',
                    'image',
                    'mimetypes:'
                        . implode(
                            ',',
                            ProductCase::ISSUE_PHOTO_MIME_TYPES
                        ),
                    'max:'
                        . self::MAX_PHOTO_SIZE_KB,
                ],
            ]
        )->validate();
    }
}