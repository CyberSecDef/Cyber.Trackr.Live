<?php

namespace App\Service\Plans\Renderer;

use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\Element\Table;

/**
 * Renders a Plan structure (from PlanBuilder) to a .docx file via
 * PHPWord. Mirrors the HTML preview structure: cover, doc control,
 * executive summary, intro, system overview, roles, family approach,
 * per-control implementation, glossary, references.
 *
 * Style: neutral Word default - Calibri 11 body, Calibri bold for
 * headings, Consolas for code blocks. No org branding, no custom
 * cover artwork. Future enhancement: configurable cover template.
 */
class DocxRenderer
{
    private const FONT = 'Calibri';
    private const FONT_MONO = 'Consolas';

    /** Builds the PhpWord doc and writes it to the given path or stream. */
    public function save(array $plan, string $target): void
    {
        $phpword = $this->build($plan);
        $writer = IOFactory::createWriter($phpword, 'Word2007');
        $writer->save($target);
    }

    /** Returns the .docx bytes as a string (used for direct response bodies). */
    public function renderToString(array $plan): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'plan_') . '.docx';
        try {
            $this->save($plan, $tmp);
            return (string) file_get_contents($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    private function build(array $plan): PhpWord
    {
        // PHPWord 1.4 does NOT XML-escape body text passed to addText() —
        // a literal `<env>` or `P&L` in user input produces invalid
        // document.xml that unzip accepts but Word refuses. Pre-encoding
        // every string in the plan before it reaches the writer makes
        // entity-encoded text round-trip correctly: PHPWord writes the
        // bytes as-is, and Word decodes the entities on open. Catalog
        // text rarely contains the trio so this is a no-op on it.
        // Headings are still routed through safeHeading() because the
        // TOC bug is an entity-rejecting bug — even `&amp;` breaks it.
        $plan = $this->escapeStringsRecursively($plan);

        $phpword = new PhpWord();

        $phpword->setDefaultFontName(self::FONT);
        $phpword->setDefaultFontSize(11);
        $phpword->setDefaultParagraphStyle([
            'spaceAfter' => 120,
            'lineHeight' => 1.15,
        ]);

        $this->defineStyles($phpword);

        $this->buildCover($phpword, $plan);
        $this->buildDocControl($phpword, $plan);
        $this->buildToc($phpword);
        $this->buildExecutiveSummary($phpword, $plan);
        $this->buildIntroduction($phpword, $plan);
        $this->buildSystemOverview($phpword, $plan);
        $this->buildRoles($phpword, $plan);
        $this->buildFamilyApproach($phpword, $plan);
        $this->buildControls($phpword, $plan);
        $this->buildContinuousMonitoring($phpword, $plan);
        $this->buildIntegration($phpword, $plan);
        $this->buildGlossary($phpword, $plan);
        $this->buildAcronyms($phpword, $plan);
        $this->buildReferences($phpword, $plan);

        return $phpword;
    }

    private function defineStyles(PhpWord $w): void
    {
        $w->addTitleStyle(1, ['bold' => true, 'size' => 18, 'name' => self::FONT], ['spaceBefore' => 360, 'spaceAfter' => 200]);
        $w->addTitleStyle(2, ['bold' => true, 'size' => 14, 'name' => self::FONT], ['spaceBefore' => 280, 'spaceAfter' => 140]);
        $w->addTitleStyle(3, ['bold' => true, 'size' => 12, 'name' => self::FONT, 'italic' => true], ['spaceBefore' => 200, 'spaceAfter' => 100]);

        $w->addParagraphStyle('Cover', ['alignment' => Jc::CENTER, 'spaceAfter' => 200]);
        $w->addFontStyle('CoverTitle', ['name' => self::FONT, 'size' => 36, 'bold' => true]);
        $w->addFontStyle('CoverSystem', ['name' => self::FONT, 'size' => 20, 'italic' => true]);
        $w->addFontStyle('CoverMeta', ['name' => self::FONT, 'size' => 11, 'color' => '555555']);
        $w->addFontStyle('CoverClass', ['name' => self::FONT_MONO, 'size' => 9, 'bold' => true, 'color' => 'FFFFFF']);

        $w->addParagraphStyle('Code', ['spaceAfter' => 120]);
        $w->addFontStyle('CodeFont', ['name' => self::FONT_MONO, 'size' => 10]);
        $w->addFontStyle('Todo', ['name' => self::FONT, 'size' => 10, 'italic' => true, 'color' => '7A1E1E']);
        $w->addFontStyle('Muted', ['name' => self::FONT, 'size' => 10, 'color' => '6F655A']);
        $w->addFontStyle('Ident', ['name' => self::FONT_MONO, 'size' => 10, 'bold' => true]);

        $w->addTableStyle('PlanTable', [
            'borderColor' => 'cccccc',
            'borderSize'  => 6,
            'cellMargin'  => 80,
        ], [
            'bgColor' => 'f5f5f5',
            'bold'    => true,
        ]);
    }

    /* ---- builders for each section ---- */

    private function buildCover(PhpWord $w, array $plan): void
    {
        $section = $w->addSection();
        $cover = $plan['front_matter']['cover'] ?? [];

        $section->addTextBreak(6);

        // Classification stamp
        $stamp = $cover['classification'] ?? 'Unclassified';
        $cell = $section->addTextRun(['alignment' => Jc::CENTER, 'spaceAfter' => 400]);
        $cell->addText('  ' . strtoupper($stamp) . '  ', 'CoverClass', ['shading' => ['fill' => '7A1E1E']]);

        $section->addText($cover['title'] ?? 'Plan', 'CoverTitle', 'Cover');
        $section->addText($cover['system_name'] ?? '[SYSTEM NAME]', 'CoverSystem', 'Cover');

        $section->addTextBreak(2);

        $version = $cover['version']           ?? '1.0';
        $date    = $cover['publication_date']  ?? '';
        $section->addText('Version ' . $version, 'CoverMeta', 'Cover');
        if ($date !== '') {
            $section->addText($date, 'CoverMeta', 'Cover');
        }
        if (!empty($plan['meta']['baseline'])) {
            $section->addText('Baseline: ' . $plan['meta']['baseline'], 'CoverMeta', 'Cover');
        }

        $section->addPageBreak();
    }

    private function buildDocControl(PhpWord $w, array $plan): void
    {
        $section = $w->addSection();
        $section->addTitle('Document Control', 1);

        $rows = $plan['front_matter']['doc_control'] ?? [];
        $table = $section->addTable('PlanTable');
        $table->addRow();
        foreach (['Version', 'Date', 'Author', 'Changes'] as $h) {
            $table->addCell(2500, ['bgColor' => 'f5f5f5'])->addText($h, ['bold' => true]);
        }
        foreach ($rows as $r) {
            $table->addRow();
            $table->addCell(2500)->addText((string) ($r['version'] ?? ''));
            $table->addCell(2500)->addText((string) ($r['date']    ?? ''));
            $table->addCell(2500)->addText((string) ($r['author']  ?? ''));
            $table->addCell(3500)->addText((string) ($r['changes'] ?? ''));
        }
    }

    private function buildToc(PhpWord $w): void
    {
        $section = $w->addSection();
        $section->addTitle('Table of Contents', 1);
        $section->addText('Right-click the table below in Word and choose "Update Field" to refresh.', 'Muted');
        $section->addTextBreak(1);
        // PHPWord defaults: dot tab-leader, depth 1-9. Constrain to depth
        // 1-3 so per-control sub-headings (depth 2) appear but the deeply
        // nested control-statement headings (depth 3) are still included
        // while leaving room above.
        $section->addTOC(null, ['name' => self::FONT, 'size' => 11], 1, 3);
        $section->addPageBreak();
    }

    private function buildExecutiveSummary(PhpWord $w, array $plan): void
    {
        $section = $w->addSection();
        $section->addTitle('Executive Summary', 1);
        $section->addText((string) ($plan['front_matter']['executive_summary'] ?? ''));
    }

    private function buildContinuousMonitoring(PhpWord $w, array $plan): void
    {
        $cm = $plan['body']['continuous_monitoring'] ?? [];
        if (empty($cm['narrative']) && empty($cm['sub_sections'])) return;

        $section = $w->addSection();
        $section->addTitle($this->safeHeading('6. ' . ($cm['title'] ?? 'Continuous Monitoring')), 1);
        if (!empty($cm['narrative'])) {
            $this->addPreservedText($section, (string) $cm['narrative']);
        }
        foreach ($cm['sub_sections'] ?? [] as $i => $sub) {
            $this->renderFamilySubSection($section, $sub, '6.' . ($i + 1));
        }
    }

    private function buildIntegration(PhpWord $w, array $plan): void
    {
        $integ = $plan['body']['integration'] ?? [];
        if (empty($integ['narrative']) && empty($integ['sub_sections'])) return;

        $section = $w->addSection();
        $section->addTitle($this->safeHeading('7. ' . ($integ['title'] ?? 'Integration with Other RMF Artifacts')), 1);
        if (!empty($integ['narrative'])) {
            $this->addPreservedText($section, (string) $integ['narrative']);
        }
        foreach ($integ['sub_sections'] ?? [] as $i => $sub) {
            $this->renderFamilySubSection($section, $sub, '7.' . ($i + 1));
        }
    }

    /**
     * Renders one family sub-section (group of structured fields) as an
     * H3 followed by a 2-column table of {field label → user value}.
     * Empty fields render with the Todo style.
     */
    private function renderFamilySubSection(Section $section, array $sub, string $sectionNum): void
    {
        $heading = $sectionNum . ' ' . ($sub['label'] ?? '');
        $section->addTitle($this->safeHeading($heading), 3);

        if (!empty($sub['description'])) {
            $section->addText((string) $sub['description'], 'Muted');
        }

        if (empty($sub['fields'])) return;

        $table = $section->addTable('PlanTable');
        foreach ($sub['fields'] as $f) {
            $table->addRow();
            $labelCell = $table->addCell(2800, ['bgColor' => 'f5f5f5']);
            $labelCell->addText((string) ($f['label'] ?? ''), ['bold' => true, 'size' => 9]);
            $valueCell = $table->addCell(8000);
            $val = $f['value'] ?? null;
            $hasValue = !empty($f['has_value']);
            if (!$hasValue) {
                $valueCell->addText('[TO BE COMPLETED]', 'Todo');
            } elseif (is_array($val)) {
                foreach ($val as $item) {
                    $valueCell->addListItem((string) $item);
                }
            } else {
                $this->addPreservedText($valueCell, (string) $val);
            }
        }
    }

    private function buildAcronyms(PhpWord $w, array $plan): void
    {
        $entries = $plan['back_matter']['acronyms'] ?? [];
        if (empty($entries)) return;

        $section = $w->addSection();
        $section->addTitle('Appendix B — Acronyms', 1);

        $table = $section->addTable('PlanTable');
        $table->addRow();
        $table->addCell(2200, ['bgColor' => 'f5f5f5'])->addText('Acronym',  ['bold' => true]);
        $table->addCell(8000, ['bgColor' => 'f5f5f5'])->addText('Expansion', ['bold' => true]);
        foreach ($entries as $a) {
            $table->addRow();
            $table->addCell(2200)->addText((string) ($a['abbr']      ?? ''), 'Ident');
            $table->addCell(8000)->addText((string) ($a['expansion'] ?? ''));
        }
    }

    private function buildIntroduction(PhpWord $w, array $plan): void
    {
        $section = $w->addSection();
        $intro = $plan['body']['introduction'] ?? [];
        $section->addTitle('1. Introduction', 1);

        $section->addTitle('1.1 Purpose', 2);
        $this->addPreservedText($section, (string) ($intro['purpose'] ?? ''));

        $section->addTitle('1.2 Scope', 2);
        $section->addText((string) ($intro['scope'] ?? ''));

        $section->addTitle('1.3 Audience', 2);
        $section->addText((string) ($intro['audience'] ?? ''));

        $section->addTitle('1.4 References', 2);
        $this->addLinkList($section, $intro['references'] ?? []);

        // Phase 2B: §1.5 Common Control Inheritance reminder
        $reminder = $plan['body']['inheritance_reminder'] ?? [];
        if (!empty($reminder['commonly_inheritable'])) {
            $section->addTitle('1.5 Common Control Inheritance', 2);
            $section->addText(
                'Some controls in the ' . ($reminder['family_name'] ?? '') . ' family are typically inherited from a common control provider rather than implemented at the system level. Before defaulting to "Implemented" for any control in section 5, verify whether your organization has an authoritative inheritance source. Commonly inheritable controls in this family:'
            );
            foreach ($reminder['commonly_inheritable'] as $entry) {
                $run = $section->addTextRun(['spaceAfter' => 60]);
                $run->addText('• ' . ($entry['number'] ?? '') . ' — typically inherited from ', null);
                $run->addText((string) ($entry['from'] ?? ''), ['italic' => true]);
            }
        }
    }

    private function buildSystemOverview(PhpWord $w, array $plan): void
    {
        $section = $w->addSection();
        $sys = $plan['body']['system_overview'] ?? [];
        $section->addTitle('2. System Overview', 1);

        $section->addTitle('2.1 Description', 2);
        $this->addPreservedText($section, (string) ($sys['description'] ?? ''));

        if (!empty($sys['environment']) || !empty($sys['classification'])) {
            // No ampersand in headings: PHPWord 1.4's TOC rendering copies
            // heading text into <w:hyperlink>...<w:t>...</w:t></w:hyperlink>
            // without XML-escaping, so a literal "&" produces an invalid
            // document.xml that unzip accepts but Word refuses.
            $section->addTitle($this->safeHeading('2.2 Environment and Classification'), 2);
            $line = '';
            if (!empty($sys['environment']))    $line .= 'Environment: ' . $sys['environment'] . '. ';
            if (!empty($sys['classification'])) $line .= 'Classification: ' . $sys['classification'] . '.';
            $section->addText(trim($line));
        }

        // Phase 2B: family-defined sub-sections under System Overview (e.g. CI methodology)
        $idx = 3;
        foreach ($sys['sub_sections'] ?? [] as $sub) {
            $this->renderFamilySubSection($section, $sub, '2.' . $idx);
            $idx++;
        }
    }

    private function buildRoles(PhpWord $w, array $plan): void
    {
        $section = $w->addSection();
        $section->addTitle('3. Roles and Responsibilities', 1);

        $table = $section->addTable('PlanTable');
        $table->addRow();
        $table->addCell(3500, ['bgColor' => 'f5f5f5'])->addText('Role',     ['bold' => true]);
        $table->addCell(7500, ['bgColor' => 'f5f5f5'])->addText('Assignee', ['bold' => true]);
        foreach ($plan['body']['roles'] ?? [] as $r) {
            $table->addRow();
            $table->addCell(3500)->addText((string) ($r['role'] ?? ''));
            $table->addCell(7500)->addText((string) ($r['name'] ?? ''));
        }
    }

    private function buildFamilyApproach(PhpWord $w, array $plan): void
    {
        $section = $w->addSection();
        $approach = $plan['body']['family_approach'] ?? [];
        $section->addTitle($this->safeHeading('4. ' . ($approach['title'] ?? 'Approach')), 1);
        $this->addPreservedText($section, (string) ($approach['narrative'] ?? ''));

        // Phase 2B: structured sub-sections (CCB, audit strategy,
        // exception management, hardening sources, doc control, etc.)
        foreach ($approach['sub_sections'] ?? [] as $i => $sub) {
            $this->renderFamilySubSection($section, $sub, '4.' . ($i + 1));
        }
    }

    private function buildControls(PhpWord $w, array $plan): void
    {
        $controls = $plan['body']['controls'] ?? [];
        $section = $w->addSection();
        $section->addTitle('5. Control Implementation', 1);

        if (empty($controls)) {
            $section->addText('No controls match the selected baseline.', 'Muted');
            return;
        }

        $totalControls = count($controls);
        $totalEnhancements = 0;
        foreach ($controls as $c) {
            $totalEnhancements += count($c['enhancements'] ?? []);
        }
        $section->addText(
            $totalControls . ' base controls and ' . $totalEnhancements . ' enhancements documented in this section.',
            'Muted'
        );

        foreach ($controls as $i => $c) {
            $this->renderOneControl($section, $c, '5.' . ($i + 1), false);

            // Enhancements inline under their parent base control
            foreach ($c['enhancements'] ?? [] as $j => $e) {
                $this->renderOneControl($section, $e, '5.' . ($i + 1) . '.' . ($j + 1), true);
            }
        }
    }

    /**
     * Renders one control (base or enhancement) into the given section.
     * Enhancements with disposition "Tailored Out" or "Inherited" get a
     * compact form (rationale or inheritance details only); Selected
     * enhancements and base controls render the full form.
     */
    private function renderOneControl(Section $section, array $c, string $sectionNum, bool $isEnhancement): void
    {
        $heading = $sectionNum . ' ' . ($c['number'] ?? '') . ' — ' . $this->titleCase($c['title'] ?? '');
        if ($isEnhancement) {
            $heading .= ' (enhancement)';
        }
        $section->addTitle($this->safeHeading($heading), 2);

        // Status / disposition line
        $statusRun = $section->addTextRun(['spaceAfter' => 80]);
        if ($isEnhancement) {
            $disp = $c['disposition'] ?: 'Selected';
            $statusRun->addText('Disposition: ', ['bold' => true]);
            $statusRun->addText($disp);
            if ($disp === 'Selected' && !empty($c['answers']['status'])) {
                $statusRun->addText('   Status: ', ['bold' => true]);
                $statusRun->addText($c['answers']['status']);
            }
        } else {
            $statusRun->addText('Status: ', ['bold' => true]);
            $statusRun->addText($c['answers']['status'] ?? '[TO BE COMPLETED]', $c['answers']['status'] ? null : 'Todo');
        }

        // Statement (with ODVs substituted + bolded)
        if (!empty($c['statement_segments'])) {
            $section->addTitle('Control statement', 3);
            $this->addStatementSegments($section, $c['statement_segments']);
        }

        // Tailored Out enhancements: rationale only
        if ($isEnhancement && ($c['disposition'] ?? null) === 'Tailored Out') {
            $section->addTitle('Tailoring rationale', 3);
            $rationale = $c['disposition_rationale'] ?: '[TO BE COMPLETED: tailoring rationale]';
            $section->addText($rationale, $c['disposition_rationale'] ? null : 'Todo');
            return;
        }

        // Inherited disposition: surface the inheritance details directly
        if ($isEnhancement && ($c['disposition'] ?? null) === 'Inherited') {
            $section->addTitle('Inheritance', 3);
            $section->addText('Inherited from: ' . ($c['answers']['inheritance_provider'] ?: '[TO BE COMPLETED]'), ['bold' => true]);
            if (!empty($c['answers']['inheritance_details'])) {
                $this->addPreservedText($section, $c['answers']['inheritance_details']);
            }
            $this->addControlMeta($section, $c);
            return;
        }

        // Selected enhancement or base control: full implementation block
        $section->addTitle('Implementation', 3);
        $this->addImplementationBlock($section, $c);
        $this->addControlMeta($section, $c);
    }

    /**
     * Adds a control statement preserving line breaks AND bolding any
     * ODV-substituted segments. Leans on the segmented form produced
     * by OdvExtractor::tokenize() in PlanBuilder.
     */
    private function addStatementSegments(Section $section, array $segments): void
    {
        if (empty($segments)) return;

        $run = $section->addTextRun(['spaceAfter' => 200, 'shading' => ['fill' => 'f5f5f5']]);
        foreach ($segments as $seg) {
            if (($seg['type'] ?? '') === 'odv') {
                $style = !empty($seg['filled']) ? ['name' => self::FONT_MONO, 'size' => 10, 'bold' => true]
                                                : ['name' => self::FONT_MONO, 'size' => 10, 'bold' => true, 'color' => '7A1E1E', 'italic' => true];
                $this->addRunWithBreaks($run, $seg['value'], $style);
            } else {
                $this->addRunWithBreaks($run, $seg['value'] ?? '', 'CodeFont');
            }
        }
    }

    /**
     * PHPWord text runs don't honor \n — split and emit textBreaks.
     * Style passed through to addText.
     *
     * @param string|array $style
     */
    private function addRunWithBreaks($run, string $text, $style): void
    {
        if ($text === '') return;
        $lines = preg_split('/\r?\n/', $text);
        $first = true;
        foreach ($lines as $line) {
            if (!$first) $run->addTextBreak();
            $run->addText($line, $style);
            $first = false;
        }
    }

    private function addImplementationBlock(Section $section, array $c): void
    {
        $status = $c['answers']['status'] ?? null;
        $a = $c['answers'] ?? [];

        if ($status === 'Inherited') {
            $section->addText('Inherited from: ' . ($a['inheritance_provider'] ?: '[TO BE COMPLETED]'), ['bold' => true]);
            if (!empty($a['inheritance_details'])) {
                $this->addPreservedText($section, $a['inheritance_details']);
            }
            return;
        }
        if ($status === 'Not Applicable') {
            $rationale = $a['na_rationale'] ?: '[TO BE COMPLETED: rationale]';
            $section->addText('Not applicable. ' . $rationale, $a['na_rationale'] ? null : 'Todo');
            return;
        }
        if (in_array($status, ['Implemented', 'Partially Implemented'], true)) {
            $narrative = $a['narrative'] ?: '[TO BE COMPLETED]';
            if ($a['narrative']) {
                $this->addPreservedText($section, $narrative);
            } else {
                $section->addText($narrative, 'Todo');
            }
            return;
        }
        $section->addText('[TO BE COMPLETED]', 'Todo');
    }

    private function addControlMeta(Section $section, array $c): void
    {
        $a = $c['answers'] ?? [];

        $table = $section->addTable('PlanTable');

        $row = function () use (&$table, $section) {
            $table->addRow();
            return $table;
        };

        $addRow = function (string $label, string $value, ?string $valueStyle = null) use ($table) {
            $table->addRow();
            $table->addCell(2800, ['bgColor' => 'f5f5f5'])->addText($label, ['bold' => true, 'size' => 9]);
            $cell = $table->addCell(8000);
            $cell->addText($value, $valueStyle);
        };

        $role = $a['responsible_role'] ?: '[TO BE COMPLETED]';
        $addRow('Responsible role', $role, $a['responsible_role'] ? null : 'Todo');

        if (!empty($a['evidence'])) {
            $table->addRow();
            $table->addCell(2800, ['bgColor' => 'f5f5f5'])->addText('Evidence / artifacts', ['bold' => true, 'size' => 9]);
            $cell = $table->addCell(8000);
            foreach ($a['evidence'] as $e) {
                $cell->addListItem((string) $e);
            }
        } else {
            $addRow('Evidence / artifacts', '[TO BE COMPLETED]', 'Todo');
        }

        if (!empty($c['related'])) {
            $addRow('Related controls', implode(', ', $c['related']));
        }

        if (!empty($c['ccis'])) {
            $ids = array_map(fn($x) => $x['id'] ?? '', $c['ccis']);
            $addRow('Linked CCIs (' . count($ids) . ')', implode(', ', $ids));
        }

        // Per-control "extras" populated by Phase 2C schema (CM-7 allowed
        // services, CM-8 inventory attributes, etc.). Iterates the schema-
        // ordered extras_rendered list so labels come from the schema rather
        // than humanized field IDs, and unfilled fields render as [TO BE
        // COMPLETED] so the assessor sees the gap.
        if (!empty($c['extras_rendered']) && is_array($c['extras_rendered'])) {
            foreach ($c['extras_rendered'] as $f) {
                $label = (string) ($f['label'] ?? '');
                if (empty($f['has_value'])) {
                    $addRow($label, '[TO BE COMPLETED]', 'Todo');
                    continue;
                }
                $val = $f['value'];
                if (is_array($val)) {
                    $table->addRow();
                    $table->addCell(2800, ['bgColor' => 'f5f5f5'])->addText($label, ['bold' => true, 'size' => 9]);
                    $cell = $table->addCell(8000);
                    foreach ($val as $item) {
                        $cell->addListItem((string) $item);
                    }
                } else {
                    $addRow($label, (string) $val);
                }
            }
        }
    }

    private function buildGlossary(PhpWord $w, array $plan): void
    {
        $entries = $plan['back_matter']['glossary'] ?? [];
        if (empty($entries)) return;

        $section = $w->addSection();
        $section->addTitle('Appendix A — Glossary', 1);
        foreach ($entries as $g) {
            $run = $section->addTextRun(['spaceAfter' => 120]);
            $run->addText(($g['term'] ?? '') . '. ', ['bold' => true]);
            $run->addText((string) ($g['definition'] ?? ''));
        }
    }

    private function buildReferences(PhpWord $w, array $plan): void
    {
        $refs = $plan['back_matter']['references'] ?? [];
        if (empty($refs)) return;

        $section = $w->addSection();
        $section->addTitle('Appendix C — References', 1);
        $this->addLinkList($section, $refs);
    }

    /* ---- helpers ---- */

    /**
     * Adds text preserving line breaks. PHPWord's addText doesn't honor \n;
     * use addTextRun with addTextBreak between lines. Container can be any
     * PHPWord element exposing addTextRun() — Section, Cell, TextRun, etc.
     */
    private function addPreservedText($container, string $text): void
    {
        if ($text === '') return;
        $lines = preg_split('/\r?\n/', $text);
        $run = $container->addTextRun();
        $first = true;
        foreach ($lines as $line) {
            if (!$first) $run->addTextBreak();
            $run->addText($line);
            $first = false;
        }
    }

    private function addCodeBlock(Section $section, string $text): void
    {
        if ($text === '') return;
        $lines = preg_split('/\r?\n/', $text);
        $run = $section->addTextRun([
            'spaceAfter' => 200,
            'shading'    => ['fill' => 'f5f5f5'],
        ]);
        $first = true;
        foreach ($lines as $line) {
            if (!$first) $run->addTextBreak();
            $run->addText($line, 'CodeFont');
            $first = false;
        }
    }

    /**
     * Strips characters that PHPWord 1.4's TOC field rendering fails to
     * XML-escape when it copies heading text into <w:hyperlink><w:t>.
     * The canonical XML-illegal trio is &, <, >; we replace & with "and"
     * (most natural in titles) and strip the other two outright. Headings
     * can't use entity encoding because the TOC bug rejects even `&amp;`.
     */
    private function safeHeading(string $s): string
    {
        // Decode any entity-encoded chars that came from
        // escapeStringsRecursively() so the heading sanitiser sees the
        // raw chars, then strip / replace them.
        $s = html_entity_decode($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        return strtr($s, ['&' => 'and', '<' => '', '>' => '']);
    }

    /**
     * Walks the plan structure and XML-encodes every string value with
     * htmlspecialchars(ENT_XML1). PHPWord 1.4's writer treats text as
     * already-escaped and emits it verbatim into <w:t>; pre-encoding is
     * the simplest way to dodge that without monkey-patching the writer.
     */
    private function escapeStringsRecursively($value)
    {
        if (is_string($value)) {
            return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = $this->escapeStringsRecursively($v);
            }
            return $out;
        }
        return $value;
    }

    /**
     * Title Case but with editorial-style lowercase conjunctions /
     * articles / short prepositions. The 800-53 r5 catalog stores titles
     * in ALL CAPS ("POLICY AND PROCEDURES"), so we want "Policy and
     * Procedures" not "Policy And Procedures".
     */
    private function titleCase(string $s): string
    {
        $s = ucwords(strtolower($s));
        $small = ['And','Or','Of','For','To','In','On','At','By','The','A','An','As','With','From'];
        return preg_replace_callback(
            '/\b(' . implode('|', $small) . ')\b/',
            fn($m) => strtolower($m[1]),
            $s
        );
    }

    /**
     * Renders a list of {label, url} entries as a bulleted set of
     * hyperlinks. PHPWord's addListItem() returns a Text element which
     * lacks addLink(), so we hand-roll a bullet glyph in a TextRun and
     * call addLink() on the run instead.
     */
    private function addLinkList(Section $section, array $items): void
    {
        foreach ($items as $r) {
            $run = $section->addTextRun(['spaceAfter' => 80]);
            $run->addText('• ');
            $run->addLink($r['url'] ?? '#', $r['label'] ?? $r['url'] ?? '', ['color' => '7A1E1E', 'underline' => 'single']);
        }
    }
}
