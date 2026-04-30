<?php

namespace App\Service;

/**
 * Reads resources/data/sync_status.json and exposes the DISA / NIST refresh
 * timestamps to templates. Lazy-loads on first access; caches per request.
 *
 * Wired to Twig as the global `sync_status` in config/packages/twig.yaml.
 * Use as `{{ sync_status.disa | rel_time }}` etc. Both getters return null
 * when the file is missing or malformed; templates should null-check.
 */
class SyncStatus
{
    private ?array $data = null;
    private bool $loaded = false;
    private string $path;

    public function __construct(string $projectDir)
    {
        $this->path = $projectDir . '/resources/data/sync_status.json';
    }

    public function getDisa(): ?\DateTimeImmutable
    {
        return $this->dateFor('disa');
    }

    public function getNist(): ?\DateTimeImmutable
    {
        return $this->dateFor('nist');
    }

    public function isAvailable(): bool
    {
        return $this->load() !== null;
    }

    private function dateFor(string $key): ?\DateTimeImmutable
    {
        $data = $this->load();
        if ($data === null || empty($data[$key]) || !is_string($data[$key])) {
            return null;
        }
        try {
            return new \DateTimeImmutable($data[$key]);
        } catch (\Throwable) {
            return null;
        }
    }

    private function load(): ?array
    {
        if ($this->loaded) {
            return $this->data;
        }
        $this->loaded = true;
        if (!is_file($this->path)) {
            return null;
        }
        $raw = @file_get_contents($this->path);
        if ($raw === false) {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }
        $this->data = $decoded;
        return $this->data;
    }
}
