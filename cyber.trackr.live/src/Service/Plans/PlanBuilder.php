<?php

namespace App\Service\Plans;

/**
 * Schema + user answers -> structured Plan array consumed by both
 * HtmlRenderer and (later) DocxRenderer. Phase 1A scope: front matter,
 * introduction, system overview, family approach. Per-control entries
 * land in Phase 1B.
 *
 * Structure intentionally returns an associative array (not a typed
 * object) so it serializes cleanly to JSON for debugging and so future
 * renderers can consume it without coupling to a class.
 */
class PlanBuilder
{
    public function __construct(
        private PlanRegistry $registry,
        private ControlResolver $resolver,
    ) {}

    /**
     * @param string $family  family code (e.g. 'cm')
     * @param array  $answers user input shaped per BYOJSON spec
     * @return array{
     *   meta: array,
     *   front_matter: array,
     *   body: array,
     *   back_matter: array
     * }
     */
    public function build(string $family, array $answers): array
    {
        $schema = $this->registry->getSchema($family);
        if ($schema === null) {
            throw new \InvalidArgumentException("Unknown family: $family");
        }
        $shared = $this->registry->getShared();

        $system   = $answers['system']         ?? [];
        $famAns   = $answers['family_answers'] ?? [];
        $document = $answers['document']       ?? [];
        $baseline = (string) ($answers['baseline'] ?? '');
        $controlAns = $answers['controls'] ?? [];

        $controls = $baseline !== ''
            ? $this->resolver->resolve(strtoupper($family), $baseline)
            : [];

        // Merge user-supplied per-control answers onto the resolved
        // catalog data. Controls without answers get an empty answers
        // sub-key so the renderer always sees the same shape - missing
        // fields render as [TO BE COMPLETED] downstream.
        foreach ($controls as &$c) {
            $c['answers'] = $this->normalizeControlAnswers($controlAns[$c['number']] ?? []);
        }
        unset($c);

        return [
            'meta' => [
                'family'      => $schema['family']      ?? strtoupper($family),
                'family_name' => $schema['family_name'] ?? $family,
                'title'       => $schema['title']       ?? $family,
                'description' => $schema['description'] ?? '',
                'baseline'    => $baseline,
            ],
            'front_matter' => [
                'cover' => [
                    'title'           => $schema['title'] ?? '',
                    'system_name'     => $system['system_name']    ?? '[SYSTEM NAME]',
                    'classification'  => $system['classification'] ?? 'Unclassified',
                    'version'         => $document['version']      ?? '1.0',
                    'publication_date' => $document['publication_date'] ?? '',
                ],
                'doc_control' => [
                    [
                        'version' => $document['version']          ?? '1.0',
                        'date'    => $document['publication_date'] ?? '',
                        'author'  => $document['author']           ?? '',
                        'changes' => 'Initial release.',
                    ],
                ],
                'executive_summary' => $this->buildExecutiveSummary($schema, $system),
            ],
            'body' => [
                'introduction' => [
                    'purpose'    => $this->renderTemplate($schema['intro_template'] ?? '', $system + $famAns),
                    'scope'      => 'This plan applies to the ' . ($system['system_name'] ?? '[SYSTEM NAME]') . ' authorization boundary as defined in section 2.',
                    'audience'   => 'System owner, ISSO, ISSM, assessors, auditors, and personnel involved in the configuration management of the system.',
                    'references' => $schema['references'] ?? [],
                ],
                'system_overview' => [
                    'description' => $system['boundary_description'] ?? '[TO BE COMPLETED]',
                    'environment' => $system['environment'] ?? '',
                    'classification' => $system['classification'] ?? '',
                ],
                'roles' => [
                    ['role' => 'System Owner',  'name' => $system['system_owner'] ?? '[TO BE COMPLETED]'],
                    ['role' => 'ISSO',          'name' => $system['isso']         ?? '[TO BE COMPLETED]'],
                    ['role' => 'ISSM',          'name' => $system['issm']         ?? '[TO BE COMPLETED]'],
                ],
                'family_approach' => [
                    'title'   => $schema['approach_section_title'] ?? 'Approach',
                    'narrative' => $this->renderTemplate($schema['approach_template'] ?? '', $system + $famAns),
                    'family_answers' => $famAns,
                ],
                'controls' => $controls,
                'continuous_monitoring' => [
                    'title'     => $schema['continuous_monitoring_section_title'] ?? 'Continuous Monitoring',
                    'narrative' => $this->renderTemplate($schema['continuous_monitoring_template'] ?? '', $system + $famAns),
                ],
            ],
            'back_matter' => [
                'glossary'   => $schema['glossary']   ?? [],
                'acronyms'   => $schema['acronyms']   ?? [],
                'references' => $schema['references'] ?? [],
            ],
        ];
    }

    /**
     * Naive {placeholder} substitution — replaces each {key} with $answers[key]
     * if present, leaves it bracketed as [TO BE COMPLETED: key] otherwise so
     * gaps are visible in the rendered plan rather than silent.
     */
    private function renderTemplate(string $template, array $answers): string
    {
        if ($template === '') return '';
        return preg_replace_callback('/\{([a-z_]+)\}/i', function ($m) use ($answers) {
            $key = $m[1];
            $val = $answers[$key] ?? null;
            if ($val === null || $val === '') {
                return '[TO BE COMPLETED: ' . $key . ']';
            }
            return (string) $val;
        }, $template) ?? $template;
    }

    /**
     * Normalizes a single control's answers into a predictable shape.
     * Empty fields become null so the template can fall back to a
     * "[TO BE COMPLETED]" placeholder cleanly.
     */
    private function normalizeControlAnswers(array $a): array
    {
        return [
            'status'               => $a['status']               ?? null,
            'narrative'            => $a['narrative']            ?? null,
            'inheritance_provider' => $a['inheritance_provider'] ?? null,
            'inheritance_details'  => $a['inheritance_details']  ?? null,
            'na_rationale'         => $a['na_rationale']         ?? null,
            'responsible_role'     => $a['responsible_role']     ?? null,
            'evidence'             => is_array($a['evidence'] ?? null) ? array_values(array_filter($a['evidence'], 'strlen')) : [],
        ];
    }

    private function buildExecutiveSummary(array $schema, array $system): string
    {
        $name = $system['system_name'] ?? '[SYSTEM NAME]';
        $title = $schema['title'] ?? 'Plan';
        return "This $title describes the configuration management practices used to maintain the integrity of $name throughout its operational lifecycle. It satisfies the documentation requirements of the CM control family in NIST SP 800-53 r5 and supports the ongoing authorization of the system.";
    }
}
