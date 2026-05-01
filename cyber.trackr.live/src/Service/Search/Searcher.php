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
 *   - Results are bucketed by doc.type into the same five-section shape
 *     the existing search.html.twig template renders.
 */
class Searcher
{
    public function __construct(
        private IndexStore $store,
        private QueryParser $parser,
    ) {}

    /**
     * @return array{
     *   rmfv4: array, rmfv5: array, ccis: array, aps: array, vulns: array
     * }
     */
    public function search(string $query): array
    {
        $empty = ['rmfv4' => [], 'rmfv5' => [], 'ccis' => [], 'aps' => [], 'vulns' => []];
        $parsed = $this->parser->parse($query);

        if (empty($parsed['tokens']) && empty($parsed['phrases'])) {
            return $empty;
        }

        $postings = $this->store->postings();
        $docs = $this->store->docs();

        // Token-level AND set.
        $matchedDocSet = $this->andDocs($parsed['tokens'], $postings);

        // If anything came back empty, try fuzzy substitution per missing token.
        if ($matchedDocSet === null) {
            $tokens = $this->fuzzySubstitutions($parsed['tokens'], $postings);
            if ($tokens === null) {
                return $empty;
            }
            $matchedDocSet = $this->andDocs($tokens, $postings);
            if ($matchedDocSet === null) {
                return $empty;
            }
        }

        // Phrase verification on docs.text.
        if (!empty($parsed['phrases'])) {
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

        // Bucket results.
        $out = $empty;
        foreach ($matchedDocSet as $idx) {
            $doc = $docs[$idx] ?? null;
            if (!$doc) continue;
            $type = $doc['type'] ?? null;
            if (!isset($out[$type])) continue;
            $out[$type][] = $doc;
        }

        // Sort id-keyed buckets alphabetically (matches legacy behavior).
        foreach (['rmfv4', 'rmfv5', 'ccis', 'aps'] as $b) {
            usort($out[$b], fn($a, $b2) => strcmp((string)($a['id'] ?? ''), (string)($b2['id'] ?? '')));
        }
        // Vulns: most recent STIG/SCAP release first, so the 500-row cap
        // surfaces the freshest content. Empty/unparseable dates sink to
        // the bottom; ties break on stig_title for stable ordering.
        usort($out['vulns'], function ($a, $b) {
            $ta = strtotime((string) ($a['released'] ?? '')) ?: 0;
            $tb = strtotime((string) ($b['released'] ?? '')) ?: 0;
            if ($tb !== $ta) return $tb <=> $ta;
            return strcmp((string)($a['stig_title'] ?? ''), (string)($b['stig_title'] ?? ''));
        });

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
     * or null if any token has no viable replacement.
     */
    private function fuzzySubstitutions(array $tokens, array $postings): ?array
    {
        $trigrams = $this->store->trigrams();
        $tokenizer = new Tokenizer();
        $out = [];

        foreach ($tokens as $tok) {
            if (isset($postings[$tok])) {
                $out[] = $tok;
                continue;
            }
            // Candidate set: tokens sharing >= half of the query token's trigrams.
            $tris = $tokenizer->trigrams($tok);
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
                if (!isset($postings[$cand])) continue;
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
