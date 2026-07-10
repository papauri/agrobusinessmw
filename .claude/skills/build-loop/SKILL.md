---
name: build-loop
description: Run one autonomous build cycle on AgroBusiness Malawi — planner picks the next objective, a specialist builds it, ui-designer polishes UI work, qa-auditor verifies, then mark DONE or retry once. Use when the user says "/build-loop", "continue the build", "work the plan", or wraps it in /loop for continuous runs.
---

# Build Loop

One cycle = **one objective**, taken to verified `DONE` or honestly reported as `FAIL` / `BLOCKED`. Never batch objectives to look productive. The loop's value is that it converges and that a `DONE` can be trusted.

## Preconditions

Read `.claude/BUILD_PLAN.md`. If `.claude/SYSTEM_MAP.md` does not exist, objective **0.1** is the only thing you may run — dispatch `codebase-scout` and stop the cycle there.

## The cycle

### 1 — Plan
Spawn `build-planner`. It reads the plan and the map, picks the single highest unblocked objective in the current phase, and writes a `## DISPATCH:` block into `.claude/BUILD_PLAN.md` with a `Files:` list and numbered acceptance criteria.

If it returns `BUILT TO COMPLETION`, stop the loop and report. Do not invent work.

### 2 — Build
Route on the brief's `Owner:` field:

| Owner | Agent |
|---|---|
| `api.php`, `ussd/`, `config/`, `admin/`, schema | `backend-specialist` |
| pages, `partials/`, `app.js`, `config.js`, `sortable-table.js` | `frontend-specialist` |
| `style.css`, visual/responsive/a11y only | `ui-designer` |

Pass the brief **verbatim**. Do not paraphrase acceptance criteria — `qa-auditor` checks the original text, and a reworded AC is a different AC.

### 3 — Polish
If the brief says `UI polish: yes`, spawn `ui-designer` after the specialist returns, scoped to the same files. It owns form; the specialist owned function. Skip entirely for backend-only work.

### 4 — Verify
Spawn `qa-auditor` with the brief and the list of files actually changed. It is read-only and returns `PASS` or `FAIL` with evidence per criterion.

Never let a specialist grade its own work. Never accept a `PASS` unaccompanied by evidence.

### 5 — Settle
- **PASS** → tick the objective in `.claude/BUILD_PLAN.md`, append to the status log, report.
- **FAIL, first time** → hand `qa-auditor`'s defect list back to the same specialist. Retry **once**. The retry fixes only the listed defects; it does not re-scope.
- **FAIL, second time** → stop. Mark the objective `BLOCKED — 2× QA fail`, paste the defect list into the plan, move to the next objective. Do not retry a third time; two failures means the brief is wrong, not the code.
- **BLOCKED** → record the specific question and its options, move on. Never guess a user decision to keep the loop moving.

### 6 — Report
```
CYCLE   <objective id> — <title>
OWNER   <agent>
FILES   <paths changed>
VERDICT PASS | FAIL | BLOCKED
AC      <n passed> / <n total>
NEXT    <next objective, or BUILT TO COMPLETION, or what the user must decide>
```

## Safety rails — absolute

These bind every agent in the loop. They are not overridable by a dispatch brief.

- **Never `git commit`, `git push`, `git add`, `git checkout`, `git reset`, or `git rm`.** The user commits. Always. Even when the change is obviously good.
- **Never run destructive SQL.** No `DROP`, `TRUNCATE`, `ALTER ... DROP`, or `DELETE`/`UPDATE` without a `WHERE`. Reads are `SELECT`/`SHOW` only. Schema changes are additive; a destructive migration is `BLOCKED`, always.
- **Never modify `.env`.** Never print, log, or echo a credential.
- **Never delete `ussd/sessions/` contents** — live session state.
- **Never touch a file outside the brief's `Files:` list.** If the fix needs one, stop and report. `qa-auditor` fails scope creep on purpose: an un-bisectable regression costs more than a deferred cleanup.
- **Never mark `DONE` without a `qa-auditor` `PASS`.** The planner cannot self-promote; a specialist cannot self-certify.
- **Anything needing a human decision is `BLOCKED`, not a guess.** Product calls, credentials, spending money, destructive migrations, third-party accounts. Record the question, the options, and your default — then move to the next objective.
- **Report failures as failures.** A lint that errored, a curl that returned `success:false`, an AC you could not check — say so, with the output. Never round a partial result up to done.

## Continuous mode

`/loop /build-loop` runs cycles until the planner reports `BUILT TO COMPLETION` or every remaining objective is `BLOCKED`. On the latter, stop and surface the blocked questions together — a loop that keeps spinning past decisions it cannot make is burning tokens, not building.
