<?php

namespace App\Support\Monetization;

final class MonetizationKeys
{
    public const LIMIT_MAX_DOCUMENTS = 'max_documents';
    public const LIMIT_MAX_PRODUCTS = 'max_products';
    public const LIMIT_MAX_STORAGE_MB = 'max_storage_mb';
    public const LIMIT_MAX_OCR_PER_MONTH = 'max_ocr_per_month';
    public const LIMIT_MAX_TEAM_MEMBERS = 'max_team_members';
    public const LIMIT_MAX_OPEN_PRODUCT_CASES = 'max_open_product_cases';

    public const FEATURE_MANUAL_UPLOAD = 'manual_upload';
    public const FEATURE_BASE_EXTRACTION = 'base_extraction';
    public const FEATURE_MANUAL_REVIEW = 'manual_review';
    public const FEATURE_PRODUCT_ARCHIVE = 'product_archive';
    public const FEATURE_ESTIMATED_COVERAGE = 'estimated_coverage';
    public const FEATURE_ESSENTIAL_REMINDERS = 'essential_reminders';
    public const FEATURE_ADVANCED_ASSISTED_REVIEW = 'advanced_assisted_review';
    public const FEATURE_EMAIL_IMPORT = 'email_import';
    public const FEATURE_SHARED_WORKSPACE = 'shared_workspace';
    public const FEATURE_MULTIPLE_PRODUCT_CASES = 'multiple_product_cases';
    public const FEATURE_EXPORT_ASSISTANCE_DOSSIER = 'export_assistance_dossier';
    public const FEATURE_ADVANCED_NOTIFICATIONS = 'advanced_notifications';
    public const FEATURE_FULL_HISTORY = 'full_history';
    public const FEATURE_BUSINESS_ASSET_ASSIGNMENT = 'business_asset_assignment';
    public const FEATURE_BUSINESS_AUDIT_LOG = 'business_audit_log';
    public const FEATURE_API_INTEGRATIONS = 'api_integrations';

    public const EVENT_DOCUMENT_UPLOADED = 'document_uploaded';
    public const EVENT_STORAGE_BYTES_ADDED = 'storage_bytes_added';
    public const EVENT_OCR_RUN = 'ocr_run';
    public const EVENT_PRODUCT_CREATED = 'product_created';
    public const EVENT_PRODUCT_CASE_OPENED = 'product_case_opened';
    public const EVENT_PRODUCT_CASE_RESOLVED = 'product_case_resolved';
    public const EVENT_PRODUCT_CASE_CLOSED = 'product_case_closed';

    /** @return list<string> */
    public static function limitKeys(): array
    {
        return [
            self::LIMIT_MAX_DOCUMENTS,
            self::LIMIT_MAX_PRODUCTS,
            self::LIMIT_MAX_STORAGE_MB,
            self::LIMIT_MAX_OCR_PER_MONTH,
            self::LIMIT_MAX_TEAM_MEMBERS,
            self::LIMIT_MAX_OPEN_PRODUCT_CASES,
        ];
    }

    /** @return list<string> */
    public static function featureKeys(): array
    {
        return [
            self::FEATURE_MANUAL_UPLOAD,
            self::FEATURE_BASE_EXTRACTION,
            self::FEATURE_MANUAL_REVIEW,
            self::FEATURE_PRODUCT_ARCHIVE,
            self::FEATURE_ESTIMATED_COVERAGE,
            self::FEATURE_ESSENTIAL_REMINDERS,
            self::FEATURE_ADVANCED_ASSISTED_REVIEW,
            self::FEATURE_EMAIL_IMPORT,
            self::FEATURE_SHARED_WORKSPACE,
            self::FEATURE_MULTIPLE_PRODUCT_CASES,
            self::FEATURE_EXPORT_ASSISTANCE_DOSSIER,
            self::FEATURE_ADVANCED_NOTIFICATIONS,
            self::FEATURE_FULL_HISTORY,
            self::FEATURE_BUSINESS_ASSET_ASSIGNMENT,
            self::FEATURE_BUSINESS_AUDIT_LOG,
            self::FEATURE_API_INTEGRATIONS,
        ];
    }
}
