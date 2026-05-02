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
        private OdvExtractor $odvExtractor,
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

        $resolved = $baseline !== ''
            ? $this->resolver->resolve(strtoupper($family), $baseline)
            : [];

        // Merge user answers onto the resolved catalog data, then
        // group enhancements under their parent base controls.
        $perControlGuidance = $schema['per_control_guidance'] ?? [];
        foreach ($resolved as &$c) {
            $userControl = $controlAns[$c['number']] ?? [];
            $c['answers']         = $this->normalizeControlAnswers($userControl);
            $c['odv_values']      = is_array($userControl['odv'] ?? null) ? $userControl['odv'] : [];
            $c['extras']          = is_array($userControl['extras'] ?? null) ? $userControl['extras'] : [];
            $c['disposition']     = $userControl['disposition'] ?? ($c['is_enhancement'] && !$c['in_baseline'] ? 'Tailored Out' : null);
            $c['disposition_rationale'] = $userControl['disposition_rationale'] ?? null;
            $c['statement_segments']    = $this->odvExtractor->tokenize(
                $c['statement'],
                $c['odvs'],
                $c['odv_values']
            );
            $c['guidance']         = $perControlGuidance[$c['number']] ?? [];
            $c['extras_rendered']  = $this->prepareExtrasForRender($c['guidance'], $c['extras']);
        }
        unset($c);

        // Group enhancements under their parent base control. Renderers
        // walk top-level entries; enhancements live in $base['enhancements'].
        $controls = $this->groupEnhancementsUnderParents($resolved);

        // Phase 2B: walk grouped family_questions and bucket the user's
        // answers per group, sorted by their target document section.
        // Renderers iterate body.family_sections to render structured
        // sub-sections for CCB, audit strategy, drift detection, etc.
        $familySections = $this->buildFamilySections($schema['family_questions'] ?? [], $famAns);

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
                'inheritance_reminder' => [
                    'commonly_inheritable' => $schema['commonly_inheritable_controls'] ?? [],
                    'family_name'          => $schema['family_name'] ?? '',
                ],
                'system_overview' => [
                    'description'    => $system['boundary_description'] ?? '[TO BE COMPLETED]',
                    'environment'    => $system['environment']    ?? '',
                    'classification' => $system['classification'] ?? '',
                    'sub_sections'   => $familySections['system_overview'] ?? [],
                ],
                'roles' => [
                    ['role' => 'System Owner',  'name' => $system['system_owner'] ?? '[TO BE COMPLETED]'],
                    ['role' => 'ISSO',          'name' => $system['isso']         ?? '[TO BE COMPLETED]'],
                    ['role' => 'ISSM',          'name' => $system['issm']         ?? '[TO BE COMPLETED]'],
                ],
                'family_approach' => [
                    'title'        => $schema['approach_section_title'] ?? 'Approach',
                    'narrative'    => $this->renderTemplate($schema['approach_template'] ?? '', $system + $famAns),
                    'sub_sections' => $familySections['approach'] ?? [],
                ],
                'controls' => $controls,
                'continuous_monitoring' => [
                    'title'        => $schema['continuous_monitoring_section_title'] ?? 'Continuous Monitoring',
                    'narrative'    => $this->renderTemplate($schema['continuous_monitoring_template'] ?? '', $system + $famAns),
                    'sub_sections' => $familySections['continuous_monitoring'] ?? [],
                ],
                'integration' => [
                    'title'        => $schema['integration_section_title'] ?? 'Integration with Other RMF Artifacts',
                    'narrative'    => $this->renderTemplate($schema['integration_template'] ?? '', $system + $famAns),
                    'sub_sections' => $familySections['integration'] ?? [],
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
     * Walks grouped family_questions and produces a map keyed by the
     * group's `section` attribute. Each section maps to a list of
     * sub-sections, each carrying a label, description, and the user's
     * answers paired with their field metadata. Flat (non-grouped)
     * questions land under section 'approach' by default.
     *
     * @return array<string, array<int, array{
     *   id: string,
     *   label: string,
     *   description: string,
     *   fields: array<int, array{id:string, label:string, type:string, value:mixed, has_value:bool}>
     * }>>
     */
    private function buildFamilySections(array $familyQuestions, array $answers): array
    {
        $out = [];
        foreach ($familyQuestions as $entry) {
            $isGroup = ($entry['type'] ?? null) === 'group';
            $section = $entry['section'] ?? 'approach';

            if ($isGroup) {
                $fields = [];
                foreach ($entry['questions'] ?? [] as $q) {
                    $fields[] = $this->prepareFieldEntry($q, $answers);
                }
                $out[$section][] = [
                    'id'          => (string) ($entry['id'] ?? ''),
                    'label'       => (string) ($entry['label'] ?? ''),
                    'description' => (string) ($entry['description'] ?? ''),
                    'fields'      => $fields,
                ];
            } else {
                // Flat question - bucket each individually so old schemas
                // without groups still produce something usable.
                $out[$section][] = [
                    'id'          => (string) ($entry['id'] ?? ''),
                    'label'       => (string) ($entry['label'] ?? ''),
                    'description' => '',
                    'fields'      => [$this->prepareFieldEntry($entry, $answers)],
                ];
            }
        }
        return $out;
    }

    /**
     * Pairs the schema's per-control extra_fields metadata (label, type)
     * with the user's per-control extras values. Returns an ordered list
     * matching the schema's field order so the rendered output reads
     * predictably regardless of how the JSON keys were serialized. Fields
     * not yet answered get has_value=false so renderers can stub them.
     *
     * @return array<int, array{id:string, label:string, type:string, value:mixed, has_value:bool}>
     */
    private function prepareExtrasForRender(array $guidance, array $extras): array
    {
        $fields = $guidance['extra_fields'] ?? [];
        if (empty($fields)) return [];

        $out = [];
        foreach ($fields as $f) {
            $id = (string) ($f['id'] ?? '');
            if ($id === '') continue;
            $value = $extras[$id] ?? null;
            $hasValue = !($value === null || $value === '' || $value === []);
            $out[] = [
                'id'        => $id,
                'label'     => (string) ($f['label'] ?? $id),
                'type'      => (string) ($f['type'] ?? 'text'),
                'value'     => $value,
                'has_value' => $hasValue,
            ];
        }
        return $out;
    }

    private function prepareFieldEntry(array $q, array $answers): array
    {
        $id = (string) ($q['id'] ?? '');
        $value = $answers[$id] ?? null;
        $hasValue = !($value === null || $value === '' || $value === []);
        return [
            'id'        => $id,
            'label'     => (string) ($q['label'] ?? ''),
            'type'      => (string) ($q['type'] ?? 'text'),
            'value'     => $value,
            'has_value' => $hasValue,
        ];
    }

    /**
     * Walks the resolved-control list (which mixes base controls and their
     * enhancements in document order) and attaches each enhancement to its
     * parent base under an `enhancements` key. Returns just the base
     * controls. Renderers display enhancements inline within their parent
     * section, so this avoids duplicate iteration logic in every renderer.
     *
     * Orphan enhancements (no parent in the list, e.g. parent base wasn't
     * in the baseline) get attached to a synthetic parent key 'orphan'
     * which renderers can choose to ignore or to render at the end.
     */
    private function groupEnhancementsUnderParents(array $controls): array
    {
        $bases = [];
        $enhancements = [];
        foreach ($controls as $c) {
            if ($c['is_enhancement']) {
                $enhancements[] = $c;
            } else {
                $c['enhancements'] = [];
                $bases[$c['number']] = $c;
            }
        }
        foreach ($enhancements as $e) {
            $parent = $e['parent'];
            if ($parent !== null && isset($bases[$parent])) {
                $bases[$parent]['enhancements'][] = $e;
            }
            // If the parent base isn't in the baseline, the enhancement is
            // dropped silently. This matches RMF semantics: an enhancement
            // can't be assessed if its base control isn't part of the
            // package - the catalog implicitly requires the base for
            // enhancements to apply.
        }
        return array_values($bases);
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
