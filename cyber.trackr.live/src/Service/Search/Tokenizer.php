<?php

namespace App\Service\Search;

/**
 * Splits free-form text into searchable tokens for the inverted index.
 *
 * Rules:
 *   - lowercase
 *   - non-alphanumeric (except hyphen + underscore) becomes whitespace
 *   - tokens shorter than 2 chars are dropped
 *   - a small stopword list is dropped
 *   - structured identifiers like CM-6, CCI-000366, V-12345, AC-2(3) are
 *     kept intact (detected by `letters-digit/paren` shape)
 *   - hyphenated words that *aren't* structured IDs are also emitted as
 *     their components (so "windows-server" indexes as windows-server,
 *     windows, and server)
 */
class Tokenizer
{
    private const STOP_WORDS = [
        'a','an','the','of','to','in','is','are','be','and','or','for','as',
        'at','by','on','with','this','that','it','its','from','have','has',
        'had','will','would','should','can','may','must','shall','if','else',
        'than','then','also','not','no','yes','any','all','some','such',
        'into','onto','out','up','down','over','under',
    ];

    /** @return string[] distinct tokens, order-preserving */
    public function tokenize(string $text): array
    {
        $text = strtolower($text);
        $text = preg_replace('/[^a-z0-9\-_()]+/u', ' ', $text) ?? '';
        $raw = preg_split('/\s+/', trim($text)) ?: [];

        $out = [];
        $seen = [];
        foreach ($raw as $tok) {
            $tok = trim($tok, "-_");
            if (strlen($tok) < 2) continue;
            if (in_array($tok, self::STOP_WORDS, true)) continue;

            $this->emit($tok, $out, $seen);

            // Structured IDs like ac-2, cci-000366, v-12345 keep joined form only.
            // Other hyphenated tokens also emit each component.
            if (str_contains($tok, '-') && !$this->isStructuredId($tok)) {
                foreach (explode('-', $tok) as $part) {
                    $part = trim($part, "()_");
                    if (strlen($part) >= 2 && !in_array($part, self::STOP_WORDS, true)) {
                        $this->emit($part, $out, $seen);
                    }
                }
            }
        }
        return $out;
    }

    private function emit(string $tok, array &$out, array &$seen): void
    {
        if (!isset($seen[$tok])) {
            $seen[$tok] = true;
            $out[] = $tok;
        }
    }

    /**
     * "ac-2", "cm-6(1)", "cci-000366", "v-12345", "sv-67890r1_rule" all match.
     * "windows-server" doesn't.
     */
    private function isStructuredId(string $tok): bool
    {
        return (bool) preg_match('/^[a-z]+-[\d(]/', $tok);
    }

    /** Trigrams of a single token, with boundary markers, for fuzzy lookup. */
    public function trigrams(string $token): array
    {
        $padded = '_' . $token . '_';
        $out = [];
        $len = strlen($padded);
        for ($i = 0; $i <= $len - 3; $i++) {
            $out[substr($padded, $i, 3)] = true;
        }
        return array_keys($out);
    }
}
