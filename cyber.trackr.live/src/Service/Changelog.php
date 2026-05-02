<?php
namespace App\Service;

/**
 * Reads the project's git history at request time so the /changelog page
 * stays current without any rebuild step. The git repository lives one
 * directory above the Symfony root (the repo holds the application as a
 * subdir mapped to the production virtual host); we run `git log` from
 * the repo root so the call works whether the working directory is the
 * Symfony bin/ folder or the production webroot.
 *
 * Each entry exposes:
 *   - hash            (full 40-char SHA)
 *   - short_hash      (7-char form for display)
 *   - subject         (first line of the commit message)
 *   - summary         (first body paragraph, ≤2 sentences, may be empty)
 *   - author_name
 *   - date_iso        (ISO 8601 with offset, suitable for <time datetime="...">)
 *   - date_human      (compact display string — "May 02, 2026 · 14:32")
 *
 * Failures (no git binary, no .git dir, exec disabled in php.ini) collapse
 * to an empty array so the page still renders an empty-state message.
 */
class Changelog
{
    public function __construct(private string $projectDir)
    {
    }

    /**
     * Walk the repo's git log newest → oldest.
     *
     * @return array<int, array{
     *      hash:string, short_hash:string, subject:string, summary:string,
     *      author_name:string, date_iso:string, date_human:string
     *  }>
     */
    public function getEntries(): array
    {
        $repoDir = $this->resolveRepoDir();
        if ($repoDir === null) return [];
        if (!function_exists('proc_open')) return [];

        // Unique record + field separators that won't appear in commit text.
        $rs = "\x1e";  // ASCII record separator
        $fs = "\x1f";  // ASCII unit/field separator
        $format = '%H' . $fs . '%aI' . $fs . '%an' . $fs . '%s' . $fs . '%b' . $rs;

        $cmd = ['git', '-C', $repoDir, 'log', '--no-merges', '--pretty=format:' . $format];
        $raw = $this->runCommand($cmd);
        if ($raw === null) return [];

        $entries = [];
        foreach (explode($rs, $raw) as $chunk) {
            $chunk = trim($chunk, "\r\n");
            if ($chunk === '') continue;
            $parts = explode($fs, $chunk);
            if (count($parts) < 5) continue;

            [$hash, $isoDate, $author, $subject, $body] = $parts;
            $hash      = trim($hash);
            $isoDate   = trim($isoDate);
            $subject   = trim($subject);
            $body      = trim($body);

            $entries[] = [
                'hash'        => $hash,
                'short_hash'  => substr($hash, 0, 7),
                'subject'     => $subject,
                'summary'     => $this->extractSummary($body),
                'author_name' => trim($author),
                'date_iso'    => $isoDate,
                'date_human'  => $this->humanizeDate($isoDate),
            ];
        }

        return $entries;
    }

    /**
     * Pull the first paragraph of the commit body and trim to ≤2 sentences.
     * Co-Authored-By trailers and bullet lists drop out cleanly because we
     * stop at the first blank line.
     */
    private function extractSummary(string $body): string
    {
        if ($body === '') return '';

        // Take everything up to the first blank line — the lead paragraph.
        $firstParagraph = preg_split('/\n\s*\n/', $body, 2)[0] ?? '';
        $firstParagraph = trim(preg_replace('/\s+/', ' ', $firstParagraph));
        if ($firstParagraph === '') return '';

        // Strip any trailing markers we know we don't want (Co-Authored-By
        // lines that snuck into the lead paragraph for short commits).
        $firstParagraph = preg_replace(
            '/\s*Co-Authored-By:.*$/i',
            '',
            $firstParagraph
        );

        // Cap at two sentences. Match runs of "<sentence-end-punct><space>".
        if (preg_match('/^(.*?[.!?])\s+(.*?[.!?])(?:\s|$)/u', $firstParagraph, $m)) {
            return trim($m[1] . ' ' . $m[2]);
        }
        if (preg_match('/^(.*?[.!?])(?:\s|$)/u', $firstParagraph, $m)) {
            return trim($m[1]);
        }

        // No sentence-ending punctuation — return as-is, capped at 240 chars.
        return mb_strlen($firstParagraph) > 240
            ? rtrim(mb_substr($firstParagraph, 0, 237)) . '…'
            : $firstParagraph;
    }

    private function humanizeDate(string $iso): string
    {
        try {
            $dt = new \DateTimeImmutable($iso);
            return $dt->format('M d, Y · H:i');
        } catch (\Throwable) {
            return $iso;
        }
    }

    /**
     * The git repo is at the parent of the Symfony app dir
     * (Cyber.Trackr.Live/.git, with the app at Cyber.Trackr.Live/cyber.trackr.live/).
     * Falls back to the Symfony root if there's a .git there too (dev case),
     * or null if no .git is reachable.
     */
    private function resolveRepoDir(): ?string
    {
        $candidates = [
            dirname($this->projectDir),  // typical: parent of Symfony root
            $this->projectDir,           // dev fallback
        ];
        foreach ($candidates as $c) {
            if ($c && is_dir($c . '/.git')) return $c;
        }
        return null;
    }

    /**
     * Run a process and return stdout, or null on failure. Uses proc_open
     * to avoid shell expansion and keep stderr out of stdout.
     *
     * @param array<int,string> $cmd
     */
    private function runCommand(array $cmd): ?string
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = @proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($proc)) return null;

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        if ($exit !== 0) return null;
        return is_string($stdout) ? $stdout : null;
    }
}
