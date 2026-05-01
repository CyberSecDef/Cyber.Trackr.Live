<?php

namespace App\Service;

use Symfony\Component\Finder\Finder;

/**
 * Loads OSCAL Profile JSON files from resources/data/overlays/ and exposes
 * which 800-53 r5 controls are members of each overlay.
 *
 * Used by the /rmf/5 page to render baseline-membership badges next to
 * each control and to power the client-side filter chips. New overlays are
 * additive: drop another `*_profile.json` into the overlays directory and
 * it shows up on the next request.
 *
 * Overlay IDs are source-prefixed ("nist-low", "fedramp-moderate") so
 * profiles from different authorities don't collide. Each overlay also
 * carries a `level` (low/moderate/high/privacy/li-saas) used for color
 * coding, and an `abbr` (NL/NM/NH/NP/FL/FM/FH/FS) for compact badge text.
 *
 * The XML at resources/data/rmf/800-53v5-controls.xml carries its own
 * <baseline> tags but they're stale (XML pub_date is 2017-08-01); OSCAL
 * 5.2.0 + FedRAMP rev5 are current and authoritative, so this loader
 * supersedes them.
 */
class OverlayLoader
{
    /**
     * Display order for the chip strip. Sources first (NIST is canonical),
     * then by impact level. Anything not listed sorts to the end and falls
     * back to alphabetical.
     */
    private const SOURCE_ORDER = ['nist' => 0, 'fedramp' => 1, 'cnss' => 2];
    private const LEVEL_ORDER  = ['low' => 0, 'moderate' => 1, 'high' => 2, 'privacy' => 3, 'li-saas' => 4];

    /**
     * @var array<string, array{
     *      id:string, source:string, level:string, abbr:string,
     *      title:string, short_title:string, count:int,
     *      controls: array<int,string>
     *   }>
     *   Keyed by overlay id (e.g. "nist-low", "fedramp-moderate").
     *   `controls` is the list of OSCAL control IDs (lowercase + dot).
     */
    private array $overlays = [];

    /**
     * @var array<string, array<int,string>>
     *      OSCAL control id → list of overlay ids the control appears in.
     */
    private array $controlMap = [];

    private bool $loaded = false;

    public function __construct(private string $projectDir)
    {
    }

    public function dir(): string
    {
        return $this->projectDir . '/resources/data/overlays';
    }

    /**
     * Returns metadata for every overlay, keyed by id, in display order
     * (NIST L → M → H → P, then FedRAMP L → M → H → LI-SaaS, then
     * anything custom alphabetically). Templates iterate values for the
     * chip strip and use it as a lookup when rendering per-control badges.
     *
     * @return array<string, array{id:string, source:string, level:string, abbr:string, title:string, short_title:string, count:int}>
     */
    public function getOverlays(): array
    {
        $this->ensureLoaded();

        $list = [];
        foreach ($this->overlays as $id => $o) {
            $list[$id] = [
                'id'          => $o['id'],
                'source'      => $o['source'],
                'level'       => $o['level'],
                'abbr'        => $o['abbr'],
                'title'       => $o['title'],
                'short_title' => $o['short_title'],
                'count'       => $o['count'],
            ];
        }

        uasort($list, function (array $a, array $b): int {
            $sa = self::SOURCE_ORDER[$a['source']] ?? 99;
            $sb = self::SOURCE_ORDER[$b['source']] ?? 99;
            if ($sa !== $sb) return $sa <=> $sb;
            $la = self::LEVEL_ORDER[$a['level']] ?? 99;
            $lb = self::LEVEL_ORDER[$b['level']] ?? 99;
            if ($la !== $lb) return $la <=> $lb;
            return strcmp($a['short_title'], $b['short_title']);
        });
        return $list;
    }

    /**
     * Returns the overlays the given XML control number belongs to. Accepts
     * the XML's native form ("AC-2", "AC-2(1)", "AC-1a.") and handles the
     * normalization. Statement-letter forms always return [].
     *
     * @return array<int, string> overlay ids, e.g. ['nist-low', 'fedramp-moderate']
     */
    public function getControlOverlays(string $xmlControlNumber): array
    {
        $this->ensureLoaded();
        $oscalId = self::normalize($xmlControlNumber);
        if ($oscalId === null) {
            return [];
        }
        return $this->controlMap[$oscalId] ?? [];
    }

    /**
     * Convert the XML's control number form to OSCAL's lowercase+dot form.
     * Returns null for statement letters (AC-1a., AC-1a.1.(b)) which are
     * sub-elements of a parent control rather than overlay-eligible items.
     */
    public static function normalize(string $xmlId): ?string
    {
        $xmlId = trim($xmlId);

        // Enhancement: "AC-2(1)" or "AC-2 (1)" → "ac-2.1"
        if (preg_match('/^([A-Z]+)-(\d+)\s*\((\d+)\)$/', $xmlId, $m)) {
            return strtolower($m[1]) . '-' . $m[2] . '.' . $m[3];
        }
        // Base control: "AC-2" → "ac-2"
        if (preg_match('/^([A-Z]+)-(\d+)$/', $xmlId, $m)) {
            return strtolower($m[1]) . '-' . $m[2];
        }
        // Statement letter or sub-statement (AC-1a., AC-1a.1.(b)) — not a control
        return null;
    }

    private function ensureLoaded(): void
    {
        if ($this->loaded) {
            return;
        }
        $this->loaded = true;

        if (!is_dir($this->dir())) {
            return;
        }

        $finder = (new Finder())
            ->files()
            ->in($this->dir())
            ->name('*_profile.json')
            // The minified resolved-catalog files contain "profile" in their
            // name too — exclude them explicitly.
            ->notName('*resolved*');

        foreach ($finder as $file) {
            $json = json_decode((string) file_get_contents($file->getRealPath()), true);
            if (!is_array($json) || !isset($json['profile'])) {
                continue;
            }
            $entry = $this->parseProfile($file->getFilename(), $json['profile']);
            if ($entry === null) {
                continue;
            }
            $this->overlays[$entry['id']] = $entry;
            foreach ($entry['controls'] as $cid) {
                $this->controlMap[$cid] ??= [];
                if (!in_array($entry['id'], $this->controlMap[$cid], true)) {
                    $this->controlMap[$cid][] = $entry['id'];
                }
            }
        }
    }

    /**
     * Extract the bits we care about from one OSCAL Profile JSON document.
     *
     * @param array<string,mixed> $profile
     * @return array{id:string, source:string, level:string, abbr:string, title:string, short_title:string, count:int, controls: array<int,string>}|null
     */
    private function parseProfile(string $filename, array $profile): ?array
    {
        $controls = [];
        foreach ($profile['imports'] ?? [] as $import) {
            foreach ($import['include-controls'] ?? [] as $inc) {
                foreach ($inc['with-ids'] ?? [] as $id) {
                    $controls[] = strtolower((string) $id);
                }
            }
        }
        if ($controls === []) {
            return null;
        }

        $title  = (string) ($profile['metadata']['title'] ?? $filename);
        $source = self::sourceFromFilename($filename);
        $level  = self::levelFromFilename($filename);

        return [
            'id'          => $source . '-' . $level,
            'source'      => $source,
            'level'       => $level,
            'abbr'        => self::abbrFor($source, $level),
            'title'       => $title,
            'short_title' => self::shortTitleFor($source, $level),
            'count'       => count($controls),
            'controls'    => $controls,
        ];
    }

    private static function sourceFromFilename(string $filename): string
    {
        if (str_starts_with($filename, 'NIST'))    return 'nist';
        if (str_starts_with($filename, 'FedRAMP')) return 'fedramp';
        if (str_starts_with($filename, 'CNSSI'))   return 'cnss';
        return 'custom';
    }

    /**
     * Order matters — "LI-SaaS" must be checked before "LOW" because both
     * contain L+I and would collide on a permissive regex.
     */
    private static function levelFromFilename(string $filename): string
    {
        foreach (['LI-SaaS' => 'li-saas', 'PRIVACY' => 'privacy', 'MODERATE' => 'moderate', 'HIGH' => 'high', 'LOW' => 'low'] as $needle => $level) {
            if (stripos($filename, $needle) !== false) {
                return $level;
            }
        }
        return 'unknown';
    }

    /**
     * Two-character abbreviation for the per-control badge. First letter is
     * the source initial (N/F), second is the level initial (L/M/H/P/S).
     * "S" stands in for LI-SaaS since L collides with Low.
     */
    private static function abbrFor(string $source, string $level): string
    {
        $sourceInitial = match ($source) {
            'nist'    => 'N',
            'fedramp' => 'F',
            'cnss'    => 'C',
            default   => strtoupper($source[0] ?? '?'),
        };
        $levelInitial = match ($level) {
            'low'      => 'L',
            'moderate' => 'M',
            'high'     => 'H',
            'privacy'  => 'P',
            'li-saas'  => 'S',
            default    => strtoupper($level[0] ?? '?'),
        };
        return $sourceInitial . $levelInitial;
    }

    /**
     * Chip-friendly label, e.g. "NIST Low" / "FR Moderate" / "CNSS High".
     * FedRAMP gets shortened to "FR" so the chip strip stays compact;
     * CNSS and NIST are already short.
     */
    private static function shortTitleFor(string $source, string $level): string
    {
        $src = match ($source) {
            'nist'    => 'NIST',
            'fedramp' => 'FR',
            'cnss'    => 'CNSS',
            default   => ucfirst($source),
        };
        $lvl = match ($level) {
            'low'      => 'Low',
            'moderate' => 'Moderate',
            'high'     => 'High',
            'privacy'  => 'Privacy',
            'li-saas'  => 'LI-SaaS',
            default    => ucfirst($level),
        };
        return $src . ' ' . $lvl;
    }
}
