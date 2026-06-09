<?php

namespace App\Console\Commands\ProductVault;

use App\Models\Brand;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('product-vault:seed-initial-knowledge {--dry-run : Validate and preview the knowledge pack without writing to the database}')]
#[Description('Seed the initial Product Vault knowledge pack')]
class SeedInitialKnowledgeCommand extends Command
{
    /**
     * Importa il primo knowledge pack controllato.
     *
     * La prima versione importa solo brand globali perché:
     * - la tabella brands esiste già;
     * - i brand sono attualmente vuoti nel database locale;
     * - categorie e line type sono già gestiti da seeder dedicati;
     * - alias e pattern restano file dati versionati finché non vengono integrati nei service.
     */
    public function handle(): int
    {
        $packPath = base_path('data/product_vault/knowledge/v1');

        try {
            $metadata = $this->loadKnowledgeFile($packPath.'/metadata.php', 'metadata');
            $brands = $this->loadKnowledgeFile($packPath.'/brands.php', 'brands');
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $errors = [
            ...$this->validateMetadata($metadata),
            ...$this->validateBrands($brands),
        ];

        if ($errors !== []) {
            $this->error('Initial knowledge pack is not valid.');

            foreach ($errors as $error) {
                $this->line('- '.$error);
            }

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        $created = 0;
        $updated = 0;
        $unchanged = 0;

        $importBrands = function () use ($brands, $dryRun, &$created, &$updated, &$unchanged): void {
            foreach ($brands as $brandData) {
                $payload = [
                    'team_id' => null,
                    'name' => trim($brandData['name']),
                    'normalized_name' => $this->normalize($brandData['normalized_name']),
                    'website' => $brandData['website'] ?? null,
                    'is_verified' => (bool) ($brandData['is_verified'] ?? false),
                    'is_active' => true,
                ];

                $brand = Brand::query()
                    ->whereNull('team_id')
                    ->where('normalized_name', $payload['normalized_name'])
                    ->first();

                if (! $brand) {
                    $created++;

                    if (! $dryRun) {
                        Brand::query()->create($payload);
                    }

                    continue;
                }

                /*
                 * Fill + isDirty permette di distinguere update reali da record già allineati.
                 * Questo rende l'import idempotente e leggibile nei riepiloghi del comando.
                 */
                $brand->fill($payload);

                if ($brand->isDirty()) {
                    $updated++;

                    if (! $dryRun) {
                        $brand->save();
                    }

                    continue;
                }

                $unchanged++;
            }
        };

        if ($dryRun) {
            $importBrands();
        } else {
            DB::transaction($importBrands);
        }

        $this->info($dryRun
            ? 'Initial knowledge pack validated in dry-run mode.'
            : 'Initial knowledge pack imported.'
        );

        $this->table(
            ['Metric', 'Value'],
            [
                ['version', $metadata['version']],
                ['dry_run', $dryRun ? 'yes' : 'no'],
                ['brands_in_pack', count($brands)],
                ['brands_created', $created],
                ['brands_updated', $updated],
                ['brands_unchanged', $unchanged],
                ['global_facts_created', 0],
                ['feedback_created', 0],
            ],
        );

        return self::SUCCESS;
    }

    /**
     * Carica un file PHP del knowledge pack e verifica che ritorni un array.
     */
    private function loadKnowledgeFile(string $path, string $label): array
    {
        if (! file_exists($path)) {
            throw new \RuntimeException("Missing knowledge pack file [{$label}]: {$path}");
        }

        $data = require $path;

        if (! is_array($data)) {
            throw new \RuntimeException("Knowledge pack file [{$label}] must return an array.");
        }

        return $data;
    }

    /**
     * Valida i metadata minimi del pack.
     *
     * Questi controlli impediscono che il comando venga usato per importare
     * dati più invasivi, come global facts o feedback utente.
     */
    private function validateMetadata(array $metadata): array
    {
        $errors = [];

        if (($metadata['version'] ?? null) !== 'initial_knowledge_pack_v1') {
            $errors[] = 'metadata.version must be initial_knowledge_pack_v1.';
        }

        if (($metadata['imports']['brands'] ?? null) !== true) {
            $errors[] = 'metadata.imports.brands must be true.';
        }

        if (($metadata['imports']['global_facts'] ?? null) !== false) {
            $errors[] = 'metadata.imports.global_facts must be false for the first implementation.';
        }

        if (($metadata['rules']['do_not_create_global_facts'] ?? null) !== true) {
            $errors[] = 'metadata.rules.do_not_create_global_facts must be true.';
        }

        if (($metadata['rules']['do_not_touch_user_feedback'] ?? null) !== true) {
            $errors[] = 'metadata.rules.do_not_touch_user_feedback must be true.';
        }

        if (($metadata['rules']['do_not_touch_user_products'] ?? null) !== true) {
            $errors[] = 'metadata.rules.do_not_touch_user_products must be true.';
        }

        return $errors;
    }

    /**
     * Valida i brand prima dell'import.
     *
     * I brand sono globali e vengono importati con team_id null.
     * Non devono contenere duplicati normalizzati nel pack.
     */
    private function validateBrands(array $brands): array
    {
        $errors = [];
        $seen = [];

        foreach ($brands as $index => $brand) {
            $row = $index + 1;

            if (! is_array($brand)) {
                $errors[] = "brands row {$row} must be an array.";

                continue;
            }

            $name = trim((string) ($brand['name'] ?? ''));
            $normalizedName = $this->normalize((string) ($brand['normalized_name'] ?? ''));

            if ($name === '') {
                $errors[] = "brands row {$row} is missing name.";
            }

            if ($normalizedName === '') {
                $errors[] = "brands row {$row} is missing normalized_name.";
            }

            if ($normalizedName !== '' && isset($seen[$normalizedName])) {
                $errors[] = "brands row {$row} duplicates normalized_name [{$normalizedName}].";
            }

            $seen[$normalizedName] = true;

            if (array_key_exists('team_id', $brand)) {
                $errors[] = "brands row {$row} must not define team_id. Initial knowledge brands are global.";
            }
        }

        return $errors;
    }

    /**
     * Normalizzazione minima coerente per dati knowledge pack.
     */
    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return $value;
    }
}