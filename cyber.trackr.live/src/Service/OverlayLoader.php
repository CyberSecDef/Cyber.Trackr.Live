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
 * The XML at resources/data/rmf/800-53v5-controls.xml carries its own
 * <baseline> tags but they're stale (XML pub_date is 2017-08-01); OSCAL
 * 5.2.0 is current and authoritative, so this loader supersedes them.
 */
class OverlayLoader
{
    /**
     * @var array<string, array{id:string, title:string, short_title:string, count:int, controls: array<int,string>}>
     *      Keyed by overlay id (e.g. "low"). `controls` is the list of OSCAL
     *      control IDs (lowercase + dot, e.g. "ac-2.1").
     */
    private array $overlays = [];

    /**
     * @var array<string, array<int,string>>
     *      OSCAL control id → list of overlay ids the control appears in.
     *      Built lazily from $overlays on first lookup.
     */
    private array $controlMap = [];

    private bool $loaded = false;

    public function __construct(private string $projectDir)
    {
    }

    /**
     * Path to the overlays directory.
     */
    public function dir(): string
    {
        return $this->projectDir . '/resources/data/overlays';
    }

    /**
     * Returns metadata for every overlay, sorted with the standard NIST
     * baselines first (low → moderate → high → privacy), then alphabetical
     * for any custom overlays added later.
     *
     * @return array<int, array{id:string, title:string, short_title:string, count:int}>
     */
    public function getOverlays(): array
    {
        $this->ensureLoaded();
        $list = array_map(
            fn(array $o) => ['id' => $o['id'], 'title' => $o['title'], 'short_title' => $o['short_title'], 'count' => $o['count']],
            $this->overlays
        );

        $order = ['low' => 0, 'moderate' => 1, 'high' => 2, 'privacy' => 3];
        usort($list, function ($a, $b) use ($order) {
            $oa = $order[$a['id']] ?? 99;
            $ob = $order[$b['id']] ?? 99;
            if ($oa !== $ob) return $oa <=> $ob;
            return strcmp($a['short_title'], $b['short_title']);
        });
        return $list;
    }

    /**
     * Returns the overlays the given XML control number belongs to. Accepts
     * the XML's native form ("AC-2", "AC-2(1)", "AC-1a.") and handles the
     * normalization. Statement-letter forms always return [].
     *
     * @return array<int, string> overlay ids, e.g. ['low', 'moderate', 'high']
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

    /**
     * Walk the overlays directory once, parse each *_profile.json, and
     * populate $overlays + $controlMap.
     */
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
     * @return array{id:string, title:string, short_title:string, count:int, controls: array<int,string>}|null
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

        $title = (string) ($profile['metadata']['title'] ?? $filename);

        return [
            'id'          => self::idFromFilename($filename),
            'title'       => $title,
            'short_title' => self::shortTitle($title, $filename),
            'count'       => count($controls),
            'controls'    => $controls,
        ];
    }

    /**
     * Derive a short, lowercase, hyphenated overlay id from the filename.
     * "NIST_SP-800-53_rev5_MODERATE-baseline_profile.json" → "moderate".
     * Anything that doesn't match the NIST naming pattern falls back to
     * the filename base.
     */
    private static function idFromFilename(string $filename): string
    {
        if (preg_match('/_(LOW|MODERATE|HIGH|PRIVACY)-baseline/i', $filename, $m)) {
            return strtolower($m[1]);
        }
        return strtolower(preg_replace('/[^a-z0-9]+/i', '-', pathinfo($filename, PATHINFO_FILENAME)));
    }

    /**
     * Pick a chip-friendly label from the profile's metadata title. NIST's
     * canonical titles are wordy ("Electronic (OSCAL) Version of NIST Special
     * Publication 800-53 Revision 5.2.0 MODERATE IMPACT BASELINE"); we just
     * want "Moderate" for the chip.
     */
    private static function shortTitle(string $title, string $filename): string
    {
        if (preg_match('/(LOW|MODERATE|HIGH|PRIVACY)\s+IMPACT\s+BASELINE/i', $title, $m)) {
            return ucfirst(strtolower($m[1]));
        }
        if (preg_match('/(LOW|MODERATE|HIGH|PRIVACY)/i', $filename, $m)) {
            return ucfirst(strtolower($m[1]));
        }
        return $title;
    }
}
