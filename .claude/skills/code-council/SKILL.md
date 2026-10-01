---
name: code-council
description: "Run a code change, bug, perf problem, or architecture decision through a council of engineering reviewers. Unlike a debate of opinions, for code with ground truth it VERIFIES claims by running tests, reproducing bugs, and adversarially refuting findings — confidence is earned by reproduction, not by tone. Two modes: REVIEW/DEBUG (execution-grounded, default when there is a diff / runnable code / a reproducible bug) and DESIGN (perspective debate, for forward-looking architecture tradeoffs with no runnable artifact yet). MANDATORY TRIGGERS: 'code council this', 'council this code', 'council this PR', 'council this diff', 'review council', 'pressure-test this code', 'pressure-test this PR'. STRONG TRIGGERS (use when there is real code/stakes): 'is this design right', 'should I use X or Y architecture', 'why is this slow', 'find the bug', 'is this safe', 'review this change for blind spots', 'what will break', 'stress-test this implementation'. Do NOT trigger on trivial one-liners, formatting, obvious fixes, or factual lookups — just do those. DO trigger when a change/decision has real stakes, multiple plausible failure modes, or genuine architectural tradeoffs."
---

# Code Council

A single review pass collapses the angles a piece of code needs. You read it once, it looks fine, you ship the bug.

The council fixes this by running the code through several independent reviewers, each anchored to a different engineering concern. But code is not a business decision — it has **ground truth**. It compiles or it doesn't. The test passes or it doesn't. The bug reproduces or it doesn't. So this council does NOT just debate opinions. For anything that can be checked, it **verifies**: it grounds every claim in the actual repo, tries to *refute* each finding, and earns confidence through reproduction rather than rhetoric.

This is adapted from the LLM Council idea (Karpathy / Ole Lehmann), but the primitive is changed for code: **find → adversarially verify → synthesize**, where "verify" means running code, not winning an argument.

---

## the two modes

Pick the mode before doing anything else.

- **Review / Debug mode (default for existing code).** There is a diff, runnable code, or a reproducible bug — i.e. ground truth exists. Use this for PR review, "find the bug", "why is this slow", "is this safe", "what will this break". Verification-driven.
- **Design mode.** A forward-looking decision with genuine tradeoffs and **no runnable artifact yet** ("queue vs cron", "monolith vs service", "should the API be sync or async"). Nothing to execute, so this falls back to a perspective debate — but with engineering lenses and an explicit de-risking step.

**How to choose:** if there is a diff / code to run / a bug to reproduce → Review mode. If it's "should we do X or Y" with nothing to execute → Design mode. If genuinely ambiguous, ask exactly one clarifying question, then proceed.

Do NOT convene the council for trivial changes (one-liners, renames, formatting, obvious fixes). Just do them.

---

## shared setup (both modes)

Before convening anyone, ground yourself in the real repository. The user's prompt is the tip of the iceberg; the answer is in the code.

1. **Determine scope.** Default to the diff (`git diff`, `git diff main...HEAD`) or a user-specified path. Do NOT review the whole repo unless explicitly asked. State the scope you chose.
2. **Read the actual code.** Use Glob/Grep/Read to open the changed files, trace callers/usages of changed symbols, and read the relevant tests. Reviewers must reason about real `file:line`, not the prompt.
3. **Find the project's checks.** Locate the test / typecheck / lint / build commands (look in `package.json`, `Makefile`, `pyproject.toml`, `CLAUDE.md`, CI config). Note them — verification needs them.
4. **Read context files** if present and relevant: `CLAUDE.md`, a `memory/` folder, the spec/issue the change addresses. Spend ~30s, grab the 2–3 files that matter.

**Cost discipline:** use a fast/cheap model (Haiku or Sonnet) for the broad finder sweep, and reserve the strong model (Opus) for verification and synthesis. Spawn parallel sub-agents with an explicit `model` override.

---

## MODE A — Review / Debug (execution-grounded)

### step 1: convene the finders (parallel sub-agents)

Spawn finders simultaneously, one per engineering lens. Diversity comes from **different evidence**, not different temperament — each lens is told which evidence to prioritize. Use a cheap/fast model here.

| Lens | Primary concern | Evidence to read first |
|---|---|---|
| **Correctness** | edge cases, null/empty, off-by-one, error paths, races | the spec/requirements + the changed logic + existing tests |
| **Security** | injection, authz, secrets, unsafe deserialization, SSRF | input boundaries, auth checks, external calls, env/secret use |
| **Performance / complexity** | algorithmic complexity, N+1, allocations, hot paths | loops, queries, I/O in hot paths, data sizes |
| **Maintainability / API design** | coupling, naming, "pain in 6 months", fit with conventions | surrounding code, existing patterns, public API surface |
| **Integration / blast radius** | who else this breaks, back-compat, migrations | callers/usages of changed symbols (grep), schemas, contracts |

Each finder MUST:
- actually read the code (cite `file:line` for every finding),
- output **structured findings**, each with: `severity` (critical/high/medium/low), `location` (file:line), `claim` (a single falsifiable statement), and `how_to_verify` (a concrete way to prove it — a test, an input, an execution),
- NOT hedge and NOT pad with style nits dressed as bugs. A finding is a claim that something is wrong, not a preference.

**Finder prompt template:**
```
You are the [LENS] reviewer on a code council. Scope: [diff / paths].
Project check commands: [test/typecheck/lint commands].

Read the actual code (open files, grep for callers of changed symbols, read tests).
Find concrete problems through the lens of [LENS]. Prioritize this evidence: [evidence].

For EACH finding output:
- severity: critical | high | medium | low
- location: file:line
- claim: one falsifiable sentence (what is wrong and why)
- how_to_verify: a concrete repro — an input, a test to write, or a command to run

Cite file:line for everything. Do not report style preferences as bugs.
If you find nothing real through your lens, say so explicitly.
```

### step 2: dedup

Collect all findings and merge duplicates that point at the same root cause / `file:line`. This is plain reasoning (or one cheap sub-agent), not a debate. Keep the clearest statement of each unique finding.

### step 3: adversarial verification (pipeline, one chain per finding)

This is the core. For each unique finding, spawn K skeptics (default K=2, use 3 for critical/high) whose explicit job is to **refute** it — not to confirm it. Use the strong model here.

Each skeptic tries, in order:
1. **Reproduce / prove it:** write a failing or reproducing test, or construct a concrete input that triggers the bug, and run the project's checks if possible.
2. **Refute it:** show the path is unreachable, the input is impossible, the guard already exists, or the claim misreads the code (with `file:line`).

A finding's status becomes:
- **CONFIRMED** — reproduced (ideally with a test/input that demonstrates it) and survives refutation.
- **HYPOTHESIS** — plausible but could not be reproduced; needs human eyes. (Default uncertain findings here, not to CONFIRMED.)
- **REFUTED** — shown to be wrong or unreachable; dropped.

> Confidence is earned by reproduction, not by tone. A confidently-worded claim with no repro is a HYPOTHESIS, not a finding.

**Skeptic prompt template:**
```
You are a skeptic verifying a code-council finding. Default to skepticism.

Finding:
- claim: [claim]
- location: [file:line]
- suggested repro: [how_to_verify]

Your job is to REFUTE this. First try to actually reproduce it: write a reproducing/
failing test or construct a triggering input, and run [check commands] if you can.
Then try to break the claim: is the path reachable? does a guard already handle it?
does the claim misread the code? Cite file:line.

Output:
- verdict: confirmed | hypothesis | refuted
- evidence: the test/input/command you ran and its result, OR why it cannot occur
- reproduction: the test or input if you produced one
```

A finding is CONFIRMED only if it reproduces and a majority of skeptics fail to refute it.

### step 4: loop until dry (optional, for thorough audits)

If the user asked for a thorough/exhaustive review, repeat steps 1–3 with fresh finders until N consecutive rounds (default N=2) surface no new CONFIRMED or HYPOTHESIS findings. Dedup new findings against everything already seen (not just confirmed ones — otherwise refuted findings keep reappearing). If you cap rounds, **say so** — don't present a bounded sweep as exhaustive.

### step 5: chairman synthesis (evidence-weighted)

One strong-model agent gets the scope, all findings with their verdicts and reproductions, and produces the verdict. Rank by **severity × confidence**, where confidence is earned by reproduction. The chairman may overrule a finder, but must justify it with evidence, not rhetoric.

**Output structure:**
```
## Confirmed (ranked by severity)
For each: severity · file:line · what's wrong · the reproduction (test/input) · the fix.

## Hypotheses (unverified — need a human)
Plausible findings that could not be reproduced, and what would confirm or kill each.

## Considered & dropped
One line each for refuted findings, so the user knows it was looked at, not missed.

## Recommended fixes
Concrete, in priority order. The single most important thing to fix first.
```

### step 6: artifacts

Produce, in the workspace:
- **`council-review-[timestamp].md`** — the ranked findings above (markdown, scannable, with `file:line`).
- **Reproducing tests** that were created during verification — written to the repo's test location (so they fail now and pass after the fix).
- **A patch** — if the user wants it, apply the recommended fixes to the working tree (`--fix` style) or emit a diff. Ask before mutating files unless the user already said to fix.

(Pass a real timestamp in — the skill cannot generate one itself; ask the system / use the current date.)

---

## MODE B — Design / Architecture (perspective debate)

No runnable artifact yet, so this is a debate — but with engineering lenses, grounded in the existing codebase, and ending with a de-risking step. Still read the relevant existing architecture (shared setup) before convening.

### step 1: frame the decision
Reframe the user's question as one neutral prompt every advisor receives: the core decision, the options on the table, real constraints from the codebase (current stack, scale, team), and what's at stake. Don't steer it. If too vague, ask one question.

### step 2: convene 5 advisors (parallel)
Engineering thinking lenses that create real tension:
1. **First Principles** — "what are we actually solving?" Strip assumptions; maybe it's the wrong question.
2. **Simplicity / YAGNI** — push back on over-engineering; what's the smallest thing that works?
3. **Scale & Future** — does this survive 10×? Where does it break under load/growth?
4. **Operability** — can you debug, monitor, deploy, and roll this back at 2am? Failure modes.
5. **Reversibility** — one-way vs two-way door. Migration cost. What locks us in?

Each: 150–300 words, lean fully into the lens, no hedging.

### step 3: peer review (parallel, anonymized)
Anonymize the 5 responses as A–E (randomize the mapping). Each reviewer answers: which is strongest and why; which has the biggest blind spot; what did ALL of them miss? Anonymization prevents deferring to a favored lens.

### step 4: chairman verdict
De-anonymize, synthesize:
```
## Where the council agrees   (high-confidence, independently converged)
## Where it clashes           (real disagreements — present both sides, don't smooth over)
## Blind spots caught         (surfaced only in peer review)
## Recommendation             (a real answer, not "it depends")
## De-risk it                 (the cheapest experiment / spike that would falsify the decision before committing, + the one thing to do first)
```
The chairman may side with a strong dissenter over the majority if the reasoning is better.

### step 5: artifacts
Save `council-design-[timestamp].md` with the framed decision, all advisor responses, peer reviews (mapping revealed), and the verdict.

---

## important notes

- **Scope to the diff/path by default.** Never silently review the whole repo.
- **Review mode verifies; design mode debates.** Don't apply persona-debate to code that can be run, and don't demand reproduction for a decision with no artifact yet.
- **Confidence is earned by reproduction**, not by confident wording. Unreproduced → HYPOTHESIS.
- **Spawn finders/advisors in parallel.** Anonymize design-mode peer review.
- **Cheap model for breadth, strong model for verification & synthesis.** Pass an explicit `model` override per sub-agent.
- **No silent caps.** If you bounded rounds, top-N findings, or skipped a check, say so.
- **Don't council the trivial.** One-liners, formatting, obvious fixes: just do them.
- **Large scopes → run it as a Workflow.** For big audits/migrations, this maps cleanly onto a workflow script: fan-out finders → pipeline into adversarial verifiers → synthesize. Offer that instead of hand-spawning dozens of agents.

---

Methodology: LLM Council by [Andrej Karpathy](https://x.com/karpathy); Claude Code adaptation inspired by [@olelehmann](https://x.com/olelehmann). Code-council variant: execution-grounded find → adversarially verify → synthesize.
