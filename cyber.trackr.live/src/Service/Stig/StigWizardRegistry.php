<?php

namespace App\Service\Stig;

/**
 * Discovers STIG-wizard schema files in resources/data/stig-wizard/.
 *
 * One file per STIG (asd.json …). Each declares `meta.stig_id` (the STIG
 * title, matching the stig_toc.json key) and `meta.benchmark_version`
 * (e.g. "V6R4"). A schema lights up the wizard on every release of its
 * major version (V6Rx) until a new schema is authored for the next major
 * version. Drop a new schema file in and the matching STIG view page gets a
 * wizard trigger automatically — no code change.
 */
class StigWizardRegistry
{
    private string $dir;

    /** @var array<string, array>|null wizard key (filename stem) -> decoded schema */
    private ?array $schemas = null;

    public function __construct(string $projectDir)
    {
        $this->dir = $projectDir . '/resources/data/stig-wizard';
    }

    public function has(string $key): bool
    {
        $this->load();
        return isset($this->schemas[strtolower($key)]);
    }

    public function getSchema(string $key): ?array
    {
        $this->load();
        return $this->schemas[strtolower($key)] ?? null;
    }

    /**
     * The wizard matching this STIG view page, or null. Matches on
     * meta.stig_id == $title AND the major version of meta.benchmark_version
     * == major version of $version.
     *
     * @return array{key:string, schema:array}|null
     */
    public function findForStig(string $title, string $version): ?array
    {
        $this->load();
        $major = $this->majorVersion($version);
        foreach ($this->schemas as $key => $schema) {
            $meta = $schema['meta'] ?? [];
            if (($meta['stig_id'] ?? null) !== $title) {
                continue;
            }
            if ($this->majorVersion((string) ($meta['benchmark_version'] ?? '')) !== $major) {
                continue;
            }
            return ['key' => $key, 'schema' => $schema];
        }
        return null;
    }

    /** "V6R4" -> "6"; "6" -> "6"; "" -> "". */
    private function majorVersion(string $v): string
    {
        return preg_match('/V?(\d+)/i', $v, $m) ? $m[1] : '';
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
        foreach (glob($this->dir . '/*.json') ?: [] as $path) {
            $base = basename($path, '.json');
            if (str_starts_with($base, '_')) {
                continue;
            }
            $decoded = json_decode((string) @file_get_contents($path), true);
            if (is_array($decoded)) {
                $this->schemas[strtolower($base)] = $decoded;
            }
        }
    }
}
