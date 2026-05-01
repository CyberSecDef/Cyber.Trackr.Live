<?php

namespace App\Service\Search;

/**
 * Builds the inverted index for the search engine. Two entry points:
 *
 *   - buildAll() : full rebuild from scratch. Walks every data source,
 *     wipes the existing index, and writes fresh files.
 *
 *   - syncDeltas() : incremental. Reads sources.json, compares each file's
 *     sha256 against the manifest, and only re-indexes changed/new sources.
 *     Sources that no longer exist (e.g. when a STIG title's "latest" file
 *     advances to a newer release) get their docs dropped.
 *
 * Both produce the same on-disk shape. Auto-triggered after DISA pulls.
 */
class IndexBuilder
{
    public function __construct(
        private string $projectDir,
        private Tokenizer $tokenizer,
        private IndexStore $store,
    ) {}

    public function buildAll(): array
    {
        return $this->run([], true);
    }

    public function syncDeltas(): array
    {
        return $this->run($this->store->sources(), false);
    }

    /**
     * @param array $existingSources sources.json contents (empty for full rebuild)
     * @return array<string, int> stats keyed by stage name
     */
    private function run(array $existingSources, bool $isFull): array
    {
        // Walking ~1.2 GB of STIG XML + holding the postings in memory peaks
        // well above PHP's default 128M. Raise the cap unconditionally so
        // neither the CLI command nor the post-DISA-pull auto-rebuild trips
        // on memory in environments that haven't tuned php.ini. Hosts that
        // disable ini_set will see the call no-op and fall back to whatever
        // memory_limit is already configured.
        @ini_set('memory_limit', '2G');

        $stats = ['unchanged' => 0, 'changed' => 0, 'new' => 0, 'removed' => 0];

        if ($isFull) {
            $docs = [];
            $postings = [];
            $sources = [];
        } else {
            $docs = $this->store->docs();
            $postings = $this->store->postings();
            $sources = $existingSources;
        }

        $expected = $this->collectExpectedSources();

        // 1. Drop docs from sources that are gone (e.g. older STIG superseded).
        foreach ($sources as $path => $meta) {
            if (!isset($expected[$path])) {
                $this->dropDocs($meta['doc_indexes'] ?? [], $docs, $postings);
                unset($sources[$path]);
                $stats['removed']++;
            }
        }

        // 2. For each expected source, sha-check; reindex if new or changed.
        foreach ($expected as $path => $producer) {
            $hash = @hash_file('sha256', $path) ?: '';
            if (isset($sources[$path]) && $sources[$path]['sha256'] === $hash) {
                $stats['unchanged']++;
                continue;
            }

            if (isset($sources[$path])) {
                // Changed: drop existing docs first.
                $this->dropDocs($sources[$path]['doc_indexes'], $docs, $postings);
                $stats['changed']++;
            } else {
                $stats['new']++;
            }

            // Append fresh docs and postings.
            $newIndexes = [];
            foreach ($producer() as $doc) {
                $idx = count($docs);
                $docs[] = $doc;
                $newIndexes[] = $idx;
                foreach ($this->tokenizer->tokenize($doc['text']) as $tok) {
                    $postings[$tok][] = $idx;
                }
            }
            $sources[$path] = [
                'sha256' => $hash,
                'mtime' => filemtime($path) ?: 0,
                'doc_indexes' => $newIndexes,
            ];
        }

        // 3. Compact: dedupe + sort posting lists, drop empty tokens.
        foreach ($postings as $tok => $idxs) {
            $unique = array_values(array_unique($idxs));
            sort($unique);
            if (empty($unique)) {
                unset($postings[$tok]);
            } else {
                $postings[$tok] = $unique;
            }
        }

        // 4. Trigrams are derived from the final vocabulary.
        $trigrams = [];
        foreach (array_keys($postings) as $tok) {
            foreach ($this->tokenizer->trigrams($tok) as $tri) {
                $trigrams[$tri][] = $tok;
            }
        }
        foreach ($trigrams as $tri => $toks) {
            $trigrams[$tri] = array_values(array_unique($toks));
        }

        // 5. Persist.
        $this->store->save(IndexStore::FILE_DOCS, $docs);
        $this->store->save(IndexStore::FILE_POSTINGS, $postings);
        $this->store->save(IndexStore::FILE_TRIGRAMS, $trigrams);
        $this->store->save(IndexStore::FILE_SOURCES, $sources);

        return [
            'docs' => count($docs),
            'tokens' => count($postings),
            'trigrams' => count($trigrams),
            ...$stats,
        ];
    }

    /**
     * Marks the given doc indexes as null in $docs and removes them from
     * every posting list. Walking all postings is O(vocabulary) but the
     * vocabulary is small relative to total doc count, so this is cheaper
     * than re-tokenizing the dropped docs to find their token edges.
     */
    private function dropDocs(array $indexes, array &$docs, array &$postings): void
    {
        $deadSet = array_flip($indexes);
        foreach ($indexes as $i) {
            $docs[$i] = null;
        }
        foreach ($postings as $tok => $idxs) {
            $kept = [];
            foreach ($idxs as $i) {
                if (!isset($deadSet[$i])) $kept[] = $i;
            }
            if (empty($kept)) {
                unset($postings[$tok]);
            } elseif (count($kept) !== count($idxs)) {
                $postings[$tok] = $kept;
            }
        }
    }

    /**
     * Maps source-file path -> generator that yields its docs. Building the
     * map is cheap; calling the generator only happens for new/changed
     * sources, so unchanged sources cost only one sha256.
     *
     * @return array<string, callable(): iterable>
     */
    private function collectExpectedSources(): array
    {
        $sources = [];

        // 1. CCI master XML.
        $cciPath = $this->projectDir . '/resources/data/cci/U_CCI_List.xml';
        if (is_file($cciPath)) {
            $sources[$cciPath] = fn() => $this->produceCcis($cciPath);
        }

        // 2. NIST 800-53 r4 + r5 control catalogs.
        $r4 = $this->projectDir . '/resources/data/rmf/800-53v4-controls.xml';
        if (is_file($r4)) {
            $sources[$r4] = fn() => $this->produceControls($r4, 'rmfv4');
        }
        $r5 = $this->projectDir . '/resources/data/rmf/800-53v5-controls.xml';
        if (is_file($r5)) {
            $sources[$r5] = fn() => $this->produceControls($r5, 'rmfv5');
        }

        // 3. AP definitions (Assessment Procedures from r4 reference dataset).
        $apJson = $this->projectDir . '/resources/data/800-53r4.json';
        if (is_file($apJson)) {
            $sources[$apJson] = fn() => $this->produceAps($apJson);
        }

        // 4. STIG XML — only the latest release per title, mirroring the
        //    behavior of the live search controller.
        $tocPath = $this->projectDir . '/resources/data/stig_toc.json';
        if (is_file($tocPath)) {
            $toc = json_decode((string) file_get_contents($tocPath), true) ?: [];
            foreach ($toc as $title => $entries) {
                if (!is_array($entries) || empty($entries)) continue;
                usort($entries, fn($a, $b) => strtotime((string)($b['date'] ?? '')) - strtotime((string)($a['date'] ?? '')));
                $latest = $entries[0];
                $filename = $latest['filename'] ?? '';
                $stigPath = $this->projectDir . '/resources/data/stig/' . $filename;
                if (!is_file($stigPath)) continue;
                $sources[$stigPath] = fn() => $this->produceStigRules($stigPath, $title, $latest);
            }
        }

        return $sources;
    }

    private function produceCcis(string $path): iterable
    {
        $xml = @simplexml_load_file($path);
        if ($xml === false) return;
        foreach ($xml->getDocNamespaces() as $prefix => $ns) {
            if ($prefix === '') $prefix = 'a';
            $xml->registerXPathNamespace($prefix, $ns);
        }
        $xml->registerXPathNamespace('xmlns', 'http://iase.disa.mil/cci');
        foreach ($xml->xpath('/xmlns:cci_list/xmlns:cci_items/xmlns:cci_item') ?: [] as $item) {
            $id = (string) ($item->xpath('./@id')[0] ?? '');
            $def = (string) ($item->definition ?? '');
            if ($id === '') continue;
            yield [
                'id' => $id,
                'type' => 'ccis',
                'title' => '',
                'note' => '',
                'description' => $def,
                'text' => $id . ' ' . $def,
            ];
        }
    }

    private function produceControls(string $path, string $type): iterable
    {
        $xml = @simplexml_load_file($path);
        if ($xml === false) return;
        foreach ($xml->getDocNamespaces() as $prefix => $ns) {
            if ($prefix === '') $prefix = 'a';
            $xml->registerXPathNamespace($prefix, $ns);
        }
        $xml->registerXPathNamespace('aaa', 'http://scap.nist.gov/schema/sp800-53/2.0');

        foreach ($xml->xpath('/controls:controls/controls:control') ?: [] as $ctrl) {
            $ctrl->registerXPathNamespace('aaa', 'http://scap.nist.gov/schema/sp800-53/2.0');
            $id = (string) (($ctrl->xpath('./aaa:number')[0] ?? '') ?: '');
            $title = (string) (($ctrl->xpath('./aaa:title')[0] ?? '') ?: '');
            $description = implode("\n", array_map('strval', $ctrl->xpath('.//aaa:description') ?: []));
            if ($id === '') continue;
            yield [
                'id' => $id,
                'type' => $type,
                'title' => $title,
                'note' => '',
                'description' => $description,
                'text' => $id . ' ' . $title . ' ' . $description,
            ];
        }
    }

    private function produceAps(string $path): iterable
    {
        $raw = (string) @file_get_contents($path);
        $data = json_decode($raw);
        if (!is_array($data)) return;
        foreach ($data as $control) {
            if (!isset($control->ccis) || !is_array($control->ccis)) continue;
            foreach ($control->ccis as $ap) {
                $apAcronym = (string) ($ap->ap_acronym ?? '');
                if ($apAcronym === '') continue;
                yield [
                    'id' => $apAcronym,
                    'type' => 'aps',
                    'title' => (string) ($ap->id ?? ''),
                    'note' => (string) ($ap->guidance_org ?? ''),
                    'description' => (string) ($ap->definition ?? ''),
                    'text' => $apAcronym . ' '
                        . ($ap->id ?? '') . ' '
                        . ($ap->guidance_org ?? '') . ' '
                        . ($ap->definition ?? ''),
                ];
            }
        }
    }

    private function produceStigRules(string $path, string $title, array $latest): iterable
    {
        $xml = @simplexml_load_file($path);
        if ($xml === false) return;
        foreach ($xml->getDocNamespaces() as $prefix => $ns) {
            if ($prefix === '') $prefix = 'a';
            $xml->registerXPathNamespace($prefix, $ns);
        }
        $xml->registerXPathNamespace('xmlns', 'http://checklists.nist.gov/xccdf/1.1');

        foreach ($xml->xpath('//xmlns:Group') ?: [] as $group) {
            $group->registerXPathNamespace('xmlns', 'http://checklists.nist.gov/xccdf/1.1');
            $groupId = (string) (($group->xpath('./@id')[0] ?? '') ?: '');
            $ruleId = (string) (($group->xpath('./xmlns:Rule/@id')[0] ?? '') ?: '');
            $ruleTitle = implode(',', array_map('strval', $group->xpath('./xmlns:Rule/xmlns:title/text()') ?: []));
            $ruleDesc = implode(',', array_map('strval', $group->xpath('./xmlns:Rule/xmlns:description/text()') ?: []));
            if ($groupId === '') continue;
            yield [
                'id' => $groupId,
                'type' => 'vulns',
                'stig_title' => $title,
                'version' => $latest['version'] ?? '',
                'release' => $latest['release'] ?? '',
                'released' => $latest['released'] ?? '',
                'filename' => $latest['filename'] ?? '',
                'group' => $groupId,
                'vuln' => $groupId,
                'rule' => $ruleId,
                'note' => $ruleTitle,
                'description' => $ruleDesc,
                'text' => $groupId . ' ' . $ruleId . ' ' . $ruleTitle . ' ' . $ruleDesc,
            ];
        }
    }
}
