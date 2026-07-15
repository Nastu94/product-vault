<?php

namespace App\Services\Monetization;

use App\Models\Document;
use App\Models\Product;
use App\Models\ProductCase;
use App\Models\Team;
use App\Models\UsageEvent;
use App\Support\Monetization\MonetizationKeys;
use Illuminate\Support\Facades\DB;

final class UsageSnapshotResolver
{
    public function __construct(
        private readonly PlanEntitlementResolver $entitlementResolver
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function resolve(Team $team): array
    {
        $teamId = (int) $team->getKey();
        $entitlements = $this->entitlementResolver->resolve($team);

        $documentsCount = Document::query()
            ->where('team_id', $teamId)
            ->count();

        $productsCount = Product::query()
            ->where('team_id', $teamId)
            ->count();

        $storageBytes = (int) Document::query()
            ->where('team_id', $teamId)
            ->sum('file_size');

        $storageMbPrecise = round(
            $storageBytes / 1024 / 1024,
            2
        );

        $storageMbForLimit = $storageBytes === 0
            ? 0
            : (int) ceil($storageBytes / 1024 / 1024);

        $teamMembersWithoutOwner = DB::table('team_user')
            ->where('team_id', $teamId)
            ->distinct()
            ->count('user_id');

        $pendingInvitationsCount = DB::table('team_invitations')
            ->where('team_id', $teamId)
            ->count();

        $teamMembersCount = 1
            + $teamMembersWithoutOwner
            + $pendingInvitationsCount;

        $openProductCasesCount = ProductCase::query()
            ->where('team_id', $teamId)
            ->whereIn('status', [
                ProductCase::STATUS_DRAFT,
                ProductCase::STATUS_READY_TO_CONTACT,
                ProductCase::STATUS_CONTACTED,
                ProductCase::STATUS_RESOLVED,
            ])
            ->count();

        $periodStart = now()->startOfMonth();
        $periodEnd = now()->endOfMonth();

        $ocrRunsThisMonth = (int) UsageEvent::query()
            ->where('team_id', $teamId)
            ->where('event_key', MonetizationKeys::EVENT_OCR_RUN)
            ->whereBetween('occurred_at', [
                $periodStart,
                $periodEnd,
            ])
            ->sum('quantity');

        $usageValues = [
            MonetizationKeys::LIMIT_MAX_DOCUMENTS => $documentsCount,
            MonetizationKeys::LIMIT_MAX_PRODUCTS => $productsCount,
            MonetizationKeys::LIMIT_MAX_STORAGE_MB => $storageMbForLimit,
            MonetizationKeys::LIMIT_MAX_OCR_PER_MONTH => $ocrRunsThisMonth,
            MonetizationKeys::LIMIT_MAX_TEAM_MEMBERS => $teamMembersCount,
            MonetizationKeys::LIMIT_MAX_OPEN_PRODUCT_CASES =>
                $openProductCasesCount,
        ];

        $labels = [
            MonetizationKeys::LIMIT_MAX_DOCUMENTS => 'Documenti',
            MonetizationKeys::LIMIT_MAX_PRODUCTS => 'Prodotti',
            MonetizationKeys::LIMIT_MAX_STORAGE_MB => 'Spazio archivio',
            MonetizationKeys::LIMIT_MAX_OCR_PER_MONTH => 'OCR mensili',
            MonetizationKeys::LIMIT_MAX_TEAM_MEMBERS => 'Membri workspace',
            MonetizationKeys::LIMIT_MAX_OPEN_PRODUCT_CASES =>
                'Pratiche aperte',
        ];

        $units = [
            MonetizationKeys::LIMIT_MAX_STORAGE_MB => 'MB',
        ];

        $resources = [];

        foreach (MonetizationKeys::limitKeys() as $limitKey) {
            $limit = data_get(
                $entitlements,
                'limits.' . $limitKey,
                []
            );

            $used = (int) ($usageValues[$limitKey] ?? 0);
            $limitValue = data_get($limit, 'value');
            $isConfigured = is_array($limit) && $limit !== [];
            $isUnlimited = $isConfigured && $limitValue === null;
            $remaining = is_int($limitValue)
                ? max(0, $limitValue - $used)
                : null;
            $percentage = is_int($limitValue) && $limitValue > 0
                ? min(
                    999,
                    (int) round(($used / $limitValue) * 100)
                )
                : null;

            $warningThreshold = max(
                1,
                min(
                    100,
                    (int) config(
                        'monetization.warning_threshold_percent',
                        80
                    )
                )
            );

            $status = match (true) {
                ! $isConfigured => 'unconfigured',
                $isUnlimited => 'unlimited',
                is_int($limitValue) && $used >= $limitValue =>
                    'exceeded',
                is_int($percentage)
                    && $percentage >= $warningThreshold =>
                    'warning',
                default => 'available',
            };

            $resources[$limitKey] = [
                'key' => $limitKey,
                'label' => $labels[$limitKey] ?? $limitKey,
                'unit' => $units[$limitKey] ?? null,
                'used' => $used,
                'limit' => is_int($limitValue) ? $limitValue : null,
                'remaining' => $remaining,
                'percentage' => $percentage,
                'status' => $status,
                'is_configured' => $isConfigured,
                'is_unlimited' => $isUnlimited,
                'reset_period' => data_get(
                    $limit,
                    'reset_period',
                    'none'
                ),
                'description' => data_get($limit, 'description'),
                'plan_limit_id' => data_get(
                    $limit,
                    'plan_limit_id'
                ),
            ];
        }

        return [
            'team_id' => $teamId,
            'plan' => $entitlements['plan'] ?? null,
            'resources' => $resources,
            'raw' => [
                'documents_count' => $documentsCount,
                'products_count' => $productsCount,
                'storage_bytes' => $storageBytes,
                'storage_mb' => $storageMbPrecise,
                'team_members_count' => $teamMembersCount,
                'team_members_without_owner' =>
                    $teamMembersWithoutOwner,
                'pending_invitations_count' =>
                    $pendingInvitationsCount,
                'open_product_cases_count' =>
                    $openProductCasesCount,
                'ocr_runs_this_month' => $ocrRunsThisMonth,
                'period_starts_at' =>
                    $periodStart->toDateString(),
                'period_ends_at' =>
                    $periodEnd->toDateString(),
            ],
            'enforcement_mode' => (string) config(
                'monetization.enforcement_mode',
                'observe'
            ),
        ];
    }
}
