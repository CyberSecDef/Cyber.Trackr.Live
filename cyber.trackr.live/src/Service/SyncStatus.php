<?php

namespace App\Service;

/**
 * Exposes the DISA / NIST refresh timestamps to templates.
 *
 * DISA is sourced from resources/data/sync_status.json (stamped by the
 * stig/scap disa_download endpoints via markDisaSyncedNow()).
 *
 * NIST is derived dynamically from the newest mtime among the OSCAL
 * profile/catalog files in resources/data/overlays/ — when a new OSCAL
 * file lands, the badge advances automatically with no manual upkeep.
 *
 * Wired to Twig as the global `sync_status` in config/packages/twig.yaml.
 * Use as `{{ sync_status.disa | rel_time }}` etc. Both getters return null
 * when their source is unavailable; templates should null-check.
 */
class SyncStatus
{
    private ?array $data = null;
    private bool $loaded = false;
    private string $path;
    private string $overlaysDir;
    private bool $nistComputed = false;
    private ?\DateTimeImmutable $nistCached = null;

    public function __construct(string $projectDir)
    {
        $this->path = $projectDir . '/resources/data/sync_status.json';
        $this->overlaysDir = $projectDir . '/resources/data/overlays';
    }

    public function getDisa(): ?\DateTimeImmutable
    {
        return $this->dateFor('disa');
    }

    /**
     * Newest mtime across OSCAL profile/catalog files in the overlays root.
     * Non-recursive — _tools/ and other subfolders are skipped by glob().
     */
    public function getNist(): ?\DateTimeImmutable
    {
        if ($this->nistComputed) {
            return $this->nistCached;
        }
        $this->nistComputed = true;

        $latest = 0;
        foreach (['*_profile.json', '*catalog*.json'] as $pattern) {
            $matches = glob($this->overlaysDir . '/' . $pattern);
            if ($matches === false) {
                continue;
            }
            foreach ($matches as $file) {
                $mtime = @filemtime($file);
                if ($mtime !== false && $mtime > $latest) {
                    $latest = $mtime;
                }
            }
        }
        if ($latest === 0) {
            return null;
        }
        return $this->nistCached = (new \DateTimeImmutable('@' . $latest))->setTimezone(new \DateTimeZone('UTC'));
    }

    public function isAvailable(): bool
    {
        return $this->load() !== null;
    }

    /**
     * Stamps the `disa` key with the current UTC time. Preserves every other
     * key (including `_comment` and `nist`) and writes atomically via a temp
     * file + rename so a crashed request can't leave a half-written JSON.
     */
    public function markDisaSyncedNow(): bool
    {
        return $this->writeKey('disa', (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'));
    }

    private function writeKey(string $key, string $isoTimestamp): bool
    {
        $data = [];
        if (is_file($this->path)) {
            $raw = @file_get_contents($this->path);
            if ($raw !== false) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $data = $decoded;
                }
            }
        }
        $data[$key] = $isoTimestamp;

        $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            return false;
        }

        $tmp = $this->path . '.tmp';
        if (@file_put_contents($tmp, $encoded . "\n", LOCK_EX) === false) {
            return false;
        }
        if (!@rename($tmp, $this->path)) {
            @unlink($tmp);
            return false;
        }

        $this->data = null;
        $this->loaded = false;
        return true;
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
