<?php

namespace App\Service;

/**
 * Computes the public-facing app version string, e.g. "3.2605.98".
 *
 * Format: MAJOR.YYMM.COMMITS
 *   - MAJOR   - hand-bumped, indicates the lineage of the codebase
 *   - YYMM    - two-digit year + zero-padded month, monotonic forever
 *               (Dec 2026 = 2612, Jan 2027 = 2701)
 *   - COMMITS - total commit count on HEAD via `git rev-list --count`
 *
 * Resolution order:
 *   1. Project-root `VERSION` file (one line, the literal string). This is
 *      the production path - the deploy step bakes it via the
 *      `app:version:freeze` command so production hosts don't need git or
 *      a `.git` directory.
 *   2. Live compute via shell-out to git. Used in dev. Returns commits=0
 *      if git isn't available or the project isn't a working tree.
 *
 * Wired to Twig as the global `app_version` in config/packages/twig.yaml.
 */
class AppVersion
{
    private const MAJOR = 3;

    private string $projectDir;
    private ?string $cached = null;

    public function __construct(string $projectDir)
    {
        $this->projectDir = $projectDir;
    }

    public function get(): string
    {
        if ($this->cached !== null) {
            return $this->cached;
        }

        $versionFile = $this->projectDir . '/VERSION';
        if (is_file($versionFile)) {
            $contents = trim((string) @file_get_contents($versionFile));
            if ($contents !== '') {
                return $this->cached = $contents;
            }
        }

        return $this->cached = $this->compute();
    }

    public function __toString(): string
    {
        return $this->get();
    }

    /**
     * Always live-computes, ignoring any baked VERSION file. Used by the
     * freeze command to write the file in the first place.
     */
    public function compute(): string
    {
        $yymm = (int) (new \DateTimeImmutable('now'))->format('ym');
        return sprintf('%d.%d.%d', self::MAJOR, $yymm, $this->commitCount());
    }

    private function commitCount(): int
    {
        $cmd = sprintf('git -C %s rev-list --count HEAD 2>/dev/null', escapeshellarg($this->projectDir));
        $out = @shell_exec($cmd);
        if (!is_string($out)) {
            return 0;
        }
        return max(0, (int) trim($out));
    }
}
