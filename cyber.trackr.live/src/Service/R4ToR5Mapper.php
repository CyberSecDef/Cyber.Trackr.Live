<?php
namespace App\Service;

class R4ToR5Mapper
{
    private const R4_XML       = __DIR__ . '/../../resources/data/rmf/800-53v4-controls.xml';
    private const R5_XML       = __DIR__ . '/../../resources/data/rmf/800-53v5-controls.xml';
    private const MAPPING_JSON = __DIR__ . '/../../resources/data/rmf/r4-to-r5-mapping.json';

    public function map(): array
    {
        $r4 = $this->loadCatalog(self::R4_XML, true);
        $r5 = $this->loadCatalog(self::R5_XML, false);
        $curated = $this->loadCurated();

        $rows = [];
        $seen_r4 = [];
        $seen_r5 = [];

        foreach ($curated as $c) {
            $row = [
                'r4'        => $c['r4'] ?? null,
                'r5'        => $c['r5'] ?? null,
                'kind'      => $c['kind'] ?? 'unchanged',
                'rationale' => $c['rationale'] ?? '',
                'source'    => 'curated',
                'r4_title'  => isset($c['r4']) && isset($r4[$c['r4']]) ? $r4[$c['r4']]['title'] : null,
                'r5_title'  => isset($c['r5']) && isset($r5[$c['r5']]) ? $r5[$c['r5']]['title'] : null,
                'family'    => $this->family($c['r4'] ?? $c['r5'] ?? ''),
            ];
            $rows[] = $row;
            if (!empty($c['r4'])) $seen_r4[$c['r4']] = true;
            if (!empty($c['r5'])) $seen_r5[$c['r5']] = true;
        }

        // Mechanical: every r4 number not yet covered.
        foreach ($r4 as $num => $info) {
            if (isset($seen_r4[$num])) continue;
            if (isset($r5[$num])) {
                $rows[] = [
                    'r4'        => $num,
                    'r5'        => $num,
                    'kind'      => 'unchanged',
                    'rationale' => '',
                    'source'    => 'mechanical',
                    'r4_title'  => $info['title'],
                    'r5_title'  => $r5[$num]['title'],
                    'family'    => $this->family($num),
                ];
                $seen_r5[$num] = true;
            } else {
                $rows[] = [
                    'r4'        => $num,
                    'r5'        => null,
                    'kind'      => 'withdrawn',
                    'rationale' => '',
                    'source'    => 'mechanical',
                    'r4_title'  => $info['title'],
                    'r5_title'  => null,
                    'family'    => $this->family($num),
                ];
            }
            $seen_r4[$num] = true;
        }

        // Mechanical: every r5 number not yet covered → new-in-r5.
        foreach ($r5 as $num => $info) {
            if (isset($seen_r5[$num])) continue;
            $rows[] = [
                'r4'        => null,
                'r5'        => $num,
                'kind'      => 'new-in-r5',
                'rationale' => '',
                'source'    => 'mechanical',
                'r4_title'  => null,
                'r5_title'  => $info['title'],
                'family'    => $this->family($num),
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
            $kids  = $cnode->children($defaultNs);
            $num   = trim((string) $kids->number);
            if ($num === '') continue;
            $title = trim((string) $kids->title);

            $controls[$num] = [
                'number' => $num,
                'title'  => $title !== '' ? $title : '(no title)',
            ];
        }

        // Enhancements: <control-enhancement> in the default 2.0 ns.
        foreach ($xml->xpath('//d:control-enhancement') as $enode) {
            $kids   = $enode->children($defaultNs);
            $enum   = trim((string) $kids->number);
            if ($enum === '') continue;
            $etitle = trim((string) $kids->title);

            $controls[$enum] = [
                'number' => $enum,
                'title'  => $etitle !== '' ? $etitle : '(no title)',
            ];
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
