<?php

namespace App\Service\Search;

/**
 * Parses a raw user query into:
 *   - phrases: literal "double-quoted" runs, kept verbatim for substring
 *     verification on candidate docs
 *   - tokens:  bare terms outside of quotes, tokenized via Tokenizer
 *
 * Both feed the AND-by-default search; phrases additionally require their
 * exact substring to appear in the doc's searchable text.
 */
class QueryParser
{
    public function __construct(private Tokenizer $tokenizer) {}

    /**
     * @return array{phrases: string[], tokens: string[]}
     */
    public function parse(string $query): array
    {
        $phrases = [];
        if (preg_match_all('/"([^"]+)"/u', $query, $m)) {
            foreach ($m[1] as $p) {
                $p = strtolower(trim($p));
                if ($p !== '') {
                    $phrases[] = $p;
                }
            }
        }
        $bare = preg_replace('/"[^"]+"/u', ' ', $query) ?? '';
        $tokens = $this->tokenizer->tokenize($bare);

        // Phrase tokens also count toward the AND set so we narrow the
        // candidate pool before doing substring verification.
        foreach ($phrases as $p) {
            foreach ($this->tokenizer->tokenize($p) as $t) {
                if (!in_array($t, $tokens, true)) {
                    $tokens[] = $t;
                }
            }
        }

        return ['phrases' => $phrases, 'tokens' => $tokens];
    }
}
