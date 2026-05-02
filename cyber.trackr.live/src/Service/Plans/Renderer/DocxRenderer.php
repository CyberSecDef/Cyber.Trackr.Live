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
        if (empty($cm['narrative'])) return;

        $section = $w->addSection();
        $section->addTitle($this->safeHeading('6. ' . ($cm['title'] ?? 'Continuous Monitoring')), 1);
        $this->addPreservedText($section, (string) $cm['narrative']);
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

        $section->addText(count($controls) . ' controls and enhancements applicable to this baseline.', 'Muted');

        foreach ($controls as $i => $c) {
            $heading = '5.' . ($i + 1) . ' ' . ($c['number'] ?? '') . ' — ' . $this->titleCase($c['title'] ?? '');
            if (!empty($c['is_enhancement'])) {
                $heading .= ' (enhancement of ' . ($c['parent'] ?? '') . ')';
            }
            $section->addTitle($this->safeHeading($heading), 2);

            // Status line
            $statusRun = $section->addTextRun(['spaceAfter' => 80]);
            $statusRun->addText('Status: ', ['bold' => true]);
            $statusRun->addText($c['answers']['status'] ?? '[TO BE COMPLETED]', $c['answers']['status'] ? null : 'Todo');

            // Statement
            if (!empty($c['statement'])) {
                $section->addTitle('Control statement', 3);
                $this->addCodeBlock($section, $c['statement']);
            }

            // Implementation block
            $section->addTitle('Implementation', 3);
            $this->addImplementationBlock($section, $c);

            // Meta
            $this->addControlMeta($section, $c);
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
     * use addTextRun with addTextBreak between lines.
     */
    private function addPreservedText(Section $section, string $text): void
    {
        if ($text === '') return;
        $lines = preg_split('/\r?\n/', $text);
        $run = $section->addTextRun();
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
     * (most natural in titles) and strip the other two outright.
     */
    private function safeHeading(string $s): string
    {
        return strtr($s, ['&' => 'and', '<' => '', '>' => '']);
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
