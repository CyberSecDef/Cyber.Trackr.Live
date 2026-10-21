<?php

namespace App\Service;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * CCI id → base 800-53 control ("CCI-000015" → "AC-2"), for the RMF Control
 * column on STIG and SCAP view pages. Those link to the Rev 5 catalog, so:
 *
 *   1. the Rev 5 reference from DISA's 2024 CCI list, when it has one;
 *   2. otherwise the first reference in the older U_CCI_List.xml, which is
 *      what these pages always showed (the 2024 list orders some Rev 3-only
 *      CCIs' references differently, e.g. CCI-001148 would flip from SC-13
 *      to the withdrawn AU-10(5));
 *   3. otherwise, for CCIs only in the 2024 list, its first reference.
 *
 * The 2024 list is a superset of the old one and adds ~1,550 CCIs, including
 * the CCI-004xxx range newer STIGs cite.
 *
 * The templates used to run two `//cci_item[@id=…]` XPath scans over the
 * 2.4 MB CCI list per rule, which was ~1.6 s of a 1.7 s RHEL 9 render
 * (review P1). Building the whole map takes ~2.5 ms; it's cached in
 * cache.app keyed on the file's mtime and size, so a page normally doesn't
 * parse the XML at all.
 */
class CciControlMap
{
    private const NS = 'http://iase.disa.mil/cci';

    private string $path;
    private string $legacyPath;

    /** @var array<string,string>|null */
    private ?array $map = null;

    public function __construct(
        string $projectDir,
        private CacheInterface $cache,
    ) {
        $this->path       = $projectDir . '/resources/data/cci/U_CCI_List_2024.xml';
        $this->legacyPath = $projectDir . '/resources/data/cci/U_CCI_List.xml';
    }

    /** @return array<string,string> */
    public function all(): array
    {
        if ($this->map !== null) {
            return $this->map;
        }
        if (!is_file($this->path)) {
            return $this->map = [];
        }
        $key = sprintf('cci_control_map.v2.%d.%d.%d', filemtime($this->path), filesize($this->path),
            is_file($this->legacyPath) ? filemtime($this->legacyPath) : 0);

        return $this->map = $this->cache->get($key, fn (ItemInterface $item) => $this->build());
    }

    public function get(string $cci): ?string
    {
        return $this->all()[$cci] ?? null;
    }

    /** @return array<string,string> */
    private function build(): array
    {
        $legacy = is_file($this->legacyPath) ? $this->firstReferences($this->legacyPath) : [];

        $map = [];
        foreach ($this->items($this->path) as $id => $item) {
            $rev5 = null;
            foreach ($item->references->reference as $ref) {
                if ((string) $ref['version'] === '5' && str_contains((string) $ref['title'], '800-53 Revision 5')) {
                    $rev5 = $ref;
                    break;
                }
            }
            if ($rev5 !== null) {
                $map[$id] = self::baseControl($rev5);
            } elseif (isset($legacy[$id])) {
                $map[$id] = $legacy[$id];
            } elseif (isset($item->references->reference[0])) {
                $map[$id] = self::baseControl($item->references->reference[0]);
            }
        }

        return $map;
    }

    /** @return array<string,string> id => base control of its first reference */
    private function firstReferences(string $path): array
    {
        $out = [];
        foreach ($this->items($path) as $id => $item) {
            if (isset($item->references->reference[0])) {
                $out[$id] = self::baseControl($item->references->reference[0]);
            }
        }
        return $out;
    }

    /** @return iterable<string,\SimpleXMLElement> first occurrence of each id */
    private function items(string $path): iterable
    {
        $xml = simplexml_load_file($path);
        if ($xml === false) {
            return;
        }
        $xml->registerXPathNamespace('c', self::NS);
        $seen = [];
        foreach ($xml->xpath('/c:cci_list/c:cci_items/c:cci_item') ?: [] as $item) {
            $id = (string) $item['id'];
            if ($id === '' || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            yield $id => $item;
        }
    }

    /**
     * "AC-2 (1)" → "AC-2". Matched by pattern rather than split on the first
     * space (the templates' old `index|split(' ')|first`) because one Rev 5
     * index is a list, "SC-37, SC-37 (1)", which that would turn into "SC-37,".
     */
    private static function baseControl(\SimpleXMLElement $ref): string
    {
        $index = (string) $ref['index'];
        return preg_match('/^[A-Z]{2}-\d+/', $index, $m) ? $m[0] : explode(' ', $index)[0];
    }
}
