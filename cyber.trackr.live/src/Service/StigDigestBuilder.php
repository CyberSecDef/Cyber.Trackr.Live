<?php
namespace App\Service;

/**
 * Builds a "what changed since the previous version" digest for a STIG and
 * caches the result alongside the source XML as `{xml_filename}.digest.json`.
 *
 * The digest detects four classes of change between a current version and its
 * immediately-prior version on the same STIG title:
 *   - added           — Group ID present in current but not previous
 *   - removed         — Group ID present in previous but not current
 *   - severity_changed — same Group ID, different Rule@severity
 *   - content_changed  — same Group ID + severity, different rule text
 *                        (description / check-content / fixtext, after
 *                        HTML-strip + whitespace normalize)
 *
 * Rule matching uses Group/@id as the primary key with the legacy V-NNNNNN
 * ident as a fallback (matching the strategy used by the existing
 * compare.html.twig template).
 *
 * Cache invalidation is based on source-file mtimes: the cached JSON stores
 * the mtimes of both current and previous XMLs at write time and is
 * recomputed on read if either source has since changed.
 */
class StigDigestBuilder
{
    private const XCCDF_NS = 'http://checklists.nist.gov/xccdf/1.1';
    private const SCHEMA_VERSION = 1;

    /**
     * Resolve the previous TOC entry for the given title + version + release.
     * The TOC entries are date-sorted descending; "previous" is the entry
     * immediately after the current one in that order. Returns null if the
     * current entry is the only one or the oldest.
     *
     * @param array<int,object|array> $tocEntries  TOC entries for this title
     * @return array{version:string,release:string,filename:string,date:string,released:string}|null
     */
    public function findPreviousEntry(array $tocEntries, string $version, string $release): ?array
    {
        $entries = array_map(fn($e) => (array) $e, $tocEntries);
        usort($entries, fn($a, $b) => ($b['date'] ?? '') <=> ($a['date'] ?? ''));

        $currentIdx = null;
        foreach ($entries as $i => $e) {
            if ((string) $e['version'] === (string) $version
                && (string) $e['release'] === (string) $release) {
                $currentIdx = $i;
                break;
            }
        }
        if ($currentIdx === null) return null;
        if (!isset($entries[$currentIdx + 1])) return null;
        return $entries[$currentIdx + 1];
    }

    /**
     * Get-or-build the digest for the given current entry against its
     * immediately-prior version. Returns null if there is no previous version.
     *
     * @param string                                                              $stigDir   absolute path to the STIG xml dir
     * @param array{filename:string,version:string,release:string,date:string,released:string} $current
     * @param array<int,object|array>                                             $tocEntries TOC entries for this title (any shape — coerced to array)
     */
    public function buildOrLoad(string $stigDir, array $current, array $tocEntries): ?array
    {
        $previous = $this->findPreviousEntry($tocEntries, (string) $current['version'], (string) $current['release']);
        if ($previous === null) return null;

        $currentPath  = rtrim($stigDir, '/') . '/' . $current['filename'];
        $previousPath = rtrim($stigDir, '/') . '/' . $previous['filename'];
        if (!file_exists($currentPath) || !file_exists($previousPath)) return null;

        $cachePath = $currentPath . '.digest.json';

        // Cache hit?
        if (file_exists($cachePath)) {
            $cached = @json_decode(@file_get_contents($cachePath), true);
            if (is_array($cached)
                && ($cached['schema_version'] ?? 0) === self::SCHEMA_VERSION
                && ($cached['source_mtime'] ?? 0) >= filemtime($currentPath)
                && ($cached['previous_mtime'] ?? 0) >= filemtime($previousPath)) {
                return $cached['digest'];
            }
        }

        // Cache miss / stale — rebuild.
        $digest = $this->compute($currentPath, $previousPath, $current, $previous);

        @file_put_contents($cachePath, json_encode([
            'schema_version' => self::SCHEMA_VERSION,
            'source_mtime'   => filemtime($currentPath),
            'previous_mtime' => filemtime($previousPath),
            'digest'         => $digest,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $digest;
    }

    /**
     * Build the digest from two paths without caching. Public for use by the
     * Atom-feed builder which manages its own iteration.
     *
     * @param array{version:string,release:string,date:string,released:string} $current
     * @param array{version:string,release:string,date:string,released:string} $previous
     */
    public function compute(string $currentPath, string $previousPath, array $current, array $previous): array
    {
        $currentRules  = $this->loadRules($currentPath);
        $previousRules = $this->loadRules($previousPath);

        $added = [];
        $removed = [];
        $severityChanged = [];
        $contentChanged = [];
        $unchanged = 0;

        foreach ($currentRules as $key => $rule) {
            if (!isset($previousRules[$key])) {
                // Try legacy V- ident as fallback (in case Group ID was renumbered).
                $legacyMatch = $this->findByLegacyIdent($previousRules, $rule['legacy_ident'] ?? '');
                if ($legacyMatch === null) {
                    $added[] = $this->summary($rule);
                    continue;
                }
                // Treat legacy match as the same rule.
                $previousRules[$key] = $legacyMatch;
            }

            $prev = $previousRules[$key];
            $changedFields = [];

            if (($rule['severity'] ?? '') !== ($prev['severity'] ?? '')) {
                $severityChanged[] = [
                    'vuln_id'      => $rule['vuln_id'],
                    'rule_id'      => $rule['rule_id'],
                    'title'        => $rule['title'],
                    'old_severity' => $prev['severity'] ?? '',
                    'new_severity' => $rule['severity'] ?? '',
                ];
                // Severity-only changes don't get duplicated as content-changed.
                continue;
            }

            if (($rule['description_norm'] ?? '') !== ($prev['description_norm'] ?? '')) $changedFields[] = 'description';
            if (($rule['check_norm'] ?? '')       !== ($prev['check_norm'] ?? ''))       $changedFields[] = 'check';
            if (($rule['fix_norm'] ?? '')         !== ($prev['fix_norm'] ?? ''))         $changedFields[] = 'fix';

            if ($changedFields !== []) {
                $contentChanged[] = [
                    'vuln_id' => $rule['vuln_id'],
                    'rule_id' => $rule['rule_id'],
                    'title'   => $rule['title'],
                    'severity'=> $rule['severity'],
                    'fields'  => $changedFields,
                ];
            } else {
                $unchanged++;
            }
        }

        // Anything in previousRules without a counterpart in currentRules → removed.
        $currentKeys = array_keys($currentRules);
        $currentLegacy = array_filter(array_column($currentRules, 'legacy_ident'));
        foreach ($previousRules as $key => $prev) {
            if (in_array($key, $currentKeys, true)) continue;
            if (!empty($prev['legacy_ident']) && in_array($prev['legacy_ident'], $currentLegacy, true)) continue;
            $removed[] = $this->summary($prev);
        }

        usort($added,            fn($a, $b) => $a['vuln_id'] <=> $b['vuln_id']);
        usort($removed,          fn($a, $b) => $a['vuln_id'] <=> $b['vuln_id']);
        usort($severityChanged,  fn($a, $b) => $a['vuln_id'] <=> $b['vuln_id']);
        usort($contentChanged,   fn($a, $b) => $a['vuln_id'] <=> $b['vuln_id']);

        return [
            'previous' => [
                'version'  => (string) $previous['version'],
                'release'  => (string) $previous['release'],
                'date'     => (string) ($previous['date'] ?? ''),
                'released' => (string) ($previous['released'] ?? ''),
            ],
            'current' => [
                'version'  => (string) $current['version'],
                'release'  => (string) $current['release'],
                'date'     => (string) ($current['date'] ?? ''),
                'released' => (string) ($current['released'] ?? ''),
            ],
            'added'            => $added,
            'removed'          => $removed,
            'severity_changed' => $severityChanged,
            'content_changed'  => $contentChanged,
            'totals' => [
                'added'            => count($added),
                'removed'          => count($removed),
                'severity_changed' => count($severityChanged),
                'content_changed'  => count($contentChanged),
                'unchanged'        => $unchanged,
            ],
        ];
    }

    /**
     * Parse a STIG XCCDF and return rule data keyed by Group/@id with the
     * normalized text fields needed for comparison.
     *
     * @return array<string,array{
     *     vuln_id:string,rule_id:string,title:string,severity:string,
     *     legacy_ident:string,description_norm:string,check_norm:string,fix_norm:string
     * }>
     */
    private function loadRules(string $path): array
    {
        $xml = @simplexml_load_file($path);
        if ($xml === false) return [];

        // The XCCDF doc has the namespace as default; alias to 'x' for xpath.
        $xml->registerXPathNamespace('x', self::XCCDF_NS);

        $rules = [];
        foreach ($xml->xpath('//x:Group') as $group) {
            $vulnId = (string) $group->attributes()->id;
            if ($vulnId === '') continue;

            $kids = $group->children(self::XCCDF_NS);
            $rule = $kids->Rule;
            if (!isset($rule[0])) continue;
            $rule = $rule[0];

            $ruleAttrs    = $rule->attributes();
            $ruleId       = (string) $ruleAttrs->id;
            $severity     = strtolower((string) $ruleAttrs->severity);
            $title        = trim((string) $rule->children(self::XCCDF_NS)->title);
            $description  = (string) $rule->children(self::XCCDF_NS)->description;
            $fixtextNode  = $rule->children(self::XCCDF_NS)->fixtext;
            $fixtext      = $fixtextNode ? (string) $fixtextNode : '';

            // <check><check-content> — usually only one check element per rule.
            $checkContent = '';
            $checkNode = $rule->children(self::XCCDF_NS)->check;
            if ($checkNode) {
                $cc = $checkNode->children(self::XCCDF_NS)->{'check-content'};
                if ($cc) $checkContent = (string) $cc;
            }

            // Legacy V-NNNNNN ident (some older STIGs duplicated the vuln ID
            // here so the V-N number persisted across renumbering).
            $legacyIdent = '';
            foreach ($rule->children(self::XCCDF_NS)->ident as $ident) {
                $val = trim((string) $ident);
                if (str_starts_with($val, 'V-')) {
                    $legacyIdent = $val;
                    break;
                }
            }

            $rules[$vulnId] = [
                'vuln_id'          => $vulnId,
                'rule_id'          => $ruleId,
                'title'            => $title,
                'severity'         => $severity,
                'legacy_ident'     => $legacyIdent,
                'description_norm' => $this->normalize($description),
                'check_norm'       => $this->normalize($checkContent),
                'fix_norm'         => $this->normalize($fixtext),
            ];
        }

        return $rules;
    }

    /**
     * Strip HTML/XML pseudo-tags and collapse whitespace so equality
     * comparison ignores cosmetic-only edits.
     */
    private function normalize(string $s): string
    {
        if ($s === '') return '';
        $s = html_entity_decode($s, ENT_QUOTES | ENT_XML1 | ENT_HTML5, 'UTF-8');
        $s = preg_replace('/<[^>]*>/s', ' ', $s);
        $s = preg_replace('/\s+/u', ' ', $s);
        return trim((string) $s);
    }

    private function findByLegacyIdent(array $rules, string $legacy): ?array
    {
        if ($legacy === '') return null;
        foreach ($rules as $r) {
            if (($r['legacy_ident'] ?? '') === $legacy) return $r;
        }
        return null;
    }

    private function summary(array $rule): array
    {
        return [
            'vuln_id'  => $rule['vuln_id'],
            'rule_id'  => $rule['rule_id'],
            'title'    => $rule['title'],
            'severity' => $rule['severity'],
        ];
    }
}
