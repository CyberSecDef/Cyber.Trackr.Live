<?php

namespace App\Service;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * CCI id → base 800-53 control ("CCI-000015" → "AC-2"), for the RMF Control
 * column on STIG and SCAP view pages.
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

    /** @var array<string,string>|null */
    private ?array $map = null;

    public function __construct(
        string $projectDir,
        private CacheInterface $cache,
    ) {
        $this->path = $projectDir . '/resources/data/cci/U_CCI_List.xml';
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
        $key = sprintf('cci_control_map.%d.%d', filemtime($this->path), filesize($this->path));

        return $this->map = $this->cache->get($key, fn (ItemInterface $item) => $this->build());
    }

    public function get(string $cci): ?string
    {
        return $this->all()[$cci] ?? null;
    }

    /** @return array<string,string> */
    private function build(): array
    {
        $xml = simplexml_load_file($this->path);
        if ($xml === false) {
            return [];
        }
        $xml->registerXPathNamespace('c', self::NS);

        $map = [];
        foreach ($xml->xpath('/c:cci_list/c:cci_items/c:cci_item') ?: [] as $item) {
            $id = (string) $item['id'];
            // First occurrence wins, as the old `xpath(...)|first` did.
            if ($id === '' || isset($map[$id])) {
                continue;
            }
            $ref = $item->references->reference[0] ?? null;
            if ($ref === null) {
                continue;
            }
            // Same as the template's `index|split(' ')|first`: "AC-2 (1)" → "AC-2".
            $map[$id] = explode(' ', (string) $ref['index'])[0];
        }

        return $map;
    }
}
