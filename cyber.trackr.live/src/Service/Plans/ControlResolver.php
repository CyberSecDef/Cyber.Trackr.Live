<?php

namespace App\Service\Plans;

use App\Service\OverlayLoader;

/**
 * Given a control family (e.g. 'CM') and a baseline overlay id (e.g.
 * 'fedramp-moderate'), returns the ordered list of applicable controls
 * and enhancements from the 800-53 r5 catalog with the data needed by
 * PlanBuilder: number, title, statement text, related controls, linked
 * CCIs, discussion paragraphs, ODV placeholders.
 *
 * Phase 2A change: enhancements are returned for **every** member of
 * the family regardless of baseline membership - each carries an
 * `in_baseline` boolean. The wizard surfaces all of them so the user
 * can disposition tailored-out enhancements explicitly (Selected /
 * Inherited / Tailored Out + rationale). Base controls still filter
 * to the baseline since a base control outside the baseline isn't
 * part of the plan at all.
 *
 * The OverlayLoader does the heavy lifting for "is this control in this
 * baseline?" by mapping XML control numbers to their member overlays.
 */
class ControlResolver
{
    private string $catalogPath;
    private string $cciPath;
    private ?\SimpleXMLElement $catalog = null;

    /** @var array<string, array>|null inverted CCI map: control number -> [{id, definition}] */
    private ?array $cciByControl = null;

    public function __construct(
        string $projectDir,
        private OverlayLoader $overlays,
        private OdvExtractor $odvExtractor,
    ) {
        $this->catalogPath = $projectDir . '/resources/data/rmf/800-53v5-controls.xml';
        $this->cciPath     = $projectDir . '/resources/data/cci/U_CCI_List.xml';
    }

    /**
     * @return array<int, array{
     *   number: string,
     *   title: string,
     *   is_enhancement: bool,
     *   parent: ?string,
     *   in_baseline: bool,
     *   statement: string,
     *   discussion: string,
     *   related: string[],
     *   ccis: array<int, array{id:string, definition:string}>,
     *   odvs: array<int, array{id:int, kind:string, prompt:string, options:string[], placeholder:string}>
     * }>
     */
    public function resolve(string $family, string $baselineId): array
    {
        $catalog = $this->loadCatalog();
        if ($catalog === null) return [];

        $familyPrefix = strtoupper($family) . '-';
        $out = [];

        foreach ($catalog->xpath('/controls:controls/controls:control') ?: [] as $ctrl) {
            $ctrl->registerXPathNamespace('controls', 'http://scap.nist.gov/schema/sp800-53/feed/2.0');
            $ctrl->registerXPathNamespace('aaa', 'http://scap.nist.gov/schema/sp800-53/2.0');
            $number = trim((string) (($ctrl->xpath('./aaa:number')[0] ?? '') ?: ''));
            if ($number === '' || !str_starts_with($number, $familyPrefix)) continue;

            $base = $this->buildEntry($ctrl, $number, false, null, $baselineId);
            if ($base === null) {
                // Base control isn't in the selected baseline. Per RMF
                // semantics, enhancements can't apply without their base,
                // so skip the entire enhancement walk for this control.
                // This also keeps the wizard from showing 50+ tailored-out
                // cards for controls the user isn't considering at all.
                continue;
            }
            $out[] = $base;

            // Walk control-enhancement siblings nested under this control.
            // The catalog stores them as <control-enhancement> elements
            // inside a <control-enhancements> wrapper, NOT as top-level
            // <controls:control>s, so the parent xpath would never see them.
            // These wrapper + enhancement elements are unprefixed in the
            // XML, putting them in the default namespace (sp800-53/2.0 =
            // our 'aaa' prefix) - NOT the 'controls:' (feed/2.0) namespace.
            foreach ($ctrl->xpath('./aaa:control-enhancements/aaa:control-enhancement') ?: [] as $enh) {
                $enh->registerXPathNamespace('aaa', 'http://scap.nist.gov/schema/sp800-53/2.0');
                $enhNumber = trim((string) (($enh->xpath('./aaa:number')[0] ?? '') ?: ''));
                if ($enhNumber === '') continue;
                $entry = $this->buildEntry($enh, $enhNumber, true, $number, $baselineId);
                if ($entry !== null) $out[] = $entry;
            }
        }

        usort($out, fn($a, $b) => $this->compareControlNumbers($a['number'], $b['number']));
        return $out;
    }

    /**
     * Builds one resolved-control entry from a SimpleXMLElement representing
     * either a <control> or a <control-enhancement>. Returns null when the
     * entry should be filtered out:
     *   - Statement-letter sub-numbers (CM-1a.) which OverlayLoader rejects
     *   - Base controls outside the selected baseline
     */
    private function buildEntry(\SimpleXMLElement $node, string $number, bool $isEnhancement, ?string $parent, string $baselineId): ?array
    {
        if (OverlayLoader::normalize($number) === null) {
            return null;
        }

        $inBaseline = in_array($baselineId, $this->overlays->getControlOverlays($number), true);

        // Base controls outside the baseline are skipped entirely.
        // Enhancements outside the baseline are kept so the wizard can
        // present a disposition picker (default: Tailored Out).
        if (!$isEnhancement && !$inBaseline) {
            return null;
        }

        $statement = $this->renderStatement($node);

        return [
            'number'         => $number,
            'title'          => trim((string) (($node->xpath('./aaa:title')[0] ?? '') ?: '')),
            'is_enhancement' => $isEnhancement,
            'parent'         => $parent,
            'in_baseline'    => $inBaseline,
            'statement'      => $statement,
            'discussion'     => $this->renderDiscussion($node),
            'related'        => $this->extractRelated($node),
            'ccis'           => $this->ccisForControl($number),
            'odvs'           => $this->odvExtractor->extract($statement),
        ];
    }

    private function loadCatalog(): ?\SimpleXMLElement
    {
        if ($this->catalog !== null) return $this->catalog;
        if (!is_file($this->catalogPath)) return null;

        $xml = @simplexml_load_file($this->catalogPath);
        if ($xml === false) return null;

        foreach ($xml->getDocNamespaces() as $prefix => $ns) {
            if ($prefix === '') $prefix = 'a';
            $xml->registerXPathNamespace($prefix, $ns);
        }
        $xml->registerXPathNamespace('aaa', 'http://scap.nist.gov/schema/sp800-53/2.0');

        return $this->catalog = $xml;
    }

    /** Flattens the recursive <statement> tree into readable, indented text. */
    private function renderStatement(\SimpleXMLElement $ctrl): string
    {
        $parts = [];
        foreach ($ctrl->xpath('./aaa:statement') ?: [] as $stmt) {
            $stmt->registerXPathNamespace('aaa', 'http://scap.nist.gov/schema/sp800-53/2.0');
            $this->walkStatement($stmt, $parts, 0);
        }
        return trim(implode("\n", $parts));
    }

    private function walkStatement(\SimpleXMLElement $stmt, array &$parts, int $depth): void
    {
        $stmt->registerXPathNamespace('aaa', 'http://scap.nist.gov/schema/sp800-53/2.0');
        $num   = trim((string) (($stmt->xpath('./aaa:number')[0] ?? '') ?: ''));
        $desc  = trim((string) (($stmt->xpath('./aaa:description')[0] ?? '') ?: ''));
        if ($num !== '' || $desc !== '') {
            $indent = str_repeat('    ', $depth);
            $parts[] = $indent . trim(($num !== '' ? $num . ' ' : '') . $desc);
        }
        foreach ($stmt->xpath('./aaa:statement') ?: [] as $child) {
            $this->walkStatement($child, $parts, $depth + 1);
        }
    }

    /** Joins all <discussion>/<description>/<p> paragraphs into a single string. */
    private function renderDiscussion(\SimpleXMLElement $ctrl): string
    {
        $paras = [];
        foreach ($ctrl->xpath('./aaa:discussion//aaa:p') ?: [] as $p) {
            $text = trim(strip_tags((string) $p->asXML()));
            if ($text !== '') $paras[] = $text;
        }
        return implode("\n\n", $paras);
    }

    /** @return string[] */
    private function extractRelated(\SimpleXMLElement $ctrl): array
    {
        $out = [];
        foreach ($ctrl->xpath('./aaa:related') ?: [] as $r) {
            $val = trim((string) $r);
            if ($val !== '') $out[] = $val;
        }
        return array_values(array_unique($out));
    }

    /** @return array<int, array{id:string, definition:string}> */
    private function ccisForControl(string $controlNumber): array
    {
        $map = $this->cciInvertedMap();
        $key = strtolower($this->normalizeForCci($controlNumber));
        return $map[$key] ?? [];
    }

    /**
     * Builds (once per request) a map from r5 control number -> list of CCIs
     * whose <reference index> includes that control. The reference index
     * format is e.g. "CM-6 b" or "CM-6 b 2"; we match on the prefix before
     * the first space so "CM-6" matches CCIs that reference any clause of CM-6.
     */
    private function cciInvertedMap(): array
    {
        if ($this->cciByControl !== null) return $this->cciByControl;
        $this->cciByControl = [];
        if (!is_file($this->cciPath)) return $this->cciByControl;

        $xml = @simplexml_load_file($this->cciPath);
        if ($xml === false) return $this->cciByControl;
        foreach ($xml->getDocNamespaces() as $prefix => $ns) {
            if ($prefix === '') $prefix = 'a';
            $xml->registerXPathNamespace($prefix, $ns);
        }
        $xml->registerXPathNamespace('xmlns', 'http://iase.disa.mil/cci');

        foreach ($xml->xpath('/xmlns:cci_list/xmlns:cci_items/xmlns:cci_item') ?: [] as $item) {
            // SimpleXML namespace registration is per-element scope; child
            // nodes returned from xpath need their own registerXPathNamespace.
            $item->registerXPathNamespace('xmlns', 'http://iase.disa.mil/cci');
            $id  = (string) ($item->xpath('./@id')[0] ?? '');
            $def = (string) ($item->definition ?? '');
            if ($id === '') continue;

            // Only the most recent version reference (NIST SP 800-53 Rev 5) is what we want.
            foreach ($item->xpath('./xmlns:references/xmlns:reference') ?: [] as $ref) {
                $version = (string) ($ref['version'] ?? '');
                $index   = (string) ($ref['index'] ?? '');
                if ($index === '') continue;
                if ($version !== '' && $version !== '5') continue;

                $controlKey = strtolower(explode(' ', $index, 2)[0]);
                $this->cciByControl[$controlKey][$id] = [
                    'id'         => $id,
                    'definition' => $def,
                ];
            }
        }
        // dedupe within each key (a CCI may reference multiple statement letters of the same control)
        foreach ($this->cciByControl as $k => $list) {
            $this->cciByControl[$k] = array_values($list);
        }
        return $this->cciByControl;
    }

    /** "CM-6"->"cm-6", "CM-6(1)"->"cm-6(1)" — keep parens, lowercase only. */
    private function normalizeForCci(string $n): string
    {
        return strtolower(trim($n));
    }

    /** Sort: parents first, then their enhancements; numerics by integer not string. */
    private function compareControlNumbers(string $a, string $b): int
    {
        preg_match('/^([A-Z]+)-(\d+)(?:\((\d+)\))?$/', $a, $ma);
        preg_match('/^([A-Z]+)-(\d+)(?:\((\d+)\))?$/', $b, $mb);
        if (empty($ma) || empty($mb)) return strcmp($a, $b);

        if ($ma[1] !== $mb[1]) return strcmp($ma[1], $mb[1]);
        if ((int) $ma[2] !== (int) $mb[2]) return (int) $ma[2] <=> (int) $mb[2];
        $ea = isset($ma[3]) ? (int) $ma[3] : -1;
        $eb = isset($mb[3]) ? (int) $mb[3] : -1;
        return $ea <=> $eb;
    }
}
