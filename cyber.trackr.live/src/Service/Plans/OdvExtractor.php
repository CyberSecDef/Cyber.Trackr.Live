<?php

namespace App\Service\Plans;

/**
 * Extracts organization-defined value placeholders from 800-53 r5 control
 * statement text and produces a substitutable representation.
 *
 * The catalog uses three bracketed forms:
 *
 *   [Assignment: organization-defined <prompt>]
 *   [Selection: <opt>; <opt>; ...]
 *   [Selection (one or more): <opt>; <opt>; ...]
 *
 * Bracket nesting is real (Selections can contain Assignments), so the
 * parser tracks bracket depth rather than using a naive regex. The
 * common case is flat; for nested cases we capture the outer form and
 * leave the inner literal in the option string. Future iteration can
 * recurse if it becomes a problem.
 *
 * Each extracted ODV is assigned a stable position-index within its
 * control. IDs survive catalog text edits as long as NIST doesn't
 * reorder sub-statements (rare).
 */
class OdvExtractor
{
    public const KIND_ASSIGNMENT     = 'assignment';
    public const KIND_SELECTION_ONE  = 'selection_one';
    public const KIND_SELECTION_MANY = 'selection_many';

    /**
     * Find all ODV placeholders in $text and return them in document order.
     * Each ODV gets an index = position in the iteration; combined with the
     * control number elsewhere it forms the storage key.
     *
     * @return array<int, array{
     *   id: int,
     *   kind: string,
     *   prompt: string,
     *   options: string[],
     *   placeholder: string
     * }>
     */
    public function extract(string $text): array
    {
        $out = [];
        $idx = 0;
        foreach ($this->findBracketedSpans($text) as $span) {
            $parsed = $this->parseSpan($span);
            if ($parsed === null) continue;
            $parsed['id'] = $idx++;
            $parsed['placeholder'] = $span;
            $out[] = $parsed;
        }
        return $out;
    }

    /**
     * Walk the text and yield every top-level [...]-bracketed substring
     * that begins with "Assignment:" or "Selection". Inner brackets are
     * tolerated by tracking depth.
     *
     * @return iterable<string>
     */
    private function findBracketedSpans(string $text): iterable
    {
        $len = strlen($text);
        $i = 0;
        while ($i < $len) {
            $ch = $text[$i];
            if ($ch === '[') {
                if ($this->looksLikeOdv(substr($text, $i, 24))) {
                    $start = $i;
                    $depth = 0;
                    while ($i < $len) {
                        if ($text[$i] === '[') $depth++;
                        elseif ($text[$i] === ']') {
                            $depth--;
                            if ($depth === 0) { $i++; break; }
                        }
                        $i++;
                    }
                    yield substr($text, $start, $i - $start);
                    continue;
                }
            }
            $i++;
        }
    }

    private function looksLikeOdv(string $head): bool
    {
        return str_starts_with($head, '[Assignment:')
            || str_starts_with($head, '[Selection:')
            || str_starts_with($head, '[Selection (one or more)');
    }

    /**
     * Parse a single bracketed span into its structured form. Returns
     * null if the inner shape doesn't match a known ODV pattern (defense).
     */
    private function parseSpan(string $span): ?array
    {
        // Strip outer brackets.
        $inner = substr($span, 1, -1);

        if (str_starts_with($inner, 'Assignment:')) {
            $prompt = trim(substr($inner, strlen('Assignment:')));
            // Remove a leading "organization-defined " so the field label
            // reads "Frequency" not "organization-defined frequency".
            $prompt = preg_replace('/^organization-defined\s+/i', '', $prompt) ?? $prompt;
            return [
                'kind'    => self::KIND_ASSIGNMENT,
                'prompt'  => trim($prompt),
                'options' => [],
            ];
        }

        if (preg_match('/^Selection\s*\(one or more\)\s*:\s*(.*)$/s', $inner, $m)) {
            return [
                'kind'    => self::KIND_SELECTION_MANY,
                'prompt'  => 'Choose one or more',
                'options' => $this->splitOptions($m[1]),
            ];
        }

        if (preg_match('/^Selection\s*:\s*(.*)$/s', $inner, $m)) {
            return [
                'kind'    => self::KIND_SELECTION_ONE,
                'prompt'  => 'Choose one',
                'options' => $this->splitOptions($m[1]),
            ];
        }

        return null;
    }

    private function splitOptions(string $body): array
    {
        // Options separated by ";". Some catalog entries have a trailing
        // " ; " inside the same option when nested - we tolerate that
        // since the option list is presented as-is to the user.
        $parts = explode(';', $body);
        $out = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p !== '') $out[] = $p;
        }
        return $out;
    }

    /**
     * Splits the statement text into a sequence of typed segments:
     *   ['type' => 'text', 'value' => 'Review the policy ']
     *   ['type' => 'odv',  'value' => 'quarterly', 'filled' => true,  'odv' => $odv]
     *   ['type' => 'odv',  'value' => '[TO BE COMPLETED: frequency]', 'filled' => false, 'odv' => $odv]
     *
     * Each renderer iterates segments and applies its own markup (HTML
     * <strong>, DOCX bold-run, Markdown **). The `filled` flag lets
     * renderers signal unfilled ODVs distinctly (e.g. accent color).
     *
     * @param array $odvs   output of extract()
     * @param array $values {odv_id => string|string[]}
     * @return array<int, array{type: string, value: string, filled?: bool, odv?: array}>
     */
    public function tokenize(string $text, array $odvs, array $values): array
    {
        if (empty($odvs)) {
            return $text === '' ? [] : [['type' => 'text', 'value' => $text]];
        }

        $segments = [];
        $offset = 0;
        foreach ($odvs as $o) {
            $needle = $o['placeholder'];
            $pos = strpos($text, $needle, $offset);
            if ($pos === false) {
                // Same placeholder text appearing again would be skipped.
                $pos = strpos($text, $needle);
                if ($pos === false) continue;
            }
            if ($pos > $offset) {
                $segments[] = ['type' => 'text', 'value' => substr($text, $offset, $pos - $offset)];
            }

            $val = $values[$o['id']] ?? null;
            $filled = !($val === null || $val === '' || $val === []);
            if ($filled) {
                $rendered = is_array($val) ? implode(', ', array_map('strval', $val)) : (string) $val;
            } else {
                $rendered = '[TO BE COMPLETED: ' . ($o['prompt'] !== '' ? $o['prompt'] : 'organizational value') . ']';
            }

            $segments[] = ['type' => 'odv', 'value' => $rendered, 'filled' => $filled, 'odv' => $o];
            $offset = $pos + strlen($needle);
        }

        if ($offset < strlen($text)) {
            $segments[] = ['type' => 'text', 'value' => substr($text, $offset)];
        }
        return $segments;
    }

    /**
     * Substitute the user's chosen values back into the statement text.
     *
     * @param string $text       original statement
     * @param array  $odvs       output of extract()
     * @param array  $values     {odv_id => string|string[]}
     * @param ?callable $wrap    optional fn(string) called on each substituted
     *                           value to e.g. add markup ("**X**" / "<strong>X</strong>")
     */
    public function substitute(string $text, array $odvs, array $values, ?callable $wrap = null): string
    {
        $wrap ??= fn(string $v) => $v;

        // Replace from the LAST occurrence to the first so earlier index
        // positions don't shift when later ones are replaced.
        $byPlaceholder = [];
        foreach ($odvs as $o) {
            $byPlaceholder[] = ['placeholder' => $o['placeholder'], 'id' => $o['id'], 'kind' => $o['kind']];
        }

        $offset = 0;
        $out = $text;
        foreach ($odvs as $o) {
            $needle = $o['placeholder'];
            $pos = strpos($out, $needle, $offset);
            if ($pos === false) {
                // Same placeholder text appearing again would be skipped by
                // moving offset forward; fall back to raw search.
                $pos = strpos($out, $needle);
                if ($pos === false) continue;
            }

            $val = $values[$o['id']] ?? null;
            if ($val === null || $val === '' || $val === []) {
                // Unfilled - render a [TO BE COMPLETED] marker rather than
                // the catalog placeholder so the assessor sees it's missing.
                $rendered = $wrap('[TO BE COMPLETED: ' . ($o['prompt'] !== '' ? $o['prompt'] : 'organizational value') . ']');
            } else {
                if (is_array($val)) {
                    $rendered = $wrap(implode(', ', array_map('strval', $val)));
                } else {
                    $rendered = $wrap((string) $val);
                }
            }

            $out = substr($out, 0, $pos) . $rendered . substr($out, $pos + strlen($needle));
            $offset = $pos + strlen($rendered);
        }
        return $out;
    }
}
