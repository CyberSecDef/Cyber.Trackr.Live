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

### Phase 2A — Engine core (~12–16 h) — COMPLETE

Foundational infrastructure that every other slice depends on. The
biggest investment in Phase 2 and the part that pays back across all
17+ families.

- [x] **ODV extraction** — `App\Service\Plans\OdvExtractor` parses
      `[Assignment: organization-defined …]`, `[Selection: …]`,
      `[Selection (one or more): …]` placeholders out of catalog
      statement text. Bracket-balancing parser handles nested cases.
      Each ODV gets `{id (position-indexed), kind, prompt, options,
      placeholder}`. Strips "organization-defined" prefix from prompts
      so labels read "Frequency" not "organization-defined frequency".
- [x] **Per-control ODV input fields in the wizard.** Each control
      card now has a "Define organizational values" section with one
      input per ODV. Assignment → text, Selection → single-select,
      Selection (one or more) → checkbox group. Position-indexed IDs
      survive catalog edits unless NIST reorders sub-statements.
- [x] **Statement segmentation.** Builder calls
      `OdvExtractor::tokenize()` to produce a `[{type, value, filled}, …]`
      stream. Each renderer applies its own markup (HTML `<strong>` +
      accent for unfilled, DOCX bold-run, etc.) without coupling.
- [x] **ODV substitution at render time.** Both HTML preview and DOCX
      renderer iterate the segments. Filled ODVs render bold; unfilled
      render `[TO BE COMPLETED: <prompt>]` with an accent / italic
      style so missing values are visible at a glance.
- [x] **BYOJSON shape for ODVs.** New `controls.<num>.odv` map keyed
      by ODV id. `schema_version` bumped to 2.
- [x] **Enhancement tailoring traceability.** ControlResolver walks
      `<control-enhancement>` elements (was missing — they live nested
      under `<control>` in the default `aaa:` namespace, not as
      top-level `<controls:control>`). All enhancements for an
      in-baseline parent are returned with `in_baseline` flag.
      Enhancements whose parent is out of baseline are skipped per
      RMF semantics.
- [x] **Disposition picker UI.** Each enhancement card in the wizard
      gets a Selected / Inherited / Tailored Out picker. Default for
      out-of-baseline enhancements is Tailored Out (pre-selected with
      `<option selected>`), default for in-baseline is Selected.
- [x] **Disposition-driven CSS class toggle.** `.is-selected /
      .is-tailored / .is-disp-inherited` classes on the parent card
      drive sub-field visibility. Tailored Out cards dim slightly
      (opacity 0.85) so the eye can pick out the active scope.
- [x] **Render enhancement dispositions inline.** HTML preview and
      DOCX renderer both group enhancements under their parent base
      control and render each with disposition-specific content:
      Selected → full implementation block; Inherited → inheritance
      provider + details; Tailored Out → rationale only. Sub-section
      numbering 5.X.Y in DOCX.
- [x] **Generic per-control guidance mechanism.** Schema may declare
      a `per_control_guidance` block keyed by control number with
      `prompts` (checklist), `evidence_suggestions` (one-click chips
      that pre-populate the evidence list), and `extra_fields` (same
      shape as `control_questions`). Wizard renders all three; both
      renderers surface filled extras in the meta table. CM schema
      doesn't populate this yet — that's Phase 2C.
- [x] **plans.js extras-collection.** `data-control-extra-key`
      attributes feed `controls.<num>.extras`. Hydration reads them
      back. Status-badge logic refreshed to combine status +
      disposition (Tailored Out / Inherited disposition takes
      precedence over status; Selected falls through to status).
- [x] **Backward compatibility (regression test).** A Phase-1-era
      payload (no `odv`, no `disposition`, no `extras`) still
      renders cleanly through the Phase 2 engine — those fields
      default to empty, ODV substitution leaves placeholders as
      `[TO BE COMPLETED: <prompt>]`, base controls behave as before.

End-to-end smoke test: rendered a complete CM Plan at nist-low
baseline with a mix of dispositions (Implemented base, Inherited
enhancement, Selected enhancement with substituted ODV, Tailored
Out enhancement). 22 KB DOCX, valid Word 2007+, document.xml passes
xmllint. Wizard fragment for the same baseline returns 51 cards
(9 bases + 42 enhancements, all pre-defaulted to Tailored Out
since none are nist-low-required) with 66 ODV inputs and 42
disposition pickers.

Also fixed during 2A: `_shared.json` `schema_version` field was
implicit — now explicit in plans.js and PlanBuilder normalization.

### Phase 2B — CM family schema enrichment (~6–8 h) — COMPLETE

Authoring work on `cm.json` that reaches all the "Fully solvable"
items above. Becomes the canonical template for AC, AU, IR, etc.

All schema groups land via a new `type: group` schema construct that
also carries a `section` attribute (system_overview / approach /
continuous_monitoring / integration). PlanBuilder's
`buildFamilySections()` buckets answers by section; renderers iterate
each bucket as ordered sub-sections (§2.x, §4.x, §6.x, §7.x).

- [x] **CCB definition** (item 17). Replaced `ccb_chair` /
      `ccb_cadence` flat fields with a structured `ccb` group:
      `ccb_chair_role`, `ccb_cadence`, `ccb_membership` (list),
      `ccb_quorum`, `ccb_decision_thresholds`,
      `ccb_emergency_change_criteria`, `ccb_documentation_standards`.
- [x] **CI identification methodology** (item 6). New `ci_methodology`
      group renders as §2.3 "Configuration Item Identification" sub-
      section under System Overview. Multi-select for `ci_categorization`
      (Hardware / Software / Firmware / Documentation / Configuration
      files / Network components / Cryptographic keys / Cloud resources).
- [x] **Hardening sources** (item 7). New `hardening_sources` group
      with a single list field carrying suggestions: DISA STIGs, CIS
      Benchmarks, NIST SP 800-70 NCP, Vendor hardening guides, Internal
      hardening standard.
- [x] **Drift detection automation** (item 9). New `drift_detection`
      group landing in §6 with structured fields: `drift_tooling`,
      `drift_scan_frequency`, `drift_alert_thresholds`,
      `drift_siem_integration`.
- [x] **§7 Integration with other RMF artifacts** (item 10). New
      `integration_template` + `integration` group with
      `ssp_section_reference`, `poam_id_prefix`, `ra5_scan_source`,
      `ca7_monitoring_strategy_link`. Renders as standalone §7.
- [x] **Configuration audit strategy** (item 12). New `audit_strategy`
      group: `audit_types` (multi-select: FCA / PCA / Software / License),
      `audit_frequency`, `audit_independent_role`,
      `audit_system_owner_role`, `audit_reconciliation_process`.
- [x] **Exception management** (item 14). New `exception_management`
      group: `exception_approval_workflow`,
      `exception_waiver_max_duration`, `exception_poam_linkage`,
      `exception_revalidation_cadence`.
- [x] **Backup integration** (item 15). New `backup_strategy` group
      under §6: `backup_recoverability`, `backup_versioning`,
      `backup_integrity_protection`.
- [x] **CM metrics / KPIs** (item 16). New `cm_metrics` group with a
      single list field carrying 7 suggestion items.
- [x] **Configuration documentation control** (item 19). New
      `documentation_control` group: `doc_versioning_schema`,
      `doc_approval_signatures_required` (multi-select),
      `doc_retention_period`.
- [x] **§1.5 Inheritance reminder** (item 20). New schema field
      `commonly_inheritable_controls` (array of `{number, from}`)
      drives a §1.5 boilerplate section reminding readers to check
      inheritance before defaulting to Implemented. CM ships with
      CM-1 (org policy), CM-10 (enterprise SAM), CM-11 (endpoint
      management) as commonly-inheritable.
- [x] **Glossary expansion**. Added: Common Control Provider,
      Functional Configuration Audit, Hybrid Control,
      Organization-Defined Value, Physical Configuration Audit,
      Selection Statement, Tailoring, Waiver. (8 new entries — total
      now 22.)
- [x] **Acronyms expansion**. Added: AO, CA-7, CIS, ConMon, DISA,
      FCA, GPO, NCP, NVD, ODV, PCA, RA-5, RBAC, SCCM, SIEM. (15 new
      — total now 28.)
- [x] **References expansion**. Added NIST SP 800-137 (ConMon) and
      CNSSI 1253. (Total now 6.)
- [x] **Engine support** (carries to all future families):
      - PlanBuilder `buildFamilySections()` consumes grouped schemas
      - HtmlRenderer + DocxRenderer added `familySubSection` macros
      - Wizard fragment groups render as nested `<details>` so the
        user expands the group they're working on
      - New `multi_select` field type with checkbox group rendering
        + array hydration in plans.js
      - `evidence_suggestions` mechanic on list fields surfaces
        one-click suggestion chips outside per-control context too

Also fixed during 2B: PHPWord 1.4 doesn't XML-escape body text passed
to `addText()` — a literal `<env>` or `&` in user input produced
invalid document.xml that unzip accepts but Word refuses (same class
as the §1D safeHeading bug, different code path). Added a recursive
`escapeStringsRecursively()` pass at the top of `DocxRenderer::build()`
that pre-encodes every string in the plan via htmlspecialchars.
PHPWord writes the entity-encoded bytes verbatim and Word decodes
them on open. `safeHeading()` updated to first decode entities
introduced by that pass before stripping `& < >` from headings.

End-to-end smoke test: full Phase 2B payload (all 11 family-question
groups populated with realistic content, 3 multi-select answers, 2
list answers including evidence suggestions, an ODV-laden CM-1
narrative) renders to a 26 KB DOCX with valid OOXML. Sub-section
headings 1.5 / 2.3 / 4.1-4.6 / 6.1-6.3 / 7.1 all present and
correctly numbered. Phase 2A regression test still passes.

### Phase 2C — Per-control guided content for CM (~4–6 h) — COMPLETE

Schema-only effort using the `per_control_guidance` mechanism from 2A.
Hits the high-impact controls identified by the reviewer. Each control
gets prompts (checklist of items to address in the narrative), evidence
suggestions (one-click chips that prepopulate the evidence list), and
structured extra fields (textarea / text / multi_select / list) that
capture data the assessor expects to see broken out separately.

- [x] **CM-3** (Configuration Change Control). 6 prompts including
      CCB cross-ref, approval thresholds, SoD, enforcement, emergency
      pathway, security-impact analysis. 6 evidence suggestions. 3
      extra fields: `cm3_approval_thresholds`, `cm3_separation_of_duties`,
      `cm3_enforcement_mechanism`.
- [x] **CM-5** (Access Restrictions for Change) — item 11. 5 prompts
      including AC-6 + AU-2/AU-12 cross-references. 5 evidence
      suggestions. 5 extra fields: `cm5_submitters`, `cm5_approvers`,
      `cm5_implementers`, `cm5_ac6_reference`, `cm5_enforcement_rbac`.
- [x] **CM-6** (Configuration Settings) — item 7 reinforcement.
      5 prompts on hardening sources, baseline derivation, deviation
      register, enforcement, verification cadence. 5 evidence
      suggestions. 3 extra fields: `cm6_hardening_sources_cited`
      (list with STIG/CIS suggestions), `cm6_deviation_register_link`,
      `cm6_enforcement_mechanism`.
- [x] **CM-7** (Least Functionality) — item 8. 5 prompts on allowed
      services, disabled services, allowlisting, network exposure,
      periodic review. 5 evidence suggestions. 3 extra fields:
      `cm7_allowed_services` (long textarea), `cm7_disabled_services`,
      `cm7_allowlisting_approach`.
- [x] **CM-8** (System Component Inventory) — item 13. 5 prompts on
      system of record, tracked attributes, update model, reconciliation,
      ownership. 5 evidence suggestions. 3 extra fields:
      `cm8_tracked_attributes` (multi_select with 14 options including
      Asset ID, Owner, Location, Hardware Specs, Software / firmware
      version, Patch Level, Auth Status, IP, MAC, Hostname, Criticality,
      Data Classification, Network Zone, Decommission Date),
      `cm8_inventory_tooling`, `cm8_reconciliation_cadence`.
- [x] **CM-10** (Software Usage Restrictions) — item 18. 4 prompts on
      license tracking, count verification, provenance / SBOM,
      acquisition gating. 4 evidence suggestions. 2 extra fields:
      `cm10_license_tracking_system`, `cm10_provenance_approach`.
- [x] **CM-11** (User-Installed Software) — item 18. 4 prompts on
      enforcement mechanism, exception process, detection, AUP. 5
      evidence suggestions. 2 extra fields: `cm11_restriction_enforcement`,
      `cm11_exception_process`.

Engine improvements that landed alongside:
- [x] Wizard fragment now supports `multi_select` inside per-control
      `extra_fields` (was only system-level family questions in 2A).
- [x] Wizard fragment now supports `suggestions` on per-control list
      `extra_fields` (e.g., CM-6's `cm6_hardening_sources_cited`).
- [x] `plans.js` collects multi-select extras as arrays via
      `data-multi-select="1"` attribute walking (was only handling
      list and plain inputs before).
- [x] `PlanBuilder::prepareExtrasForRender()` pairs each user-supplied
      extras value with its schema metadata (label, type) and produces
      a schema-ordered `extras_rendered` array. Renderers iterate it
      so labels come from the schema, ordering is predictable, and
      unfilled fields render as `[TO BE COMPLETED]` rather than being
      silently absent. Replaces the old "ucfirst the field id" hack.

End-to-end smoke test: nist-moderate baseline payload exercising
CM-3 / CM-7 / CM-8 extras (textarea, textarea, multi_select). Wizard
fragment surfaces all 22 extra-field keys across the 7 enhanced
controls with 7 prompt checklists and 40 suggestion chips. HTML
preview renders schema-labeled extras (Approval thresholds, Allowed
services / ports / protocols, Tracked attributes etc.) with multi-
select rendered as `<ul><li>` lists. DOCX 29.7 KB, valid OOXML, all
extras text present in body.

### Phase 2D — Validation pass (~2–3 h) — COMPLETE

- [x] **Full Phase 2 payload exercised.** Authored
      `/tmp/p2d-full.json` with all 12 system fields, all 11 family-
      question groups populated with realistic content, every
      applicable CM control + enhancement dispositioned (Implemented /
      Inherited / Tailored Out mix), per-control extras populated
      for CM-3 / CM-5 / CM-6 / CM-7 / CM-8 / CM-10 / CM-11, ODVs
      filled across every control with placeholders. Renders to a
      34.5 KB DOCX (332 KB document.xml), valid OOXML throughout.
- [x] **20-item reviewer checklist re-verified.** Wrote a 60-check
      script walking each reviewer item and probing the rendered
      DOCX for evidence. **60 of 60 pass.** The one false negative
      (`>5% drift`) was entity-encoding: the `>` in user input is
      stored as `&gt;` in the XML and decoded by Word on display.
- [x] **Sample-plan comparison** (4 real CM Plans at
      `/home/rweber/Documents/cm_plans/`). Sample plans run 24-31
      pages and 23-67k chars; the generated Phase 2D plan is
      **49 pages / 88k chars**. The size comes from per-control
      traceability — every applicable control + enhancement gets
      its own §5.X.Y section with the catalog statement, ODV-
      substituted text, structured extras, and meta. Sample plans
      tend to be process-organized (CI / Change Control / CSA /
      Audits) while the generated plan is control-organized
      (per-800-53 §5.X). Both structures are valid; control-
      organized is the right choice for ATO-assessment workflows
      since assessors can find any specific control by number.
- [x] **Backward compatibility verified.** A Phase 1-shape payload
      (no `odv`, no `disposition`, no `extras`, old `ccb_chair` and
      `ccb_cadence` field names from the pre-2B flat schema) loads
      into the Phase 2 engine and renders cleanly: HTTP 200, valid
      OOXML, 26 KB DOCX. Phase 1 control narratives still surface;
      missing Phase 2B/2C structured fields render as 177 `[TO BE
      COMPLETED]` markers across the doc, making gaps explicit
      rather than silent. Note: the legacy `ccb_chair` field name
      doesn't auto-migrate to `ccb_chair_role` — users with Phase 1
      drafts will see `[TO BE COMPLETED: ccb_chair_role]` in the §4
      narrative until they fill in the new structured CCB group.
      Acceptable given the `schema_version` bump from 1 to 2.

### Phase 2D — Findings & lessons learned

**XML escaping in Word body text** — PHPWord 1.4 doesn't XML-escape
text passed to `addText()`. A literal `<env>`, `&`, or `>` in user
input produces invalid `document.xml` that `unzip` accepts but Word
refuses. Fixed by `DocxRenderer::escapeStringsRecursively()` walking
the entire plan structure before any PHPWord calls. **Documented in
the existing memory** at `feedback_phpword_heading_escaping.md` — the
heading-escape and body-escape are different code paths in PHPWord
but the symptom (Word refuses to open) is identical.

**ccb_chair → ccb_chair_role rename loses Phase 1 data.** Acceptable
because schema_version bumped, but worth noting for future schema
migrations. Pattern for next time: when renaming a field that has
existing user data, add a migration in `plans.js` `hydrate()` that
maps old → new keys based on `schema_version`.

**Real-plan structure differs.** Samples are process-organized
(Configuration Identification / Change Control / Status Accounting /
Audits as top-level sections). Generated plan is control-organized.
The control-organized layout is correct for RMF assessment, but for
operational CM teams, a "process digest" near the front would help.
Candidate future enhancement: a §4.0 prose summary that walks the
CM lifecycle in the practitioner's frame before the structured §4.x
sub-sections and per-control §5 begin.

**Sections present in real plans but not yet in our schema:**
- Configuration Status Accounting as its own concept (we touch on
  it via `ccb_documentation_standards`, but it's a distinct CMMI
  process and assessors may look for it explicitly)
- Data Management / Archive & Retrieval (we cover via
  `backup_strategy` + `doc_retention_period` but spread across
  sections)
- CM Training (real plans often include a paragraph; AT-2/AT-3
  controls cover formal training but the CM-specific application
  is often called out)

These are candidate Phase 3 additions — not gaps for ATO
assessability, but they'd help when an operational CMMI assessor
reviews the plan with a process lens.

**Phase 2 totals (actual)**: ~32 hours focused work, within the
25-33 estimate. Engine work (2A) was the biggest chunk; schema
authoring (2B + 2C) was smooth thanks to the engine being solid.

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

### Future: additional family plans — ordered execution plan

Each family below is a schema-only effort once Phase 2 ships.
Expected effort: ~12–18 h per family for prose templates, family
questions, per-control guidance, glossary, acronyms, references.
Tiered by impact + natural pairings to plans already authored.

**Tier 1 — high impact, natural pairs to completed work**

- [x] CM — Configuration Management Plan (Phase 1 + 2)
- [x] AC — Access Control Plan
- [x] AU — Audit and Accountability Plan
- [x] IA — Identification and Authentication Plan (authored at Phase 2 floor; 11 family-question groups, 8 guided controls — IA-2/3/4/5/7/8/11/12 — with 14 extra fields, 26 glossary entries, 40 acronyms, 8 references)
- [x] IR — Incident Response Plan (authored at Phase 2 floor; 11 family-question groups, 8 guided controls — IR-2/3/4/5/6/7/8/9 — with 15 extra fields, 25 glossary entries, 48 acronyms, 9 references)
- [x] CP — Contingency Plan (authored at Phase 2 floor; 12 family-question groups, 8 guided controls — CP-2/3/4/6/7/8/9/10 — with 17 extra fields, 23 glossary entries, 30 acronyms, 6 references)
- [x] SI — System and Information Integrity Plan (authored at Phase 2 floor; 13 family-question groups, 8 guided controls — SI-2/3/4/5/7/10/11/12 — with 15 extra fields, 24 glossary entries, 56 acronyms, 11 references)
- [x] CA — Assessment, Authorization, and Monitoring Plan (authored at Phase 2 floor; 12 family-question groups, 7 guided controls — CA-2/3/5/6/7/8/9 — with 12 extra fields, 23 glossary entries, 39 acronyms, 9 references)

**Tier 1 COMPLETE.** All 8 high-impact / paired plans ship.

**Tier 2 — broad technical scope**

- [x] SC — System and Communications Protection Plan (authored at Phase 2 floor; 15 family-question groups, 8 guided controls — SC-7/8/12/13/18/21/23/28 — with 16 extra fields, 23 glossary entries, 70 acronyms, 11 references; renders 91 cards at nist-moderate — 18 bases + 73 enhancements — with 74 ODV inputs and 73 disposition pickers)
- [x] RA — Risk Assessment Plan (authored at Phase 2 floor; 14 family-question groups, 8 guided controls — RA-2 / RA-3 / RA-3(1) / RA-5 / RA-7 / RA-8 / RA-9 / RA-10 — with 11 extra fields, 27 glossary entries, 67 acronyms, 12 references; renders 22 cards at nist-moderate — 6 bases + 16 enhancements)
- [x] SA — System and Services Acquisition Plan (authored at Phase 2 floor; 15 family-question groups, 9 guided controls — SA-3 / SA-4 / SA-5 / SA-8 / SA-9 / SA-10 / SA-11 / SA-15 / SA-22 — with 17 extra fields, 31 glossary entries, 64 acronyms, 12 references; renders 101 cards at nist-moderate — 11 bases + 90 enhancements — with 90 ODV inputs and 90 disposition pickers)

**Tier 2 COMPLETE.** Three broad-technical-scope plans ship: SC, RA, SA.

**Tier 3 — physical, personnel, operational**

- [x] PE — Physical and Environmental Protection Plan (authored at Phase 2 floor; 14 family-question groups, 8 guided controls — PE-2 / PE-3 / PE-4 / PE-6 / PE-8 / PE-13 / PE-16 / PE-17 — with 14 extra fields, 23 glossary entries, 57 acronyms, 10 references; renders 50 cards at nist-moderate — 16 bases + 34 enhancements — with 67 ODV inputs and 34 disposition pickers; explicit inheritance-posture extras handle the common FedRAMP / colo case)
- [x] PS — Personnel Security Plan (authored at Phase 2 floor; 13 family-question groups, 7 guided controls — PS-2 / PS-3 / PS-4 / PS-5 / PS-6 / PS-7 / PS-8 — with 10 extra fields, 23 glossary entries, 56 acronyms, 9 references; renders 18 cards at nist-moderate — 9 bases + 9 enhancements; CV / Trusted Workforce 2.0, FIS Tier 1-5, EO 12968 in scope)
- [x] MA — Maintenance Plan (authored at Phase 2 floor; 10 family-question groups, 5 guided controls — MA-2 / MA-3 / MA-4 / MA-5 / MA-6 — with 10 extra fields, 17 glossary entries, 46 acronyms, 7 references; renders 29 cards at nist-moderate — 6 bases + 23 enhancements; explicit inheritance-posture extras for cloud-hosted MA-2 / MA-3)
- [x] MP — Media Protection Plan (authored at Phase 2 floor; 12 family-question groups, 6 guided controls — MP-2 / MP-3 / MP-4 / MP-5 / MP-6 / MP-7 — with 11 extra fields, 17 glossary entries, 49 acronyms, 7 references; renders 25 cards at nist-moderate — 7 bases + 18 enhancements; CUI marking per 32 CFR Part 2002 + NIST SP 800-88 r1 sanitization Decision Tree)
- [ ] AT — Awareness and Training Plan *(role-based training, security awareness, phishing exercises)*

**Tier 4 — specialized / meta**

- [ ] PL — Planning Plan *(meta-plan; SSP, rules of behavior, baseline tailoring documentation)*
- [ ] SR — Supply Chain Risk Management Plan *(r5-only; pairs with SA. SBOM, vendor assurance, anti-counterfeit)*
- [ ] PT — PII Processing and Transparency Plan *(r5-only; privacy-focused. PII, consent, data subject rights)*
- [ ] PM — Program Management Plan *(org-wide; may not fit single-system pattern. Consider intent before authoring)*

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
