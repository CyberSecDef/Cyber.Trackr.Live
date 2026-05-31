# STIG wizard schemas

Each `<stig>.json` here drives an interview-style wizard (GitHub issue #15) that
turns answers into a partially-completed CKLB. The wizard JS reads the schema,
renders the questions, then resolves the matched `actions` against a blank CKLB
skeleton built from the public STIG XCCDF. **Answers never leave the browser.**

Adding a new STIG is a data exercise — drop a new schema file, no code change.

> **Every status-changing action is an automated applicability *suggestion* and
> requires SME review.** An assessor owns the final determination.

## Schema contract

| Key | Purpose |
|-----|---------|
| `meta` | STIG id, benchmark version, source XCCDF filename, rule count. |
| `defaults` | `status` for untouched rules (`Not_Reviewed`) and the `review_stamp` appended to every auto-determined rule. |
| `system_questions[]` | System-context fields (name, ISSO, …). Captured into the CKLB asset/target data; do not change status. |
| `rule_groups` | Reusable named V-ID sets. Reference a group anywhere `rules` is accepted as the string `"@name"`. |
| `questions[]` | The interview. See below. |
| `always_apply[]` | `{rules, comment}` buckets that inject a guidance comment on always-applicable requirements **without changing status**. |

### Questions

```jsonc
{
  "id": "uses_database",
  "label": "Does the application use a database?",
  "type": "yesno | yesno_text | select | multiselect | text",
  "options": ["…"],            // select / multiselect only
  "text_label": "…",           // yesno_text only — label for the free-text box
  "help": "…",
  "answers": {
    "<answer-key>": {
      "actions":   [ /* see Actions */ ],
      "followups": [ /* nested questions, same shape */ ]
    }
  }
}
```

**Answer keys** by type:
- `yesno` / `yesno_text` → `"yes"` / `"no"`. For `yesno_text` the free text is bound to `<id>_text`.
- `select` → the exact option string.
- `multiselect` → the exact option string for "this option is checked", or `"not:<option>"` for "this option is **un**checked" (used to mark a requirement N/A when a feature is absent).

### Actions

```jsonc
{
  "rules": "@group" | ["V-…", "@group", …],
  "status": "Not_Applicable | NotAFinding | Open | Not_Reviewed",
  "confidence": "high | medium | low",
  "comment": "Free text. {var} interpolates the answer with id `var` (e.g. {uses_database_text})."
}
```

### Merge semantics (when multiple actions hit the same rule)

1. **`Not_Applicable` is dominant** — if any matched action sets N/A, the rule is N/A.
2. Otherwise status precedence is **`Not_Applicable` > `Open` > `NotAFinding` > `Not_Reviewed`** (a finding is never overwritten by a compliance claim).
3. **Comments always concatenate**, in question order, regardless of status.
4. Any rule whose final status ≠ `Not_Reviewed` gets `defaults.review_stamp` appended.

### Confidence

`high` — the gating question cleanly and unambiguously drives applicability.
`medium` — usually correct but has edge cases worth a glance.
`low` — must be manually confirmed; surfaced prominently in review / future "Strict Mode".

## Tests

- **PHPUnit** (`php bin/phpunit`) — `tests/Service/Stig/` covers the skeleton
  builder (286 rules, blank, field fidelity, unique UUIDs) and the wizard
  registry (version/title gating); `tests/Controller/StigWizardControllerTest.php`
  covers the wizard page, the view-page CTA, the skeleton endpoint, and 404s.
- **Playwright** (`bash bin/e2e.sh`) — `tests-e2e/wizard.spec.js` drives the
  full browser flow: view-page CTA → wizard → conditional follow-ups → generate
  → sessionStorage handoff → checklist loaded in the viewer with N/A applied.
  Uses the system Chromium via `CHROMIUM_BIN` (no browser download); the test
  server is a throwaway `php -S` with `tests-e2e/router.php` for static assets.

## Authoring status

- `asd.json` — Application Security and Development STIG **V6R4** (286 rules). Draft;
  needs SME review. ~196 rules referenced (138 status-changing, 55 comment-only);
  the rest (mostly the always-applicable audit-generation cluster) stay `Not_Reviewed`.
