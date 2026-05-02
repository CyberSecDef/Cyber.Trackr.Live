<?php
namespace App\Service;

class R4ToR5Mapper
{
    private const R4_XML       = __DIR__ . '/../../resources/data/rmf/800-53v4-controls.xml';
    private const R5_XML       = __DIR__ . '/../../resources/data/rmf/800-53v5-controls.xml';
    private const MAPPING_JSON = __DIR__ . '/../../resources/data/rmf/r4-to-r5-mapping.json';

    public function map(): array
    {
        // Catalogs are keyed by CANONICAL form (no space before parens) so
        // r4's "AC-2 (1)" and r5's "AC-2(1)" pair correctly. Each entry
        // also carries 'display' — the original on-disk format, used for
        // deep-link anchors (the existing r4 / r5 view pages anchor on the
        // catalog's stored form, which differs between revisions).
        $r4 = $this->loadCatalog(self::R4_XML, true);
        $r5 = $this->loadCatalog(self::R5_XML, false);
        $curated = $this->loadCurated();

        $rows = [];
        $seen_r4 = [];
        $seen_r5 = [];

        foreach ($curated as $c) {
            $r4key = isset($c['r4']) ? $this->normalize($c['r4']) : null;
            $r5key = isset($c['r5']) ? $this->normalize($c['r5']) : null;
            $row = [
                'r4'         => $r4key,
                'r4_display' => $r4key && isset($r4[$r4key]) ? $r4[$r4key]['display'] : $r4key,
                'r5'         => $r5key,
                'r5_display' => $r5key && isset($r5[$r5key]) ? $r5[$r5key]['display'] : $r5key,
                'kind'       => $c['kind'] ?? 'unchanged',
                'rationale'  => $c['rationale'] ?? '',
                'source'     => 'curated',
                'r4_title'   => $r4key && isset($r4[$r4key]) ? $r4[$r4key]['title'] : null,
                'r5_title'   => $r5key && isset($r5[$r5key]) ? $r5[$r5key]['title'] : null,
                'family'     => $this->family($r4key ?? $r5key ?? ''),
            ];
            $rows[] = $row;
            if ($r4key) $seen_r4[$r4key] = true;
            if ($r5key) $seen_r5[$r5key] = true;
        }

        // Mechanical: every r4 number not yet covered. Three cases:
        //   1. r5 has a live entry with the same canonical number → unchanged
        //   2. r5 has a withdrawn placeholder pointing elsewhere → incorporated-into
        //      (use the r5 entry's <incorporated-into> targets as authoritative)
        //   3. r5 has no entry at all → truly withdrawn (rare — usually r5 keeps
        //      a withdrawn placeholder for traceability)
        foreach ($r4 as $num => $info) {
            if (isset($seen_r4[$num])) continue;
            $r5entry = $r5[$num] ?? null;

            if ($r5entry && ($r5entry['status'] ?? 'active') === 'active') {
                $rows[] = [
                    'r4'         => $num,
                    'r4_display' => $info['display'],
                    'r5'         => $num,
                    'r5_display' => $r5entry['display'],
                    'kind'       => 'unchanged',
                    'rationale'  => '',
                    'source'     => 'mechanical',
                    'r4_title'   => $info['title'],
                    'r5_title'   => $r5entry['title'],
                    'family'     => $this->family($num),
                ];
                $seen_r5[$num] = true;
            } elseif ($r5entry && !empty($r5entry['incorporated_into'])) {
                $targets        = $r5entry['incorporated_into'];
                $targetDisplays = [];
                foreach ($targets as $tnum) {
                    $targetDisplays[] = $r5[$tnum]['display'] ?? $tnum;
                }
                $rows[] = [
                    'r4'           => $num,
                    'r4_display'   => $info['display'],
                    'r5'           => $targets[0],                 // primary target for sort + single href
                    'r5_display'   => $targetDisplays[0],
                    'r5_targets'   => $targetDisplays,              // all targets for the cell
                    'kind'         => 'incorporated-into',
                    'rationale'    => $r5entry['withdrawn_desc'] !== ''
                        ? $r5entry['withdrawn_desc']
                        : 'Incorporated into ' . implode(', ', $targetDisplays),
                    'source'       => 'mechanical',
                    'r4_title'     => $info['title'],
                    'r5_title'     => null,
                    'family'       => $this->family($num),
                ];
                // Mark the withdrawn r5 placeholder as seen so we don't
                // emit it as new-in-r5 below; targets stay live (they are
                // separately rendered as their own r5 rows).
                $seen_r5[$num] = true;
            } elseif ($r5entry) {
                // r5 has a withdrawn placeholder but no <incorporated-into>;
                // treat as withdrawn-without-replacement.
                $rows[] = [
                    'r4'         => $num,
                    'r4_display' => $info['display'],
                    'r5'         => null,
                    'r5_display' => null,
                    'kind'       => 'withdrawn',
                    'rationale'  => $r5entry['withdrawn_desc'] ?: '',
                    'source'     => 'mechanical',
                    'r4_title'   => $info['title'],
                    'r5_title'   => null,
                    'family'     => $this->family($num),
                ];
                $seen_r5[$num] = true;
            } else {
                // No r5 entry at all — truly gone, no traceability record.
                $rows[] = [
                    'r4'         => $num,
                    'r4_display' => $info['display'],
                    'r5'         => null,
                    'r5_display' => null,
                    'kind'       => 'withdrawn',
                    'rationale'  => '',
                    'source'     => 'mechanical',
                    'r4_title'   => $info['title'],
                    'r5_title'   => null,
                    'family'     => $this->family($num),
                ];
            }
            $seen_r4[$num] = true;
        }

        // Mechanical: every LIVE r5 number not yet covered → new-in-r5.
        // Withdrawn r5 placeholders that weren't paired above (orphan
        // withdrawals not present in r4) are skipped — they don't
        // represent a meaningful change for the diff view.
        foreach ($r5 as $num => $info) {
            if (isset($seen_r5[$num])) continue;
            if (($info['status'] ?? 'active') !== 'active') continue;
            $rows[] = [
                'r4'         => null,
                'r4_display' => null,
                'r5'         => $num,
                'r5_display' => $info['display'],
                'kind'       => 'new-in-r5',
                'rationale'  => '',
                'source'     => 'mechanical',
                'r4_title'   => null,
                'r5_title'   => $info['title'],
                'family'     => $this->family($num),
            ];
            $seen_r5[$num] = true;
        }

        usort($rows, fn($a, $b) => $this->sortKey($a) <=> $this->sortKey($b));

        return [
            'rows'    => $rows,
            'kinds'   => $this->kindCounts($rows),
            'families'=> $this->familyCounts($rows),
            'totals'  => [
                'r4_total'      => count($r4),
                'r5_total'      => count($r5),
                'curated_count' => count($curated),
            ],
        ];
    }

    private function loadCatalog(string $path, bool $isR4): array
    {
        $real = realpath($path);
        if (!$real || !file_exists($real)) return [];

        $xml = simplexml_load_file($real);
        if ($xml === false) return [];

        // Both catalogs share the same shape:
        //   <controls:controls xmlns="2.0" xmlns:controls="feed/2.0"> wrapper
        //     <controls:control>                                     <-- feed/2.0
        //       <number>AC-1</number>                                <-- default 2.0
        //       <title>...</title>                                   <-- default 2.0
        //       <control-enhancement>                                <-- default 2.0
        //         <number>AC-1(1)</number>
        //
        // So <controls:control> uses the feed/2.0 ns; inner <number>, <title>,
        // and <control-enhancement> use the default 2.0 ns. Register both.
        $xml->registerXPathNamespace('f', 'http://scap.nist.gov/schema/sp800-53/feed/2.0');
        $xml->registerXPathNamespace('d', 'http://scap.nist.gov/schema/sp800-53/2.0');

        $controls = [];
        $defaultNs = 'http://scap.nist.gov/schema/sp800-53/2.0';

        foreach ($xml->xpath('//f:control') as $cnode) {
            $controls += $this->extractEntry($cnode, $defaultNs);
        }

        // Enhancements: <control-enhancement> in the default 2.0 ns. r4 stores
        // these as "AC-2 (1)" with a space; r5 stores them as "AC-2(1)" without.
        // Canonical key removes the space so cross-revision matching works;
        // 'display' preserves the original for deep-linking.
        foreach ($xml->xpath('//d:control-enhancement') as $enode) {
            $controls += $this->extractEntry($enode, $defaultNs);
        }

        return $controls;
    }

    private function loadCurated(): array
    {
        $real = realpath(self::MAPPING_JSON);
        if (!$real || !file_exists($real)) return [];

        $raw = json_decode(file_get_contents($real), true);
        return $raw['curated'] ?? [];
    }

    /**
     * Extract a single control / control-enhancement node into the catalog
     * map shape. Captures number, title, withdrawal status, and any
     * <incorporated-into> targets so the caller can use the catalog itself
     * as the authoritative cross-revision mapping.
     *
     * @return array<string,array<string,mixed>> single-entry map keyed by canonical number
     */
    private function extractEntry(\SimpleXMLElement $node, string $ns): array
    {
        $kids    = $node->children($ns);
        $display = trim((string) $kids->number);
        if ($display === '') return [];
        $key     = $this->normalize($display);
        $title   = trim((string) $kids->title);
        $status  = trim((string) $kids->status);

        $incorporated = [];
        $withdrawnDesc = '';
        if ($kids->withdrawn) {
            foreach ($kids->withdrawn->children($ns)->{'incorporated-into'} as $into) {
                $t = $this->normalize(trim((string) $into));
                if ($t !== '') $incorporated[] = $t;
            }
            // r5's <withdrawn> may also carry a <description>; fall back to
            // statement/description if not present.
            $wDesc = trim((string) $kids->withdrawn->children($ns)->description);
            if ($wDesc !== '') $withdrawnDesc = $wDesc;
        }

        return [$key => [
            'number'           => $key,
            'display'          => $display,
            'title'            => $title !== '' ? $title : '(no title)',
            'status'           => $status !== '' ? strtolower($status) : 'active',
            'incorporated_into'=> $incorporated,
            'withdrawn_desc'   => $withdrawnDesc,
        ]];
    }

    /**
     * Canonical form of a control number for cross-revision matching.
     * Strips whitespace before/around the enhancement parentheses so r4's
     * "AC-2 (1)" and r5's "AC-2(1)" both reduce to "AC-2(1)".
     */
    private function normalize(?string $num): string
    {
        if ($num === null || $num === '') return '';
        // Remove all whitespace adjacent to "(" anywhere in the string.
        $s = preg_replace('/\s*\(\s*/', '(', trim($num));
        $s = preg_replace('/\s*\)\s*/', ')', $s);
        return $s;
    }

    private function family(string $num): string
    {
        if ($num === '') return '';
        return strtoupper(substr($num, 0, strpos($num . '-', '-')));
    }

    /**
     * Sort by family, then by base control number, then by enhancement number.
     * Sorts AC-2 before AC-2(1) before AC-3.
     */
    private function sortKey(array $row): string
    {
        $num = $row['r4'] ?? $row['r5'] ?? 'ZZ-9999';
        $fam = $this->family($num);
        if (preg_match('/^([A-Z]{2})-(\d+)(?:\((\d+)\))?$/', $num, $m)) {
            return sprintf('%s-%04d-%04d', $m[1], (int) $m[2], (int) ($m[3] ?? 0));
        }
        return $fam . '-9999-9999';
    }

    private function kindCounts(array $rows): array
    {
        $c = [];
        foreach ($rows as $r) {
            $c[$r['kind']] = ($c[$r['kind']] ?? 0) + 1;
        }
        ksort($c);
        return $c;
    }

    private function familyCounts(array $rows): array
    {
        $c = [];
        foreach ($rows as $r) {
            if ($r['family'] === '') continue;
            $c[$r['family']] = ($c[$r['family']] ?? 0) + 1;
        }
        ksort($c);
        return $c;
    }
}
