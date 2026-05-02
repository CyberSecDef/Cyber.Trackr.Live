# Cyber Trackr — TODO

## Auto Plan Generation (CM-first, schema-driven)

A wizard-based generator that produces NIST RMF family plans (Configuration
Management Plan, Access Control Plan, etc.) from a structured questionnaire.
Template-based, no LLM. Schema-driven so adding new families is a JSON file,
not a code change. Output is HTML preview + downloadable `.docx`.

---

### → Pick up here next session

**Current state**: Phase 1 is COMPLETE for the CM family but is now
considered the **structural floor** for the engine — it produces a
syntactically correct plan but not yet an assessable one. Phase 2 is
the assessability pass: it makes the plan *measurable, parameterized,
and evidence-backed* per RMF best practice. The Phase 2 engine work
becomes the **shared baseline** all future family schemas rely on.

Code from Phase 1 is local and uncommitted (we agreed to commit once
after 1D).
End-to-end verified: `/plans` index → `/plans/cm` wizard → baseline picker
loads per-control cards → preview HTML opens in new tab → download
generates a clean `.docx` that opens in Word.

**Where to start when resuming**:

1. **Commit the whole Phase 1 series** as one feature commit (we held all
   diffs from 1A through 1D plus the docx-corruption fix specifically so
   we could land them as one coherent feature). Files involved:
   - `composer.json` / `composer.lock` (added phpoffice/phpword)
   - `resources/data/plans/_shared.json` + `cm.json` (new)
   - `src/Service/Plans/{PlanRegistry,ControlResolver,PlanBuilder}.php` (new)
   - `src/Service/Plans/Renderer/{HtmlRenderer,DocxRenderer}.php` (new)
   - `src/Controller/PlansController.php` (new)
   - `src/Twig/AppExtension.php` (added `editorial_title` filter)
   - `templates/plans/{index,wizard,preview,_control_cards}.html.twig` (new)
   - `templates/base.html.twig` (added Plans nav link)
   - `public/js/plans.js` (new)
   - `public/css/app.css` (~400 lines of plan-related styling)
   - `TODO.md` (this file, the planning doc)

2. **Begin Phase 2 — Assessability** (see full spec below). Driven by
   real RMF practitioner feedback on the Phase 1 CM Plan: the plan was
   structurally sound but lacked enforceable, measurable,
   organization-defined values; control-enhancement traceability;
   per-control technical enforcement detail; and several CM-specific
   structured sections (CCB definition, CI methodology, audit strategy,
   exception management, drift automation, metrics, etc.). Phase 2
   addresses the engine + schema gaps so the resulting plans are
   *assessable*, not just structurally compliant.

3. **Future families come AFTER Phase 2.** Phase 2 sets the new floor
   for what every family schema should provide. Adding AC, AU, IR, etc.
   on top of the Phase 2 engine is still schema-only work (no engine
   changes), but the schema templates are richer.

### Lesson learned during 1D — PHPWord TOC bug

PHPWord 1.4's TOC field rendering copies heading text into
`<w:hyperlink><w:t>…</w:t></w:hyperlink>` **without** XML-escaping, so a
literal `&` in any heading produces a `document.xml` that `unzip`
accepts but Word refuses to open. The fix is `DocxRenderer::safeHeading()`,
which strips/replaces `& < >` from anything passed to `addTitle()`. All
dynamic heading paths route through it (4. {family approach title},
5.X CM-N — {control title}, 6. {continuous monitoring title}). When
authoring future family schemas, **keep `&`, `<`, `>` out of any field
that lands in a heading** (intro_template body text is fine — only
title strings are at risk).

---

### Decisions locked

- **CM family-specific questions** (5): CM tool / repo of record, CCB chair,
  CCB cadence, baseline storage approach, change-request workflow.
- **DOCX styling**: single neutral template for v1 (no configurable cover
  color / logo upload — that's a future enhancement).
- **Nav placement**: new top-level "Plans" slot in the primary nav, between
  "Scans to Reports" and "API."
- **DOCX library**: PHPWord (acceptable to add the ~5 MB to `vendor/`).
- **No LLM**: pure templating + user-authored prose.
- **No DB**: persistence via BYOJSON (download / upload draft files,
  autosave to `localStorage` during a session).
- **Status taxonomy**: Implemented / Partially Implemented / Not Implemented
  / Not Applicable / Inherited (with provider + details fields).
- **Skipping is allowed**: missing controls render as `[TO BE COMPLETED]`
  rather than blocking the generate action.
- **Wizard layout**: single-page accordion, not a stepper. RMF folks answer
  non-linearly.
- **Cross-references**: each control's "See also" pulls from `<related>` in
  the 800-53 r5 catalog, rendered with the existing `ui.rmf_link` macro.

### Architecture summary

- **Schemas**: `resources/data/plans/_shared.json` (system metadata + per-
  control questions, used by every family) + `resources/data/plans/<fam>.json`
  per family (intro prose template, family-specific questions).
- **Services** under `App\Service\Plans\`:
  - `PlanRegistry` — discovers schema files, exposes `availablePlans()`.
  - `ControlResolver` — given `family + baseline`, returns applicable
    controls from the 800-53 r5 catalog filtered by OSCAL profile.
  - `PlanBuilder` — schema + answers → `Plan` value object.
  - `Renderer\HtmlRenderer` — `Plan` → HTML.
  - `Renderer\DocxRenderer` — `Plan` → `.docx` via PHPWord.
- **Controller**: `App\Controller\PlansController` with five routes
  (`/plans` index, `/plans/{family}` wizard, `/plans/{family}/preview`,
  `/plans/{family}/download`, `/plans/{family}/schema.json`).
- **Templates**: `templates/plans/{index,wizard,preview}.html.twig`.
- **CSS**: new `.plan-wizard` and `.plan-preview` blocks in `app.css`.

### Document structure (every plan, family-specific where noted)

**Front matter**
- Cover page (title, system name, version, date, classification stamp)
- Document control / revision history table
- Table of contents
- Executive summary (auto-generated from system metadata)

**Body**
1. Introduction (purpose, scope, audience, references) — family boilerplate
2. System Overview — from system metadata
3. Roles and Responsibilities — table from owner/ISSO/ISSM
4. **{Family} Approach** — narrative built from family-specific answers
5. Control Implementation — one subsection per applicable control:
   - Control text from 800-53 r5
   - Implementation status
   - Implementation narrative (or inheritance/N-A details)
   - Responsible role
   - Evidence / artifacts list
   - Linked CCIs (deep-linked to /cci#CCI-…)
   - Related controls (deep-linked to /rmf/5#rmf_…)
6. Continuous Monitoring — family boilerplate

**Back matter**
- Appendix A: Glossary (auto, terms from this family's controls)
- Appendix B: Acronyms (auto)
- Appendix C: References (NIST 800-53 r5, family SP, CNSSI 1253 if applicable)

### BYOJSON shape

```json
{
  "schema_version": 1,
  "family": "CM",
  "saved_at": "2026-05-01T19:42:00Z",
  "system": { "name": "...", "owner": "...", ... },
  "baseline": "fedramp-moderate",
  "family_answers": { "ccb_chair": "...", "ccb_cadence": "...", ... },
  "controls": {
    "CM-1": { "status": "Implemented", "narrative": "...", "evidence": ["..."], ... },
    "CM-2": { "status": "Inherited", "provider": "...", "details": "...", ... }
  },
  "document": { "version": "1.0", "author": "...", "publication_date": "..." }
}
```

`schema_version` allows future migration of old drafts when the shape evolves.

---

### Phase 1A — Foundation (~8–12 h) — COMPLETE

- [x] Add `App\Service\Plans\PlanRegistry` (lists schema files, exposes
      family code → schema metadata)
- [x] Add `App\Service\Plans\ControlResolver` (family + baseline →
      ordered list of applicable controls from 800-53 r5 + OSCAL profiles)
- [x] Author `resources/data/plans/_shared.json` (system metadata
      questions, per-control questions including inheritance fields)
- [x] Author `resources/data/plans/cm.json` (CM intro prose template +
      five family questions: CM tool/repo, CCB chair, CCB cadence,
      baseline storage, change-request workflow)
- [x] Add `App\Controller\PlansController` with `/plans` index route
      that lists plans from `PlanRegistry`
- [x] Add `/plans/{family}` wizard route — renders the wizard for
      system metadata + baseline + family questions only (no per-control
      yet)
- [x] Add `/plans/{family}/schema.json` route exposing the schema to
      wizard JS for show_when logic and BYOJSON validation
- [x] Add `templates/plans/index.html.twig` — family picker, matches
      site editorial style
- [x] Add `templates/plans/wizard.html.twig` (5 accordion sections;
      sections 1/2/3/5 fully wired, section 4 placeholder for 1B)
- [x] Add basic `.plan-wizard` CSS (accordion, form fields, save/load
      buttons, conditional show_when)
- [x] Add `public/js/plans.js` — autosave to localStorage, BYOJSON
      save/load, show_when conditional rendering, evidence list field,
      preview POST
- [x] Add "Plans" nav link to `templates/base.html.twig` between
      "Scans to Reports" and "API"
- [x] Add `/plans/cm/preview` POST route that renders cover/intro/
      system-overview/family-approach (proof the schema + builder +
      renderer pipeline works end-to-end before adding per-control)
- [x] Add `App\Service\Plans\PlanBuilder` (skeleton — handles
      front-matter + intro + system-overview + family approach +
      lists applicable controls; per-control implementation is 1B)
- [x] Add `App\Service\Plans\Renderer\HtmlRenderer` and the
      `templates/plans/preview.html.twig` shell

End-to-end smoke test passed: `/plans` index lists CM, `/plans/cm`
wizard renders with all sections and overlays populated, POST to
`/plans/cm/preview` with sample answers returns a rendered HTML
preview with cover, intro, family approach narrative, and the list
of 12 applicable controls for the nist-moderate baseline.

### Phase 1B — Per-control implementation (~12–15 h) — COMPLETE

- [x] Add `/plans/{family}/controls/{baseline}` route returning an
      HTML fragment of per-control cards (lazy-loaded by JS when the
      baseline changes, so initial wizard load stays light)
- [x] `templates/plans/_control_cards.html.twig` fragment: per-card
      accordion, sidebar with control statement / discussion /
      related / linked CCIs (read-only), form fields with status
      dropdown, conditional narrative/provider/details/rationale,
      role, evidence list
- [x] Class-based conditional rendering: `.plan-control` parent gets
      `is-implemented` / `is-partial` / `is-not-implemented` /
      `is-na` / `is-inherited` based on status; conditional fields
      visible only when the parent has the right class. Scoped per
      card so multiple status dropdowns don't fight each other
- [x] Status badge in summary header reflects current status with
      color-coded variant (green/yellow/red/accent)
- [x] Evidence/artifacts list field works in per-control cards via
      shared `planWizard.listAdd` (added in Phase 1A)
- [x] Extend `PlanBuilder` to merge user-supplied per-control answers
      onto resolved catalog entries, with `normalizeControlAnswers`
      producing a predictable shape so the renderer always sees the
      same fields
- [x] Update `templates/plans/preview.html.twig` section 5 to render
      per-control implementation: control statement, status badge,
      implementation narrative (or inheritance / N-A details),
      responsible role, evidence list, related controls, linked CCIs
      with deep-links via `ui.rmf_link` and `ui.cci_link`
- [x] Skip handling: empty answers render `[TO BE COMPLETED]` in
      colored italic monospace, not blocking generation
- [x] Inherited rendering: "Inherited from {provider}. {details}"
      block replaces the implementation narrative
- [x] `plans.js` extended with: lazy fetch of per-control fragment
      on baseline change, hydration of per-control fields from
      `state.controls` into newly rendered cards, status-class
      toggling, badge refresh, autosave continuing to capture
      per-control answers via `data-control-num` attributes

### Phase 1C — DOCX output + BYOJSON persistence (~8–12 h) — COMPLETE

- [x] `composer require phpoffice/phpword` (1.4.0; ~3.1 MB in vendor/)
- [x] Add `App\Service\Plans\Renderer\DocxRenderer` consuming the same
      `Plan` array shape as the HTML renderer
- [x] Single neutral DOCX style: Calibri 11 body, bold headings at
      18/14/12pt, Consolas for code/statement blocks, tables with
      light gray header rows, accent-colored hyperlinks, italic
      "[TO BE COMPLETED]" placeholders for missing answers
- [x] Cover page section with classification stamp banner, big serif
      title, system name in italic, version + date + baseline meta
- [x] Document control / revision history table from document
      metadata (single row for v1, schema supports multiple)
- [x] Executive summary auto-generated from system metadata
- [x] Body sections: Introduction (purpose / scope / audience /
      references with hyperlinks), System Overview, Roles table,
      Family Approach narrative
- [x] Per-control implementation: heading, status, control
      statement (preformatted code block), implementation block
      (narrative / inheritance / N-A based on status), meta table
      with role, evidence list, related controls, linked CCIs
- [x] Back matter: glossary (one paragraph per term, term in bold),
      references with hyperlinks
- [x] Add `/plans/{family}/download` POST route returning the docx
      bytes with proper `application/vnd.openxmlformats-...` MIME and
      a sensible filename derived from system_acronym (or system_name)
- [x] Wizard "Download Word" button alongside Preview, with full
      JS support: collects current state, POSTs JSON, reads
      Content-Disposition for filename, triggers browser download
- [x] BYOJSON Save progress / Load draft / autosave already wired
      in Phase 1A; verified to round-trip through the new download

Smoke test: a CM Plan with 3 controls of mixed status (Implemented /
Inherited / N-A) generates a 14 KB `.docx` recognized by `file` as
"Microsoft Word 2007+", containing all expected content (system
name, family-approach narrative, status badges, inheritance details,
N-A rationale, control statements).

Deferred items (out of scope for v1):
- Auto-generated TOC. `addTOC()` works but requires Word to
  refresh fields manually on open. Cleaner to skip for now.
- Acronyms appendix. Schema doesn't carry acronyms yet; derive
  in 1D once we author them per family.

### Phase 1D — Validation + polish — COMPLETE (self-review pass)

Items I could tackle without an outside tester. Real practitioner
validation is its own follow-up — the engine is now ready for that
pass and any iteration will be on prose templates / question wording,
not the rendering pipeline.

- [x] CM glossary expanded from 4 to 14 entries covering the full
      vocabulary a CM Plan typically uses (Authorization Boundary,
      Change Request, Configuration Audit, Configuration Identification,
      Configuration Status Accounting, Drift, Functional Baseline,
      Production Baseline, Security-Impact Analysis, etc.)
- [x] CM acronyms list authored: ATO, CCB, CI, CM, CMDB, ISSM, ISSO,
      NIST, POAM, RMF, SP, SSP, STIG (13 entries)
- [x] Section 6 — Continuous Monitoring — added with family-specific
      template prose. Renders in HTML preview and DOCX.
- [x] Appendix B — Acronyms — added to schema + builder + HTML
      preview + DOCX renderer (rendered as a two-column table in DOCX,
      definition list in HTML)
- [x] Table of Contents added to DOCX. PHPWord generates a TOC field
      that Word users right-click → "Update Field" to populate. A
      hint is rendered above the empty TOC explaining this.
- [x] Editorial title case via new `editorial_title` Twig filter and
      mirrored helper in DocxRenderer. 800-53 r5 stores titles in
      ALL CAPS so the default `|title` filter produced "Policy And
      Procedures"; now reads "Policy and Procedures" as it should.
- [x] Full self-validation: rendered an end-to-end CM Plan with all
      12 applicable controls (mix of Implemented / Inherited / Not
      Applicable). 17.6 KB DOCX, valid Word 2007+, 12 H1 / 18 H2 /
      24 H3 hierarchy, all sections + appendices present.
- [x] References list fixed (PHPWord 1.4 doesn't expose addLink on
      list items - hand-rolled bullet glyph + addLink on a TextRun).

Outstanding for true ongoing validation:
- [x] Real RMF practitioner generates a CM Plan end-to-end against
      a fake/test system; capture friction (DONE 2026-05-02 — feedback
      received, drove the Phase 2 scope below)
- [x] Gap analysis vs a real delivered CM Plan from a package
      (DONE 2026-05-02 — see Phase 2 mapping)
- [ ] Iterate on DOCX styling / prose / question wording based on
      that feedback (rolls into Phase 2)

---

## Phase 2 — Assessability (CM-baseline for all families)

### Why this exists

Phase 1 produced a structurally valid CM Plan but a real RMF
practitioner reviewing it called out twenty assessability gaps. The
core deficiency: the plan documents *intent and structure* but lacks
the **enforceable, measurable, organization-defined parameters** that
ATO evidence requires. Phase 2 closes those gaps.

**This becomes the new floor for every family plan.** Engine changes
in Phase 2 benefit AC, AU, IR, etc. for free; the CM-specific schema
authoring becomes the template every other family copies from.

### How the 20 reviewer items map to Phase 2 work

| # | Reviewer item                              | Slice | Solvable? | Notes |
|---|--------------------------------------------|-------|-----------|-------|
|  1 | Missing organization-defined values (ODVs) | 2A    | Fully     | Engine: parse `[Assignment: …]` from catalog; per-control ODV input fields; render substituted statement. **Single biggest impact.** |
|  2 | "No CM-3 section"                          | 2B/2C | Misread   | CM-3 is rendered. Reviewer meant *narrative depth* — addressed via 2C structured fields for change-control specifics. |
|  3 | Enhancements tailoring traceability         | 2A    | Fully     | Engine: return ALL enhancements with `in_baseline` flag; per-enhancement disposition picker (Selected / Inherited / Tailored Out + rationale). |
|  4 | Implementation sections empty               | 2C    | Partial   | User must type substance. Engine: per-control prompts checklist alongside narrative textarea. |
|  5 | Evidence model placeholders                 | 2C    | Partial   | Engine: per-control `evidence_suggestions` rendered as one-click "add" buttons. |
|  6 | CI identification methodology               | 2B    | Fully     | New family-question group: naming conventions, categorization, relationship mapping, version control. |
|  7 | Hardening sources (DISA STIG, CIS, vendor)  | 2B    | Fully     | New family-question list field for authoritative sources. |
|  8 | CM-7 least functionality not operationalized| 2C    | Partial   | Per-control extra-fields for CM-7: allowed services/ports, disabled services, allowlisting approach. |
|  9 | Drift detection automation strategy         | 2B    | Fully     | Extend §6 Continuous Monitoring schema with structured sub-fields: tooling, scan frequency, alert thresholds, SIEM integration. |
| 10 | Integration with other RMF artifacts        | 2B    | Mostly    | New §7 "Integration" section with boilerplate + user-fillable cross-reference fields (SSP section, POA&M ID prefix, RA-5 scan source, CA-7 monitoring strategy). |
| 11 | Separation of duties / CM-5                 | 2C    | Fully     | Per-control CM-5 extra-fields: who submits / approves / implements; AC-6 cross-reference; enforcement mechanism. |
| 12 | Configuration audit strategy                | 2B    | Fully     | New family-question group: functional vs physical audits, frequency, independent vs system-owner role, reconciliation. |
| 13 | CM-8 inventory attributes                   | 2C    | Fully     | Per-control CM-8 extra-fields enumerating tracked attributes (Asset ID, Owner, Location, Software Version, Patch Level, Auth Status). |
| 14 | Exception management process                | 2B    | Fully     | New family-question group: deviation approval workflow, time-bound waivers, POA&M linkage, revalidation cadence. |
| 15 | Backup integration (CM-9 / CP overlap)      | 2B    | Fully     | New family-question group: baseline recoverability, versioning, integrity protection. |
| 16 | CM metrics / KPIs                           | 2B    | Fully     | New family-question list field with suggested examples (% compliant, MTTR, unauthorized changes). |
| 17 | CCB definition incomplete                   | 2B    | Fully     | Replace single `ccb_chair` field with structured CCB group: membership, quorum, decision thresholds, emergency-change criteria, doc standards. |
| 18 | Supply chain / software provenance          | 2C    | Partial   | Per-control CM-10 / CM-11 prompts and structured fields. |
| 19 | Configuration documentation control         | 2B    | Fully     | New family-question group: versioning schema, approval signatures, retention requirements. |
| 20 | Inheritance / hybrid control discussion     | 2B    | Mostly    | Already supported per-control via "Inherited" status. Add a §1.5 boilerplate reminder paragraph naming commonly-inheritable controls (CM-1 from org policy, etc.) and prompting users to check inheritance before defaulting to Implemented. |

### Phase 2 decisions

- **Schema-driven still applies.** All Phase 2 features are configured
  through schema fields; engine knows how to consume any schema.
- **No LLM.** Phase 2 expands the *structure* the user fills in, not
  the prose itself.
- **Backward compatibility.** Existing BYOJSON drafts from Phase 1
  must still load. New schema fields default to empty when absent.
  `schema_version` bumps to 2 when any net-new field is required.
- **Sub-field rendering follows the existing CSS-class pattern.**
  Per-control extra-fields appear under the existing per-control card,
  conditional on status (mostly Implemented / Partially Implemented).
- **The CM schema becomes the canonical template.** When the engine
  is solid, copying `cm.json` to `<fam>.json` produces a starting
  point with all 9 family-question groups, the §7 Integration block,
  the inheritance reminder, and per-control structured guidance for
  high-impact controls.
- **Tradeoff acknowledged.** A user who skips the new fields gets a
  weaker plan. That's intentional: the wizard *prompts* for the
  content an assessor will look for; the user still owns supplying
  it. Plan quality scales with input thoroughness.

### Phase 2A — Engine core (~12–16 h)

Foundational infrastructure that every other slice depends on. The
biggest investment in Phase 2 and the part that pays back across all
17+ families.

- [ ] **ODV extraction**. Add `OdvExtractor` service that parses
      `<statement>` text in the 800-53 r5 catalog and pulls out every
      `[Assignment: organization-defined …]`, `[Selection: …]`, and
      `[Selection (one or more): …]` placeholder. Returns a list of
      `{id, kind: assignment|selection, prompt, options?}` per control.
- [ ] **Per-control ODV input fields in the wizard.** When a control
      card is rendered, show one input per ODV under a new "Define
      organizational values" section of the card. Selection-types
      render as multi-select; Assignment-types as text. Field IDs
      are derived from a stable hash of the ODV prompt so they survive
      catalog re-parsing.
- [ ] **ODV substitution at render time.** `PlanBuilder` builds a
      substituted version of each control's `statement` text where
      `[Assignment: …]` placeholders are replaced inline with the
      user's chosen value (formatted as **bold** in the rendered
      output to call out org-supplied content). Both HTML preview and
      DOCX renderer use the substituted version.
- [ ] **BYOJSON shape for ODVs.** New `controls.<num>.odv` map keyed
      by ODV id. Bumps `schema_version` to 2.
- [ ] **Enhancement tailoring traceability.** Modify `ControlResolver`
      to return ALL enhancements for a control, with an `in_baseline`
      boolean flag. Per-control card in the wizard shows enhancements
      collapsed into a sub-list with a per-enhancement disposition
      picker: **Selected** (treated as a regular control, gets its
      own implementation block) / **Inherited** / **Tailored Out**
      (with mandatory rationale).
- [ ] **Render enhancement dispositions.** HTML preview and DOCX
      renderer show every enhancement under its parent control with
      its disposition. Tailored-out enhancements render their
      rationale prominently so the assessor can see the decision.
- [ ] **Generic per-control guidance mechanism.** Add a
      `per_control_guidance` block to the family schema, keyed by
      control number. Each entry can supply:
      - `prompts`: array of strings → rendered as a checklist next to
        the narrative textarea (visual only, doesn't gate input)
      - `evidence_suggestions`: array of strings → rendered as
        one-click "add this evidence" buttons that pre-populate the
        evidence list field
      - `extra_fields`: array of additional structured fields (same
        shape as `control_questions`) appended to the per-control card
- [ ] **Conditional extra-field rendering.** Extra fields honor the
      same status-class show/hide pattern (`is-implemented`,
      `is-inherited`, etc.) so they only appear when relevant.
- [ ] **Update plans.js** to collect extra-field answers into the
      `controls.<num>.extras` sub-key alongside the existing core
      answers. Hydration logic reads them back into the right fields.
- [ ] **Regression test.** A Phase 1-era CM-only payload (no ODVs, no
      tailoring decisions, no extras) still renders cleanly through
      the Phase 2 engine — fields just go empty.

### Phase 2B — CM family schema enrichment (~6–8 h)

Authoring work on `cm.json` that reaches all the "Fully solvable"
items above. Becomes the canonical template for AC, AU, IR, etc.

- [ ] **CCB definition** (item 17). Replace `ccb_chair` and
      `ccb_cadence` simple fields with a `ccb` group:
      - `chair_role`, `membership` (list), `quorum`,
      - `decision_thresholds` (text — e.g., "All changes require
        majority; high-impact require unanimous"),
      - `emergency_change_criteria`,
      - `documentation_standards`.
      `approach_template` updated to weave these into the §4 narrative.
- [ ] **CI identification methodology** (item 6). New family-question
      group `ci_methodology`:
      - `naming_convention` (textarea),
      - `categorization` (multi-select: Hardware / Software / Firmware /
        Documentation / Configuration Files / other),
      - `relationship_mapping` (textarea),
      - `version_control_structure` (textarea).
      Renders in §3.5 (new sub-section under System Overview) titled
      "Configuration Item Identification."
- [ ] **Hardening sources** (item 7). New family-question
      `hardening_sources` as a list field with suggestions:
      "DISA STIGs", "CIS Benchmarks", "NIST SP 800-70", "Vendor
      hardening guides (specify which)". Renders in §4 narrative + a
      bullet list.
- [ ] **Drift detection automation** (item 9). Extend §6 Continuous
      Monitoring with structured sub-fields:
      - `drift_tooling` (text — e.g., "SCCM compliance baselines,
        Tenable, Ansible idempotent run"),
      - `drift_scan_frequency` (text — e.g., "weekly"),
      - `alert_thresholds` (textarea),
      - `siem_integration` (textarea).
      `continuous_monitoring_template` reworked to weave these in.
- [ ] **§7 Integration with other RMF artifacts** (item 10). New
      schema block + new section in the document. Family-questions:
      - `ssp_section_reference`,
      - `poam_id_prefix`,
      - `ra5_scan_source` (text — e.g., "Tenable.sc Container A"),
      - `ca7_monitoring_strategy_link`.
      Boilerplate prose template substitutes these in.
- [ ] **Configuration audit strategy** (item 12). New family-question
      group `audit_strategy`:
      - `audit_types` (multi-select: Functional / Physical / Both),
      - `audit_frequency`,
      - `independent_assessor_role`,
      - `system_owner_role`,
      - `reconciliation_process` (textarea).
      Renders in §4 narrative as a sub-bullet list.
- [ ] **Exception management** (item 14). New family-question group
      `exception_management`:
      - `approval_workflow` (textarea),
      - `waiver_max_duration`,
      - `poam_linkage` (textarea),
      - `revalidation_cadence`.
      Renders in §4 narrative.
- [ ] **Backup integration** (item 15). New family-question group
      `backup_strategy`:
      - `recoverability_approach` (textarea),
      - `versioning` (textarea),
      - `integrity_protection` (textarea — e.g., "git tags signed
        with org GPG key, hash-verified at deploy").
      Renders in a new sub-section under §6 Continuous Monitoring.
- [ ] **CM metrics / KPIs** (item 16). New family-question
      `cm_metrics` as a list field with suggestions: "% assets
      compliant with baseline", "Mean time to remediate drift",
      "Unauthorized changes detected per quarter", "% changes routed
      through CCB", "% emergency changes". Renders as a bullet list
      under §6.
- [ ] **Configuration documentation control** (item 19). New
      family-question group `documentation_control`:
      - `versioning_schema` (text — e.g., "MAJOR.MINOR.PATCH"),
      - `approval_signatures_required` (multi-select: System Owner /
        ISSO / ISSM / CCB Chair / AO),
      - `retention_period`.
      Renders as a sub-section under §4.
- [ ] **§1.5 Inheritance reminder** (item 20). New boilerplate section
      injected after §1.4 References:
      - Brief paragraph reminding the reader that controls can be
        inherited from common control providers
      - List of commonly-inheritable controls in this family (for CM:
        CM-1 from organizational policy, possibly CM-10 from
        enterprise SAM)
      - Prompt to verify inheritance in §5 before defaulting to
        Implemented.
      Schema field `commonly_inheritable_controls` enumerates them
      so each family can list its own.
- [ ] **Glossary expansion**. Add terms surfaced by the new sections:
      "Functional Configuration Audit", "Physical Configuration Audit",
      "Tailoring", "Selection Statement", "Assignment Statement",
      "Common Control Provider", "Hybrid Control", "Waiver",
      "Drift Detection".
- [ ] **Acronyms expansion**. CCB, CI, ODV, FCA, PCA, PII, NIST,
      SCCM, GPO, RBAC, SP, SSP, STIG, CIS, NVD.
- [ ] **References expansion**. Add NIST SP 800-128 sections, NIST SP
      800-137 (continuous monitoring), CNSSI 1253 if relevant for the
      common federal use case.

### Phase 2C — Per-control guided content for CM (~4–6 h)

Schema-only effort using the `per_control_guidance` mechanism from 2A.
Hits the high-impact controls identified by the reviewer.

- [ ] **CM-3** (Configuration Change Control) prompts + extra-fields:
      - prompts: "approval thresholds", "separation of duties",
        "enforcement mechanisms", "emergency change pathway"
      - evidence_suggestions: "CCB meeting minutes (last 90 days)",
        "Sample change ticket", "Emergency change log"
      - extra-fields: `approval_thresholds` (textarea),
        `separation_of_duties` (textarea), `enforcement_mechanism` (text)
- [ ] **CM-5** (Access Restrictions for Change) — item 11:
      - prompts: "who can submit changes", "who can approve",
        "who can implement", "AC-6 cross-reference"
      - evidence_suggestions: "IAM group membership report (CM
        privileged group)", "Quarterly access review records"
      - extra-fields: `submitters` (text), `approvers` (text),
        `implementers` (text), `ac6_reference` (text),
        `enforcement_rbac` (textarea)
- [ ] **CM-6** (Configuration Settings) — item 7 reinforcement:
      - prompts: "STIG / CIS / vendor hardening sources cited",
        "Deviation register", "Enforcement mechanism (Ansible /
        SCCM / GPO)", "Verification cadence"
      - evidence_suggestions: "Ansible playbook repository link",
        "Tenable scan results dashboard", "Approved deviation
        register", "STIG compliance report"
      - extra-fields: `hardening_sources_cited` (list),
        `deviation_register_link` (text),
        `enforcement_mechanism` (textarea)
- [ ] **CM-7** (Least Functionality) — item 8:
      - prompts: "Allowed services and ports", "Disabled services",
        "Application allowlisting approach"
      - evidence_suggestions: "Allowed-services baseline document",
        "Tenable scan output (port/service)", "Allowlisting policy"
      - extra-fields: `allowed_services` (textarea — long),
        `disabled_services` (textarea), `allowlisting_approach`
        (textarea)
- [ ] **CM-8** (System Component Inventory) — item 13:
      - prompts: "Tracked attributes per CI", "Inventory tooling",
        "Reconciliation cadence"
      - evidence_suggestions: "ServiceNow CMDB report", "AWS Config
        inventory snapshot", "Monthly inventory review minutes"
      - extra-fields:
        `tracked_attributes` (multi-select with custom: Asset ID,
        Owner, Location, Hardware Specs, Software Version, Patch
        Level, Authorization Status, IP Address, MAC, Hostname,
        Criticality, Data Classification),
        `inventory_tooling` (text),
        `reconciliation_cadence` (text)
- [ ] **CM-10** (Software Usage Restrictions) — item 18:
      - prompts: "License tracking system", "Usage compliance
        verification", "Upstream provenance"
      - evidence_suggestions: "License inventory report", "SBOM if
        available"
      - extra-fields: `license_tracking_system` (text),
        `provenance_approach` (textarea)
- [ ] **CM-11** (User-Installed Software) — item 18:
      - prompts: "Restriction enforcement (allowlist / endpoint
        management / OS-level)", "Approved exception process"
      - evidence_suggestions: "Endpoint management policy",
        "Exception register"
      - extra-fields: `restriction_enforcement` (text),
        `exception_process` (textarea)

### Phase 2D — Validation pass (~2–3 h)

- [ ] Generate a full CM Plan with all Phase 2 features exercised
      (ODVs filled, every CM enhancement dispositioned, every new
      family-question group answered, per-control extras populated
      for the 7 enhanced controls).
- [ ] Manual review against the original 20-item list — confirm each
      item is meaningfully addressed in the rendered output.
- [ ] Compare DOCX page count and content density to a real
      delivered CM Plan from a federal package.
- [ ] Verify backward compatibility: load a Phase 1 BYOJSON draft
      into the Phase 2 wizard; new fields default to empty; preview
      and download still succeed.
- [ ] Update the lessons-learned section if new gotchas emerge.

### Phase 2 totals

- 2A engine: 12–16 h
- 2B CM schema enrichment: 6–8 h
- 2C CM per-control guidance: 4–6 h
- 2D validation: 2–3 h
- **Total: ~25–33 h** for the assessability bump

### Knock-on effect on Future families

Once Phase 2 is shipped, each additional family schema is bigger
than the original Phase 1 estimate but the per-family ratio is much
better. Per-family cost rises from ~4–8 h (Phase 1 floor) to
**~12–18 h** (Phase 2 floor), but the resulting plan is genuinely
assessable instead of a structural shell. The engine work itself
amortizes across all 17+ families.

---

### Future: additional family plans

Each family below is a schema-only effort once Phase 2 ships.
Expected effort: ~12–18 h per family (was 4–8 h before Phase 2 raised
the assessability floor) for prose templates, family questions,
per-control guidance, glossary, acronyms, references. Order suggested
by RMF priority, but reorderable based on demand.

- [ ] AC — Access Control Plan
- [ ] AU — Audit and Accountability Plan
- [ ] AT — Awareness and Training Plan
- [ ] CP — Contingency Plan (intersection with COOP/DR)
- [ ] IA — Identification and Authentication Plan
- [ ] IR — Incident Response Plan
- [ ] MA — Maintenance Plan
- [ ] MP — Media Protection Plan
- [ ] PE — Physical and Environmental Protection Plan
- [ ] PL — Planning (lightweight; mostly meta-plan)
- [ ] PM — Program Management (org-wide, may not fit single-system pattern)
- [ ] PS — Personnel Security Plan
- [ ] RA — Risk Assessment Plan
- [ ] SA — System and Services Acquisition Plan
- [ ] SC — System and Communications Protection Plan
- [ ] SI — System and Information Integrity Plan
- [ ] SR — Supply Chain Risk Management Plan (r5 only)
- [ ] PT — PII Processing and Transparency Plan (r5 only)

### Stretch / nice-to-have (post-MVP)

- [ ] Configurable DOCX cover (org name + logo upload, color theme)
- [ ] Multiple draft management UI (currently BYOJSON files manage themselves)
- [ ] Plan diff: upload two drafts, see what changed control-by-control
- [ ] Cross-family combined SSP generator (consume multiple per-family
      drafts, emit a single SSP)
- [ ] Plan templates per overlay (e.g., a CM Plan tuned to FedRAMP High
      versus CNSSI 1253 Classified — different boilerplate)
- [ ] Versioned draft history within a single JSON file (revision array)
- [ ] PDF export option (in addition to DOCX) via headless renderer
