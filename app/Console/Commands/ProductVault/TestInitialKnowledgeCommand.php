<?php

namespace App\Console\Commands\ProductVault;

use App\Models\Brand;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use App\Services\Documents\ProductUnderstanding\InitialKnowledgeRepository;

#[Signature('product-vault:test-initial-knowledge')]
#[Description('Run controlled checks for the initial Product Vault knowledge pack')]
class TestInitialKnowledgeCommand extends Command
{
    /**
     * Esegue verifiche controllate sul knowledge pack iniziale.
     *
     * Questo comando non testa fixture Product Understanding e non deve creare
     * global facts, feedback, prodotti, categorie o line type. L'unico import
     * ammesso nella prima versione è quello dei brand globali.
     */
    public function handle(): int
    {
        $rows = [];
        $failures = [];

        $record = function (
            string $scenario,
            string $assertion,
            bool $passed,
            mixed $expected = null,
            mixed $actual = null
        ) use (&$rows, &$failures): void {
            $rows[] = [
                $scenario,
                $assertion,
                $passed ? 'OK' : 'FAIL',
            ];

            if (! $passed) {
                $failures[] = [
                    'scenario' => $scenario,
                    'assertion' => $assertion,
                    'expected' => $expected,
                    'actual' => $actual,
                ];
            }
        };

        $assertEquals = function (
            string $scenario,
            string $assertion,
            mixed $expected,
            mixed $actual
        ) use ($record): void {
            $record($scenario, $assertion, $expected === $actual, $expected, $actual);
        };

        $assertTrue = function (
            string $scenario,
            string $assertion,
            bool $actual
        ) use ($record): void {
            $record($scenario, $assertion, $actual === true, true, $actual);
        };

        $packPath = base_path('data/product_vault/knowledge/v1');

        $metadata = $this->safeLoadKnowledgeFile($packPath.'/metadata.php');
        $brands = $this->safeLoadKnowledgeFile($packPath.'/brands.php');
        $brandAliases = $this->safeLoadKnowledgeFile($packPath.'/brand_aliases.php');
        $linePatterns = $this->safeLoadKnowledgeFile($packPath.'/line_patterns.php');
        $exclusionPatterns = $this->safeLoadKnowledgeFile($packPath.'/exclusion_patterns.php');

        /*
        |--------------------------------------------------------------------------
        | Scenario 1: file e metadata
        |--------------------------------------------------------------------------
        */
        $assertTrue('pack_files', 'metadata file returns array', is_array($metadata));
        $assertTrue('pack_files', 'brands file returns array', is_array($brands));
        $assertTrue('pack_files', 'brand aliases file returns array', is_array($brandAliases));
        $assertTrue('pack_files', 'line patterns file returns array', is_array($linePatterns));
        $assertTrue('pack_files', 'exclusion patterns file returns array', is_array($exclusionPatterns));

        $assertEquals('metadata', 'version', 'initial_knowledge_pack_v1', data_get($metadata, 'version'));
        $assertEquals('metadata', 'brands import enabled', true, data_get($metadata, 'imports.brands'));
        $assertEquals('metadata', 'brand aliases import disabled', false, data_get($metadata, 'imports.brand_aliases'));
        $assertEquals('metadata', 'line patterns import disabled', false, data_get($metadata, 'imports.line_patterns'));
        $assertEquals('metadata', 'exclusion patterns import disabled', false, data_get($metadata, 'imports.exclusion_patterns'));
        $assertEquals('metadata', 'global facts import disabled', false, data_get($metadata, 'imports.global_facts'));
        $assertEquals('metadata', 'do not create global facts', true, data_get($metadata, 'rules.do_not_create_global_facts'));
        $assertEquals('metadata', 'do not touch user feedback', true, data_get($metadata, 'rules.do_not_touch_user_feedback'));
        $assertEquals('metadata', 'do not touch user products', true, data_get($metadata, 'rules.do_not_touch_user_products'));

        /*
        |--------------------------------------------------------------------------
        | Scenario 2: validità brand
        |--------------------------------------------------------------------------
        */
        $expectedBrandCount = 20;
        $normalizedBrandNames = collect($brands)
            ->pluck('normalized_name')
            ->map(fn ($value) => $this->normalize((string) $value))
            ->filter()
            ->values();

        $assertEquals('brands_pack', 'brand count', $expectedBrandCount, count($brands));
        $assertEquals('brands_pack', 'normalized names count', $expectedBrandCount, $normalizedBrandNames->count());
        $assertEquals('brands_pack', 'no duplicate normalized names', $normalizedBrandNames->count(), $normalizedBrandNames->unique()->count());

        foreach ($brands as $index => $brand) {
            $row = $index + 1;

            $assertTrue(
                'brands_pack',
                "row {$row} has name",
                trim((string) ($brand['name'] ?? '')) !== ''
            );

            $assertTrue(
                'brands_pack',
                "row {$row} has normalized_name",
                trim((string) ($brand['normalized_name'] ?? '')) !== ''
            );

            $assertTrue(
                'brands_pack',
                "row {$row} does not define team_id",
                ! array_key_exists('team_id', $brand)
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Scenario 2B: validità alias brand
        |--------------------------------------------------------------------------
        */
        $expectedBrandAliasCount = 10;
        $normalizedAliases = collect($brandAliases)
            ->pluck('normalized_alias')
            ->map(fn ($value) => $this->normalize((string) $value))
            ->filter()
            ->values();

        $knownBrandNames = $normalizedBrandNames->all();

        $assertEquals('brand_aliases_pack', 'brand alias count', $expectedBrandAliasCount, count($brandAliases));
        $assertEquals('brand_aliases_pack', 'normalized aliases count', $expectedBrandAliasCount, $normalizedAliases->count());
        $assertEquals('brand_aliases_pack', 'no duplicate normalized aliases', $normalizedAliases->count(), $normalizedAliases->unique()->count());

        foreach ($brandAliases as $index => $alias) {
            $row = $index + 1;

            $normalizedAlias = $this->normalize((string) ($alias['normalized_alias'] ?? ''));
            $brandNormalizedName = $this->normalize((string) ($alias['brand_normalized_name'] ?? ''));

            $assertTrue(
                'brand_aliases_pack',
                "row {$row} has alias",
                trim((string) ($alias['alias'] ?? '')) !== ''
            );

            $assertTrue(
                'brand_aliases_pack',
                "row {$row} has normalized_alias",
                $normalizedAlias !== ''
            );

            $assertTrue(
                'brand_aliases_pack',
                "row {$row} has brand_normalized_name",
                $brandNormalizedName !== ''
            );

            $assertTrue(
                'brand_aliases_pack',
                "row {$row} points to existing brand",
                in_array($brandNormalizedName, $knownBrandNames, true)
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Scenario 2C: repository knowledge base
        |--------------------------------------------------------------------------
        */
        $repository = app(InitialKnowledgeRepository::class);

        $hpAlias = $repository->findBrandAlias('HEWLETT PACKARD Notebook EliteBook 840 G10');

        $assertEquals(
            'knowledge_repository',
            'hewlett packard resolves to hp',
            'hp',
            $hpAlias['brand_normalized_name'] ?? null
        );

        $discountSuppression = $repository->matchCandidateSuppressionPattern(
            description: 'Sconto promozionale',
            rawText: 'Sconto promozionale',
            documentLineTypeCode: 'discount',
        );

        $assertEquals(
            'knowledge_repository',
            'discount line suppression matched',
            'discount_line',
            $discountSuppression['reason'] ?? null
        );

        $productWithDiscountWord = $repository->matchCandidateSuppressionPattern(
            description: 'Notebook Lenovo con sconto incluso',
            rawText: 'Notebook Lenovo con sconto incluso',
            documentLineTypeCode: 'product',
        );

        $assertEquals(
            'knowledge_repository',
            'product line with discount word is not suppressed',
            null,
            $productWithDiscountWord
        );

        $notebookPatterns = $repository->matchLinePatterns(
            description: 'Notebook Lenovo ThinkPad X1 Carbon Gen 11',
            rawText: 'Notebook Lenovo ThinkPad X1 Carbon Gen 11',
            documentLineTypeCode: 'product',
        );

        $assertEquals(
            'knowledge_repository',
            'notebook line pattern matched',
            'notebook',
            $notebookPatterns[0]['pattern'] ?? null
        );

        $notebookTypoPatterns = $repository->matchLinePatterns(
            description: 'Notebok Lenovo ThinkPad X1 Carbon Gen 11',
            rawText: 'Notebok Lenovo ThinkPad X1 Carbon Gen 11',
            documentLineTypeCode: 'product',
        );

        $assertEquals(
            'knowledge_repository',
            'notebook typo pattern matched',
            'notebook',
            $notebookTypoPatterns[0]['pattern'] ?? null
        );

        $assertEquals(
            'knowledge_repository',
            'notebook typo pattern match type',
            'fuzzy_pattern',
            $notebookTypoPatterns[0]['match_type'] ?? null
        );

        $notebookTypoSummary = $repository->summarizeLinePatternMatches($notebookTypoPatterns);

        $assertEquals(
            'knowledge_repository',
            'notebook typo summary best pattern',
            'notebook',
            $notebookTypoSummary['best_positive_pattern'] ?? null
        );

        $assertEquals(
            'knowledge_repository',
            'notebook typo summary fuzzy count',
            1,
            $notebookTypoSummary['fuzzy_pattern_count'] ?? null
        );

        $assertEquals(
            'knowledge_repository',
            'notebook typo summary fuzzy positive',
            true,
            $notebookTypoSummary['has_fuzzy_positive_match'] ?? null
        );

        $stampanteTypoPatterns = $repository->matchLinePatterns(
            description: 'Stanpamte Epson EcoTank ET-2850',
            rawText: 'Stanpamte Epson EcoTank ET-2850',
            documentLineTypeCode: 'product',
        );

        $assertEquals(
            'knowledge_repository',
            'stampante typo pattern matched',
            'stampante',
            $stampanteTypoPatterns[0]['pattern'] ?? null
        );

        $assertEquals(
            'knowledge_repository',
            'stampante typo pattern match type',
            'fuzzy_pattern',
            $stampanteTypoPatterns[0]['match_type'] ?? null
        );

        $stampanteTypoSummary = $repository->summarizeLinePatternMatches($stampanteTypoPatterns);

        $assertEquals(
            'knowledge_repository',
            'stampante typo summary best pattern',
            'stampante',
            $stampanteTypoSummary['best_positive_pattern'] ?? null
        );

        $assertEquals(
            'knowledge_repository',
            'stampante typo summary product kind',
            'durable_product',
            $stampanteTypoSummary['best_product_kind'] ?? null
        );

        $assertEquals(
            'knowledge_repository',
            'notebook pattern line type matches',
            true,
            $notebookPatterns[0]['line_type_matches_document_line_type'] ?? null
        );

        $shippingPatterns = $repository->matchLinePatterns(
            description: 'Spedizione express',
            rawText: 'Spedizione express',
            documentLineTypeCode: 'unknown',
        );

        $assertEquals(
            'knowledge_repository',
            'shipping service pattern matched',
            'spedizione',
            $shippingPatterns[0]['pattern'] ?? null
        );

        $nasPatterns = $repository->matchLinePatterns(
            description: 'NAS TerraVault Home Duo 8TB',
            rawText: 'NAS TerraVault Home Duo 8TB',
            documentLineTypeCode: 'product',
        );

        $nasSummary = $repository->summarizeLinePatternMatches($nasPatterns);

        $assertEquals(
            'knowledge_repository',
            'nas pattern matched',
            'nas',
            $nasSummary['best_positive_pattern'] ?? null
        );

        $assertEquals(
            'knowledge_repository',
            'nas pattern category',
            'computers',
            $nasSummary['best_suggested_category_slug'] ?? null
        );

        $fotocameraPatterns = $repository->matchLinePatterns(
            description: 'Fotocamera LumioShot Z5 Mirrorless',
            rawText: 'Fotocamera LumioShot Z5 Mirrorless',
            documentLineTypeCode: 'product',
        );

        $fotocameraSummary = $repository->summarizeLinePatternMatches($fotocameraPatterns);

        $assertEquals(
            'knowledge_repository',
            'fotocamera pattern matched',
            'fotocamera',
            $fotocameraSummary['best_positive_pattern'] ?? null
        );

        $assertEquals(
            'knowledge_repository',
            'fotocamera pattern category',
            'electronics',
            $fotocameraSummary['best_suggested_category_slug'] ?? null
        );

        $obiettivoPatterns = $repository->matchLinePatterns(
            description: 'Obiettivo LumioPrime 35mm F1.8',
            rawText: 'Obiettivo LumioPrime 35mm F1.8',
            documentLineTypeCode: 'product',
        );

        $obiettivoSummary = $repository->summarizeLinePatternMatches($obiettivoPatterns);

        $assertEquals(
            'knowledge_repository',
            'obiettivo pattern matched',
            'obiettivo',
            $obiettivoSummary['best_positive_pattern'] ?? null
        );

        $gimbalPatterns = $repository->matchLinePatterns(
            description: 'Stabilizzatore Gimbal SteadyCam Mini 3',
            rawText: 'Stabilizzatore Gimbal SteadyCam Mini 3',
            documentLineTypeCode: 'product',
        );

        $gimbalSummary = $repository->summarizeLinePatternMatches($gimbalPatterns);

        $assertEquals(
            'knowledge_repository',
            'gimbal pattern matched',
            'gimbal',
            $gimbalSummary['best_positive_pattern'] ?? null
        );

        /*
        |--------------------------------------------------------------------------
        | Scenario 2D: audit command smoke checks
        |--------------------------------------------------------------------------
        |
        | Il comando audit lavora sui candidati realmente presenti nel database.
        | Per questo motivo non facciamo assert su nomi specifici come Stanpamte
        | o Notebok: renderebbe il test dipendente dai dati locali.
        |
        | Qui verifichiamo invece che i nuovi filtri fuzzy siano eseguibili,
        | producano sempre output leggibile e validino correttamente la soglia.
        */
        $onlyFuzzyExitCode = Artisan::call('product-vault:audit-initial-knowledge', [
            '--only-fuzzy' => true,
            '--limit' => 200,
        ]);

        $onlyFuzzyOutput = Artisan::output();

        $assertEquals(
            'audit_command',
            'only fuzzy exit code',
            self::SUCCESS,
            $onlyFuzzyExitCode
        );

        $assertTrue(
            'audit_command',
            'only fuzzy output is not empty',
            trim($onlyFuzzyOutput) !== ''
        );

        $assertTrue(
            'audit_command',
            'only fuzzy output is readable',
            str_contains($onlyFuzzyOutput, 'fuzzy_pattern')
                || str_contains($onlyFuzzyOutput, 'Nessun candidato trovato')
        );

        $maxSimilarityExitCode = Artisan::call('product-vault:audit-initial-knowledge', [
            '--only-fuzzy' => true,
            '--max-similarity' => '0.85',
            '--limit' => 200,
        ]);

        $maxSimilarityOutput = Artisan::output();

        $assertEquals(
            'audit_command',
            'max similarity exit code',
            self::SUCCESS,
            $maxSimilarityExitCode
        );

        $assertTrue(
            'audit_command',
            'max similarity output is not empty',
            trim($maxSimilarityOutput) !== ''
        );

        $assertTrue(
            'audit_command',
            'max similarity output is readable',
            str_contains($maxSimilarityOutput, 'fuzzy_pattern')
                || str_contains($maxSimilarityOutput, 'Nessun candidato trovato')
        );

        $invalidSimilarityExitCode = Artisan::call('product-vault:audit-initial-knowledge', [
            '--max-similarity' => 'abc',
        ]);

        $invalidSimilarityOutput = Artisan::output();

        $assertEquals(
            'audit_command',
            'invalid max similarity exit code',
            self::FAILURE,
            $invalidSimilarityExitCode
        );

        $assertTrue(
            'audit_command',
            'invalid max similarity output',
            str_contains($invalidSimilarityOutput, 'deve essere numerico')
        );

        $outOfRangeSimilarityExitCode = Artisan::call('product-vault:audit-initial-knowledge', [
            '--max-similarity' => '1.5',
        ]);

        $outOfRangeSimilarityOutput = Artisan::output();

        $assertEquals(
            'audit_command',
            'out of range max similarity exit code',
            self::FAILURE,
            $outOfRangeSimilarityExitCode
        );

        $assertTrue(
            'audit_command',
            'out of range max similarity output',
            str_contains($outOfRangeSimilarityOutput, 'compreso tra 0 e 1')
        );

        /*
        |--------------------------------------------------------------------------
        | Scenario 3: pattern coerenti con line type reali
        |--------------------------------------------------------------------------
        */
        $allowedLineTypes = DB::table('document_line_types')
            ->pluck('code')
            ->all();

        $assertEquals(
            'line_types',
            'real line types',
            ['discount', 'merchant_info', 'payment', 'product', 'tax', 'total', 'unknown'],
            $allowedLineTypes
        );

        foreach ($this->extractPatternRows($linePatterns) as $index => $pattern) {
            $row = $index + 1;

            $assertTrue(
                'line_patterns',
                "row {$row} has allowed document_line_type",
                in_array($pattern['document_line_type'] ?? null, $allowedLineTypes, true)
            );
        }

        foreach ($this->extractPatternRows($exclusionPatterns) as $index => $pattern) {
            $row = $index + 1;

            $assertTrue(
                'exclusion_patterns',
                "row {$row} has allowed document_line_type",
                in_array($pattern['document_line_type'] ?? null, $allowedLineTypes, true)
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Scenario 4: protezione dati non coinvolti
        |--------------------------------------------------------------------------
        */
        $before = $this->protectedCounts();

        $dryRunExitCode = Artisan::call('product-vault:seed-initial-knowledge', [
            '--dry-run' => true,
        ]);

        $afterDryRun = $this->protectedCounts();

        $assertEquals('dry_run', 'exit code', 0, $dryRunExitCode);
        $assertEquals('dry_run', 'protected counts unchanged', $before, $afterDryRun);

        /*
        |--------------------------------------------------------------------------
        | Scenario 5: import reale e idempotenza
        |--------------------------------------------------------------------------
        */
        $firstImportExitCode = Artisan::call('product-vault:seed-initial-knowledge');

        $afterFirstImport = $this->protectedCounts();

        $assertEquals('first_import', 'exit code', 0, $firstImportExitCode);
        $assertEquals('first_import', 'global facts unchanged', $before['global_facts'], $afterFirstImport['global_facts']);
        $assertEquals('first_import', 'feedback unchanged', $before['feedback'], $afterFirstImport['feedback']);
        $assertEquals('first_import', 'products unchanged', $before['products'], $afterFirstImport['products']);
        $assertEquals('first_import', 'categories unchanged', $before['categories'], $afterFirstImport['categories']);
        $assertEquals('first_import', 'document line types unchanged', $before['document_line_types'], $afterFirstImport['document_line_types']);

        $importedBrands = Brand::query()
            ->whereNull('team_id')
            ->whereIn('normalized_name', $normalizedBrandNames->all())
            ->get();

        $assertEquals('first_import', 'expected global brands imported', $expectedBrandCount, $importedBrands->count());
        $assertEquals('first_import', 'imported brands verified', $expectedBrandCount, $importedBrands->where('is_verified', true)->count());
        $assertEquals('first_import', 'imported brands active', $expectedBrandCount, $importedBrands->where('is_active', true)->count());

        $brandCountAfterFirstImport = Brand::query()->count();

        $secondImportExitCode = Artisan::call('product-vault:seed-initial-knowledge');

        $afterSecondImport = $this->protectedCounts();
        $brandCountAfterSecondImport = Brand::query()->count();

        $assertEquals('second_import', 'exit code', 0, $secondImportExitCode);
        $assertEquals('second_import', 'brand count unchanged', $brandCountAfterFirstImport, $brandCountAfterSecondImport);
        $assertEquals('second_import', 'protected counts unchanged', $afterFirstImport, $afterSecondImport);

        $this->table(['Scenario', 'Assertion', 'Status'], $rows);

        if ($failures !== []) {
            $this->error('Initial knowledge checks failed.');

            foreach ($failures as $failure) {
                $this->line('');
                $this->warn($failure['scenario'].' / '.$failure['assertion']);
                $this->line('Expected: '.json_encode($failure['expected'], JSON_UNESCAPED_UNICODE));
                $this->line('Actual:   '.json_encode($failure['actual'], JSON_UNESCAPED_UNICODE));
            }

            return self::FAILURE;
        }

        $this->info('Initial knowledge checks passed.');

        return self::SUCCESS;
    }

    /**
     * Carica un file dati del knowledge pack senza interrompere brutalmente il comando.
     */
    private function safeLoadKnowledgeFile(string $path): array
    {
        if (! file_exists($path)) {
            return [];
        }

        $data = require $path;

        return is_array($data) ? $data : [];
    }

    /**
     * Estrae righe pattern da strutture raggruppate.
     */
    private function extractPatternRows(array $groups): array
    {
        $rows = [];

        foreach ($groups as $group) {
            if (! is_array($group)) {
                continue;
            }

            foreach ($group as $item) {
                if (is_array($item) && array_key_exists('document_line_type', $item)) {
                    $rows[] = $item;
                }
            }
        }

        return $rows;
    }

    /**
     * Conteggi che il seed iniziale non deve modificare.
     */
    private function protectedCounts(): array
    {
        return [
            'categories' => DB::table('categories')->count(),
            'document_line_types' => DB::table('document_line_types')->count(),
            'global_facts' => DB::table('product_understanding_global_facts')->count(),
            'feedback' => DB::table('product_understanding_feedback')->count(),
            'products' => DB::table('products')->count(),
        ];
    }

    /**
     * Normalizzazione minima per confrontare i dati del pack.
     */
    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return $value;
    }
}