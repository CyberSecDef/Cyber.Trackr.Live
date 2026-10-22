<?php

namespace App\Service\Search;

/**
 * Queries the inverted index built by IndexBuilder.
 *
 * Behavior:
 *   - Bare tokens AND together (all must match).
 *   - "Quoted phrases" require the literal substring to appear in the
 *     doc's searchable `text` field, in addition to AND-matching their
 *     constituent tokens.
 *   - If at least one bare token matches no doc directly, it falls back
 *     to fuzzy: trigram lookup against the vocabulary, edit-distance <= 1
 *     verification, then re-runs AND with the closest substitute.
 *     Structured identifiers (V-253254, SV-...r1_rule, CCI-000366, AC-2(3))
 *     never fuzz: a near-miss ID is a different requirement, so an unknown
 *     ID returns nothing rather than its nearest neighbour.
 *   - Results are bucketed by doc.type into the same five-section shape
 *     the existing search.html.twig template renders, plus `counts`.
 *     Vulns are ranked newest-first from IndexStore's docmeta and only the
 *     first VULN_LIMIT are read and returned (the page shows 500); counts
 *     carry the full totals (review P2).
 */
class Searcher
{
    /** Review S13. Prod's real searches top out under 100 characters. */
    public const MAX_QUERY_CHARS = 200;
    public const MAX_TERMS = 10;

    /** The search page lists at most this many vulnerability rows. */
    public const VULN_LIMIT = 500;

    public const EMPTY = [
        'rmfv4' => [], 'rmfv5' => [], 'ccis' => [], 'aps' => [], 'vulns' => [],
        'counts' => ['rmfv4' => 0, 'rmfv5' => 0, 'ccis' => 0, 'aps' => 0, 'vulns' => 0],
    ];

    public function __construct(
        private IndexStore $store,
        private QueryParser $parser,
    ) {}

    /**
     * Why a query is refused before any index work, or null if it's fine.
     * Cheap: string length plus the tokenizer, no postings loaded.
     */
    public function rejectionReason(string $query): ?string
    {
        if (mb_strlen($query) > self::MAX_QUERY_CHARS) {
            return sprintf('Search is limited to %d characters.', self::MAX_QUERY_CHARS);
        }
        if (count($this->parser->parse($query)['tokens']) > self::MAX_TERMS) {
            return sprintf('Search is limited to %d terms.', self::MAX_TERMS);
        }
        return null;
    }

    /**
     * @return array{
     *   rmfv4: array, rmfv5: array, ccis: array, aps: array, vulns: array
     * }
     */
    public function search(string $query): array
    {
        // Ordinary queries now stay small, but a quoted phrase over a very
        // broad word still reads every candidate doc to verify the phrase.
        // Hosts that disable ini_set no-op.
        @ini_set('memory_limit', '512M');

        $empty = self::EMPTY;
        if ($this->rejectionReason($query) !== null) {
            return $empty;
        }
        $parsed = $this->parser->parse($query);

        if (empty($parsed['tokens']) && empty($parsed['phrases'])) {
            return $empty;
        }

        // Only the buckets holding the query's tokens are read.
        $postings = $this->store->postingsFor($parsed['tokens']);

        // Token-level AND set.
        $matchedDocSet = $this->andDocs($parsed['tokens'], $postings);

        // If anything came back empty, try fuzzy substitution per missing token.
        if ($matchedDocSet === null) {
            $tokens = $this->fuzzySubstitutions($parsed['tokens'], $postings);
            if ($tokens === null) {
                return $empty;
            }
            $matchedDocSet = $this->andDocs($tokens, $this->store->postingsFor($tokens));
            if ($matchedDocSet === null) {
                return $empty;
            }
        }

        if (empty($matchedDocSet)) {
            return $empty;
        }

        // Phrase verification needs each candidate's text, so phrase queries
        // read every candidate; everything else is decided from docmeta.
        $docs = [];
        if (!empty($parsed['phrases'])) {
            $docs = $this->store->getDocs($matchedDocSet);
            $matchedDocSet = array_filter($matchedDocSet, function ($idx) use ($docs, $parsed) {
                $doc = $docs[$idx] ?? null;
                if (!$doc) return false;
                $hay = strtolower((string) ($doc['text'] ?? ''));
                foreach ($parsed['phrases'] as $p) {
                    if (strpos($hay, $p) === false) return false;
                }
                return true;
            });
        }

        // Bucket by type without reading docs.
        $byType = ['rmfv4' => [], 'rmfv5' => [], 'ccis' => [], 'aps' => [], 'vulns' => []];
        $vulnMeta = [];
        foreach ($matchedDocSet as $idx) {
            $meta = $this->store->meta($idx);
            if ($meta['type'] === null || !isset($byType[$meta['type']])) continue;
            $byType[$meta['type']][] = $idx;
            if ($meta['type'] === 'vulns') $vulnMeta[$idx] = $meta;
        }

        // Vulns: most recent STIG/SCAP release first (timestamp precomputed at
        // build time), ties broken on stig_title, stable otherwise. Only the
        // rows the page shows are read.
        usort($byType['vulns'], function ($a, $b) use ($vulnMeta) {
            $ta = $vulnMeta[$a]['ts'];
            $tb = $vulnMeta[$b]['ts'];
            if ($tb !== $ta) return $tb <=> $ta;
            return strcmp($vulnMeta[$a]['title'], $vulnMeta[$b]['title']);
        });
        $counts = array_map('count', $byType);
        $byType['vulns'] = array_slice($byType['vulns'], 0, self::VULN_LIMIT);

        // RMF / CCI / AP sections list every match.
        $want = array_merge(...array_values($byType));
        $docs += $this->store->getDocs(array_values(array_diff($want, array_keys($docs))));

        $out = $empty;
        foreach ($byType as $type => $idxs) {
            foreach ($idxs as $idx) {
                if (isset($docs[$idx])) $out[$type][] = $docs[$idx];
            }
        }

        // Sort id-keyed buckets alphabetically (matches legacy behavior).
        foreach (['rmfv4', 'rmfv5', 'ccis', 'aps'] as $b) {
            usort($out[$b], fn($a, $b2) => strcmp((string)($a['id'] ?? ''), (string)($b2['id'] ?? '')));
        }
        $out['counts'] = $counts;

        return $out;
    }

    /**
     * Intersect posting lists across all tokens. Returns:
     *   - null if any token has no postings (nothing matches everything)
     *   - empty array if intersection is empty
     *   - list of doc indexes otherwise
     */
    private function andDocs(array $tokens, array $postings): ?array
    {
        if (empty($tokens)) return [];
        $sets = [];
        foreach ($tokens as $tok) {
            if (!isset($postings[$tok])) return null;
            $sets[] = $postings[$tok];
        }
        // Smallest first to keep intersection cheap.
        usort($sets, fn($a, $b) => count($a) - count($b));
        $acc = array_flip($sets[0]);
        for ($i = 1; $i < count($sets); $i++) {
            $next = [];
            foreach ($sets[$i] as $idx) {
                if (isset($acc[$idx])) $next[$idx] = true;
            }
            $acc = $next;
            if (empty($acc)) return [];
        }
        return array_keys($acc);
    }

    /**
     * For each token absent from the postings, find the closest replacement
     * via trigrams + edit-distance <= 1. Returns the substituted token list,
     * or null if any token has no viable replacement. Structured IDs are
     * exact-only and always count as having no replacement.
     */
    private function fuzzySubstitutions(array $tokens, array $postings): ?array
    {
        $tokenizer = new Tokenizer();
        $out = [];

        foreach ($tokens as $tok) {
            if (isset($postings[$tok])) {
                $out[] = $tok;
                continue;
            }
            // V-253258 -> V-25325 is a different STIG entirely, not a typo fix.
            if ($tokenizer->isStructuredId($tok)) {
                return null;
            }
            // Candidate set: tokens sharing >= half of the query token's trigrams.
            $tris = $tokenizer->trigrams($tok);
            $trigrams = $this->store->trigramsFor($tris);
            $candCounts = [];
            foreach ($tris as $tri) {
                foreach ($trigrams[$tri] ?? [] as $cand) {
                    $candCounts[$cand] = ($candCounts[$cand] ?? 0) + 1;
                }
            }
            $threshold = max(1, (int) floor(count($tris) / 2));
            arsort($candCounts);

            $best = null;
            $bestDist = PHP_INT_MAX;
            foreach ($candCounts as $cand => $shared) {
                if ($shared < $threshold) break;
                // No postings check needed: trigrams are built from the
                // final postings vocabulary, so every candidate has postings.
                $d = levenshtein($tok, $cand);
                if ($d < $bestDist) {
                    $bestDist = $d;
                    $best = $cand;
                    if ($d === 0) break;
                }
                if ($d <= 1) {
                    $best = $cand;
                    $bestDist = $d;
                    break;
                }
            }
            if ($best === null || $bestDist > 2) {
                return null;
            }
            $out[] = $best;
        }
        return $out;
    }
}
