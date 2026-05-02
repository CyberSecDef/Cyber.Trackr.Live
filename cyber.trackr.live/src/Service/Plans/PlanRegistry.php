<?php

namespace App\Service\Plans;

/**
 * Discovers family-plan schema files in resources/data/plans/.
 *
 * One file per family (cm.json, ac.json, etc.) plus the shared file
 * (_shared.json, hidden from the public list). Adding a new family is a
 * matter of dropping a new schema file in this directory; PlanRegistry
 * auto-discovers it and PlansController auto-routes it.
 *
 * Schemas are JSON-decoded and cached per request.
 */
class PlanRegistry
{
    private string $dir;

    /** @var array<string, array>|null family code -> decoded schema */
    private ?array $schemas = null;

    /** @var array|null decoded _shared.json */
    private ?array $shared = null;

    public function __construct(string $projectDir)
    {
        $this->dir = $projectDir . '/resources/data/plans';
    }

    /**
     * @return array<string, array{family:string, family_name:string, title:string, description:string}>
     *         keyed by lowercase family code (e.g. 'cm')
     */
    public function availablePlans(): array
    {
        $this->load();
        $out = [];
        foreach ($this->schemas as $code => $schema) {
            $out[$code] = [
                'family'      => $schema['family']      ?? strtoupper($code),
                'family_name' => $schema['family_name'] ?? $code,
                'title'       => $schema['title']       ?? $code,
                'description' => $schema['description'] ?? '',
            ];
        }
        ksort($out);
        return $out;
    }

    public function has(string $family): bool
    {
        $this->load();
        return isset($this->schemas[strtolower($family)]);
    }

    public function getSchema(string $family): ?array
    {
        $this->load();
        return $this->schemas[strtolower($family)] ?? null;
    }

    public function getShared(): array
    {
        $this->load();
        return $this->shared ?? [];
    }

    private function load(): void
    {
        if ($this->schemas !== null) {
            return;
        }
        $this->schemas = [];

        if (!is_dir($this->dir)) {
            return;
        }

        $sharedPath = $this->dir . '/_shared.json';
        if (is_file($sharedPath)) {
            $decoded = json_decode((string) @file_get_contents($sharedPath), true);
            if (is_array($decoded)) {
                $this->shared = $decoded;
            }
        }

        foreach (glob($this->dir . '/*.json') ?: [] as $path) {
            $base = basename($path, '.json');
            if (str_starts_with($base, '_')) continue;
            $decoded = json_decode((string) @file_get_contents($path), true);
            if (!is_array($decoded)) continue;
            $this->schemas[strtolower($base)] = $decoded;
        }
    }
}
