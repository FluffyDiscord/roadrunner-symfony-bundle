---
name: spec-driven
description: Documentation-first development methodology — clarify the spec until code generation becomes mechanical. The hard work is the documentation; code is just the printout. Triggers on "Build", "Create", "Implement", "Document", or "Spec out". Enforces three checkpoints before any code is written — Spec Gate (structural completeness), Clarity Gate (epistemic honesty: no assumptions mistaken for facts), and Adversarial Review (independent hostile critique by an agent without the author's context).
---

# Spec-Driven Development

## This is a documentation methodology, not a coding methodology

**The goal:** AI-ready documentation. When the documentation is unambiguous, code generation becomes mechanical.

> "If the docs are good enough, AI writes the code. The hard work IS the documentation. Code is just the printout."

The deliverable of phases 1 and 2 is a *specification*, not source. Source is generated from it. If you find yourself writing code before the spec is verified, you have left the methodology.

---

## The mechanism (why this works, stated honestly)

This methodology makes no promise of a fixed speed multiplier. The claim is mechanical and falsifiable:

```
Vague spec → the agent must GUESS intent → it guesses wrong some fraction
            of the time → you discover the mismatch downstream → rework loop.

Clear spec → the agent EXECUTES stated intent → mismatches are rare and
            shallow → the rework loop mostly disappears.
```

Speed is the *consequence* of removing the guess-and-revise loop, not an input you can dial in. The payoff scales with whatever rework currently costs you: a team drowning in revision cycles gains a lot; a team already writing tight specs gains little. If your rework rate is already near zero, this methodology buys you almost nothing — and that is the honest test of whether you need it.

**Where most "AI-assisted development" leaks time:** a person feeds the agent a messy or partial spec, the agent fills the gaps with plausible assumptions, the output diverges from intent, and the cost reappears as revision cycles. The leak is the gap-filling. Every gate below exists to close one class of gap before the agent ever runs.

*This mechanism is the methodology's operating hypothesis, not a measured law — the document applies its own "label your assumptions" rule to itself. It is plausible and falsifiable (measure your rework rate before and after), but it is not backed here by published data.*

---

## When this engages

This methodology activates whenever the user says **"Build"**, **"Create"**, **"Implement"**, **"Document"**, or **"Spec out"** — on any task, regardless of size. It engages fully every time those words appear; it does not stand down for "small" work. (A small task simply produces a small spec — but it still passes every gate.)

| Phrase | Scope |
|--------|-------|
| "Build [feature]" / "Create [component]" | Full methodology (Phases 1–4) |
| "Implement [system]" | Full methodology — first check whether clear docs already exist |
| "Document [project]" / "Spec out [feature]" | Phases 1–2 only (no code) |
| "Clean up docs for [X]" | Documentation Audit only |

---

## The pipeline

```
Phase 1 Strategy ─▶ Phase 2 Docs ─▶ ┌─ GATE 1: Spec Gate     (structural — is it COMPLETE & actionable?)
                                     ├─ GATE 2: Clarity Gate  (epistemic  — is it TRUE & unambiguous?)
                                     └─ GATE 3: Adversarial   (independent hostile critic attacks it)
                                            │
                                            ▼
                              Verified Specification ─▶ Phase 3 Execute ─▶ Phase 4 Maintain
```

**Know the difference between the gates — they ask different questions and miss different things:**

| Gate | Question it answers | What it catches |
|------|--------------------|-----------------|
| **Spec Gate** | "Can the agent execute this without asking questions?" | Missing sections, vague directives, unplaced content |
| **Clarity Gate** | "Will the agent mistake assumptions for facts?" | Laundered guesses, unsourced claims, hidden ambiguity |
| **Adversarial Review** | "What did the author and the author's model both miss?" | Blind spots invisible from inside the author's context |

No gate is optional. A spec that passes Spec Gate can still be confidently wrong (Clarity catches that). A spec that passes both can still share the author's blind spots (Adversarial catches that).

---

## Document type architecture

**The rule:** Not every document needs every section. Putting implementation detail into strategic documents breaks single-source-of-truth.

> "If the agent has to decide *where* to find information, you have already lost."

### Document types

| Type | Purpose | Examples |
|------|---------|----------|
| **Strategic** | WHAT and WHY | Strategic Blueprint, PRD, Vision docs, Business cases |
| **Implementation** | HOW | Technical Specs, API docs, Module specs, Architecture docs |
| **Reference** | Lookup | Schema Reference, Glossary, Configuration |

### Section placement matrix

| Section | Strategic | Implementation | Reference |
|---------|-----------|----------------|-----------|
| **Deep Links (References)** | Required | Required | Required |
| **Anti-patterns** | Pointer only | Required | N/A |
| **Test Case Specifications** | Pointer only | Required | N/A |
| **Error Handling Matrix** | Pointer only | Required | N/A |

**Wrong** (duplicates content): a Strategic Blueprint that contains its own Anti-patterns, Test Cases, and Error Matrix — each now a second copy that will drift from the Technical Spec.

**Right** (single source): the Strategic Blueprint carries strategy plus *pointers* ("Anti-patterns → Technical Spec §7"); the Technical Spec owns the anti-patterns, test cases, and error matrix.

---

## The methodology: phases and time allocation

This is the **single authoritative allocation**. Every other mention in this document points here.

| Phase | Time | Focus |
|-------|------|-------|
| Phase 1: Strategic Thinking | 40% | WHAT to build, WHY it matters |
| Phase 2: AI-Ready Documentation | 40% | HOW to build (specs so clear the agent has zero decisions) |
| *(Gates 1 & 2 run here — checkpoints, not phases)* | *0% — inside Phase 2's 40%* | Spec Gate + Clarity Gate before any execution |
| Phase 2.5: Adversarial Review | 5% | Independent hostile critic attacks the spec (Gate 3) |
| Phase 3: Execution | 10% | Code generation + integration |
| Phase 4: Quality & Iteration | 5% | Testing, refinement, divergence control |

**The logic:** spending 5% to break the spec on purpose costs less than the rework it prevents. Bulletproofing the docs adversarially makes execution *faster*, not slower — because the agent stops guessing. (Gates 1 & 2 carry no separate budget: they are checkpoints run inside Phase 2. Phase 2.5 is broken out because it pulls in a second, independent reviewer.)

> **On the numbers in this document.** The minimums and thresholds this methodology uses — the 40/40/5/10/5 split, the 9/10 bar, the 6/10 cap, "≥5 anti-patterns," "5 unit / 3 integration tests," the rubric weights — are deliberate heuristic *defaults*: floors chosen to force effort and make the gates concrete, not measured optima. Treat them as the author's starting points and override any with a one-line rationale when your domain warrants. Clarity Gate check 9 (number provenance) governs the numbers *inside your specs*; it does not pretend these methodology defaults are derived from data.

---

## PHASE 1: Strategic Thinking (40%)

### Where do you start?

```
What is the starting point?
├─ Existing docs          → Documentation Audit → then the 7 Questions
├─ Existing code, no docs → reverse-engineer a baseline spec (cite file+line),
│                           then spec the new work as a delta against it
└─ Greenfield             → straight to the 7 Questions
```

**Brownfield (existing code, no usable spec).** Reverse-engineer a baseline spec from the code first — describe current behavior with file+line citations (Clarity check 1) — then spec the new work as a delta. The 7 Questions still apply, but answers already settled by the existing system (often #3–#7) are *recorded from it as-is*, not re-litigated. Reverse-engineering the spec is Phase-2 work in its own right, not a shortcut around it.

### Documentation Audit (only if prior docs exist)

**Skip entirely when starting from scratch.** This step exists because inherited documentation accumulates cruft: aspirational statements, speculative futures, outdated decisions, duplication across files, motivational filler with no implementation value.

Apply the Audit Test to every existing document:

| Check | Question |
|-------|----------|
| **Actionable** | Can the agent act on this? If aspirational, delete it. |
| **Current** | Is this still the decision? If changed, update or remove. |
| **Single Source** | Is this said elsewhere? Consolidate to one place. |
| **Decision** | Is this actually decided? If not, do not include it — log it as an Open Question. |
| **Prompt-Ready** | Would you paste this into an agent prompt? If not, delete. |

Audit checklist:
- [ ] Removed all "vision" / "future state" language
- [ ] Deleted motivational conclusions and preambles
- [ ] Consolidated duplicated information to a single source
- [ ] Updated every outdated architectural decision
- [ ] Removed speculative features outside current scope

**Target:** report the before/after size so the reduction is a fact, not a feeling. There is no target ratio — reduction is an *output* of removing cruft, not a goal to hit, so do not pad deletions to reach a number. The one hard rule: **zero** loss of actionable information.

### The 7 Questions

Answer all seven with specificity before writing any new documentation. Vague answers produce vague code.

| # | Question | Reject | Require |
|---|----------|--------|---------|
| 1 | What exact problem are you solving? | "Help users manage tasks" | "Help [specific persona] achieve [measurable outcome] in [specific context]" |
| 2 | What are your success metrics? | "Users save time" | Numbers + timeline: "100 users, 25% conversion, 3 months" |
| 3 | Why will you win? | "Better UI and features" | Structural advantage: architecture, data moat, business model |
| 4 | What is the core architecture decision? | "Let the AI decide" | A human decides, based on an explicit trade-off analysis |
| 5 | What is the tech-stack rationale? | "Node because I like it" | A business rationale tied to constraints |
| 6 | What are the MVP features? | 10+ "must-haves" | A small essential set, the rest explicitly deferred |
| 7 | What are you NOT building? | "We'll see what users want" | Explicit exclusions with rationale |

**If you cannot answer a question at the Require level, that is an Open Question for the user — not a blank for you to fill with a plausible guess.** (See the Unknowns protocol under Clarity Gate.)

### Phase 1 exit criteria

- [ ] All 7 questions answered at Require level (or surfaced as Open Questions)
- [ ] Strategic Blueprint created
- [ ] Architecture Decision Records (ADRs) for major choices
- [ ] Zero ambiguity about WHAT you are building

---

## PHASE 2: AI-Ready Documentation (40%)

### The 4 mandatory sections (every implementation document)

Without these four, the agent guesses — and guessing is the leak this methodology exists to plug.

**Definitions.** A **component** is a unit with its own public interface and its own spec section — a class, service, module, or endpoint group with a coherent contract. An **implementation document** is the spec for one component or a cohesive group of them. The floors below are *per implementation document* (anti-patterns) and *per component* (tests); where one document covers several components, apply the per-component floors to each. If a component genuinely has fewer (e.g. a pure formatter with no external errors), record *why* — never pad to hit a floor, which would violate check 7 (no fluff).

#### 1. Anti-Patterns (DO NOT)

The agent needs to know what *not* to do.

```
## Anti-Patterns (DO NOT)

| Don't | Do Instead | Why |
|-------|-----------|-----|
| Store timestamps as native date objects | Use ISO 8601 strings | Serialization drift across boundaries |
| Hardcode configuration values | Inject via environment/config | Deployment flexibility |
| Use generic error messages | Specific code per failure | Otherwise undebuggable |
| Skip validation on internal calls | Validate everything | Internal callers have bugs too |
| Expose internal IDs in APIs | Use opaque identifiers | Security and flexibility |
```

**Rule:** at least 5 anti-patterns per implementation document — or a recorded reason why fewer genuinely apply.

#### 2. Test Case Specifications

The agent needs concrete verification criteria.

```
## Test Case Specifications

### Unit tests required
| Test ID | Component | Input | Expected output | Edge cases |
|---------|-----------|-------|-----------------|------------|
| TC-001 | Tier classifier | 100 contacts | 20–30 in Critical tier (origin: requirement R-12) | Empty list, all-equal scores |

### Integration tests required
| Test ID | Flow | Setup | Verification | Teardown |
|---------|------|-------|--------------|----------|
| IT-001 | Auth flow | Create test user | Token refresh works | Delete test user |
```

**Rule:** at least 5 unit tests and 3 integration tests per component — or a recorded reason why fewer genuinely apply.

#### 3. Error Handling Matrix

The agent needs a defined response for every failure mode.

```
## Error Handling Matrix

### External service errors
| Error type | Detection | Response | Fallback | Logging | Alert |
|------------|-----------|----------|----------|---------|-------|
| Timeout | > defined threshold | Retry N× exponential | Return cached | ERROR | If N in window |
| Rate limit | 429 | Pause + back off | Queue for retry | WARN | If over threshold |

### User-facing errors
| Error type | User message | Code | Recovery action |
|------------|--------------|------|-----------------|
| Quota exceeded | "You've used all checks this month." | 403 | Show upgrade CTA |
| Session expired | "Please sign in again." | 401 | Redirect to login |
```

**Rule:** every external-service and user-facing error must be specified. Every threshold must have a stated origin (see Clarity Gate check 9).

#### 4. Deep Links (all document types)

The agent needs to navigate to an exact location. "See the technical annex" is useless.

```
## References

| Topic | Location | Anchor |
|-------|----------|--------|
| User profiles | [Schema Reference](../schemas/schema.md#user_profiles) | `user_profiles` |
| Auth flow | [API Spec](../specs/api.md#authentication) | §3.2 |
```

**Rule:** never use a vague reference. Always include document path + section anchor.

---

## GATE 1 — The Spec Gate (structural completeness)

> **Never skip this gate.** It is the line between a spec the agent executes and a spec the agent guesses at.

A gate is passed **only when you can name the evidence**. "Looks good" is not a pass. For each item, the pass is binary, and you must point to the specific section, table, or line that satisfies it. If you cannot point to it, it fails.

### The 13 binary checks

**Foundation (7):**

| # | Check | Passes when… |
|---|-------|--------------|
| 1 | **Actionable** | Every section dictates a concrete action. No aspirational content survives. |
| 2 | **Current** | Nothing contradicts the present state of the system or the latest decision. |
| 3 | **Single Source** | No fact appears in two documents. Cross-references point; they do not copy. *(One sanctioned exception: the self-contained agent prompts in this skill restate the rubric/thresholds so they paste cleanly.)* |
| 4 | **Decision, not wish** | Every normative statement is a decision, not a hope. |
| 5 | **Prompt-Ready** | You would paste any section verbatim into an agent prompt. |
| 6 | **No future state** | No "will eventually," "might," "ideally" language remains. |
| 7 | **No fluff** | No motivational or aspirational filler remains. |

**Document architecture (6):**

| # | Check | Passes when… |
|---|-------|--------------|
| 8 | **Type identified** | The document is marked Strategic, Implementation, or Reference. |
| 9 | **Anti-patterns placed** | In implementation docs only (per the Phase 2 floor); strategic docs hold pointers. |
| 10 | **Test cases placed** | In implementation docs only; strategic docs hold pointers. |
| 11 | **Error handling placed** | Matrix in implementation docs only. |
| 12 | **Deep links present** | Every reference resolves to a path + anchor. No "see elsewhere." |
| 13 | **No duplicates** | Strategic docs use pointers, never copied content. |

### Scoring (secondary to the binary checks)

The binary checks are pass/fail and come first. The score below is a *qualitative* read used only after all of them pass.

| Criterion | Weight | 10/10 requirement |
|-----------|--------|-------------------|
| **Actionability** | 25% | Every section states a concrete implementation directive |
| **Specificity** | 20% | Every number, type, and threshold is explicit |
| **Consistency** | 15% | Single source of truth, no duplicates |
| **Structure** | 15% | Tables over prose, predictable hierarchy |
| **Disambiguation** | 15% | Anti-patterns present (see the Phase 2 floor), edge cases explicit |
| **Reference Clarity** | 10% | Deep links only, no vague references |

| Score | Meaning | Action |
|-------|---------|--------|
| 10 | Zero clarifying questions needed | Pass — proceed to Clarity Gate |
| 9 | One minor clarification remains | Not yet a pass — resolve the one item (it becomes a 10), then proceed to Clarity Gate. You never carry it forward |
| 7–8 | Several ambiguities | Major revision required |
| < 7 | Not AI-ready | Return to Phase 2 |

### Anti-gaming rules

- The score is **not self-awarded prose.** Each criterion's score must cite specific evidence from the spec ("Specificity 9/10: all 14 thresholds defined in §4, table 2"). A score with no evidence is void.
- **One unresolved ambiguity that would make a developer guess caps the total at 6/10**, regardless of how strong the other criteria are. Completeness elsewhere does not buy back a guess. The binary checks above catch *structural* ambiguity (a missing section, a vague directive, unplaced content); this cap catches *semantic* ambiguity that survives a structurally-complete spec — e.g. "retry the request a few times" is actionable, placed, and linked (it clears every binary check), yet "a few" admits two readings, so it caps the score. Use Clarity Gate check 8 to decide whether a sentence has two readings.
- If any binary check above fails, there is no score — you are not at the gate yet.
- **Irreducible ambiguity** — ambiguity that no better documentation can resolve because it depends on an unmade external decision — is not a documentation failure and does **not** trigger the cap. Record it as an Open Question / accepted risk, flag it, and escalate per the Unknowns protocol if it is user-blocking.

### Gate enforcement

```
- [ ] All 7 Foundation checks pass (evidence named for each)
- [ ] All 6 Architecture checks pass (evidence named for each)
- [ ] Score ≥ 9/10 with per-criterion evidence (a 9 is not a pass until its single open item is resolved here at the Spec Gate — never carried past it)
If any item fails → fix before Clarity Gate.
```

### AI-assisted Spec Gate (meta-prompt)

Paste a spec into a fresh agent with this prompt to have it graded:

```
ROLE: You are the Spec Gatekeeper. Ruthlessly evaluate this specification for
ambiguity, incompleteness, and guess-inducing gaps.

TASK: Grade 1–10 on this rubric, and for EACH score cite the exact section that
justifies it (no evidence = score void):
1. Actionability (25%) — every section dictates a concrete implementation detail
2. Specificity (20%) — data types, error codes, thresholds, edge cases all explicit
3. Consistency (15%) — single source of truth, no duplicates
4. Structure (15%) — tables over prose, clear hierarchy
5. Disambiguation (15%) — anti-patterns present, edge cases explicit
6. Reference Clarity (10%) — deep links only

OUTPUT:
1. Score /10
2. Per-criterion breakdown WITH cited evidence
3. Hallucination risks: list every line where a developer would have to guess
4. The Fix: rewrite the 3 most ambiguous sections into AI-ready form

THRESHOLD: 9–10 ready · 7–8 revise · <7 return to Phase 2.
Any single guess-inducing ambiguity caps the score at 6.
```

---

## GATE 2 — The Clarity Gate (epistemic honesty)

Spec Gate asks *"is it complete?"* Clarity Gate asks a different, harder question:

> **"Will the agent mistake assumptions for facts?"**

A spec can be structurally perfect and confidently wrong. This gate catches the laundered guess — the moment where something the author *assumed* is written as something the system *does*. It is the gate most likely to pass a confidently-wrong spec, because a complete spec built on a false premise produces complete, wrong code that neither the structural checks nor the tests would catch.

### The 9 epistemic checks (binary, evidence-named)

| # | Check | Passes when… |
|---|-------|--------------|
| 1 | **Evidence-backed** | Every claim about a *pre-existing* system (behavior, schema, dependency, performance) cites a source: file + line, a measurement, or a named document — no claim stated from memory. Claims about the system you are *specifying* are design decisions governed by checks 2, 4, and 9, not citations of existing behavior. |
| 2 | **Assumptions labeled** | Anything not verified is explicitly marked as an assumption — never asserted as fact. |
| 3 | **Unknowns surfaced** | Every gap the author could not resolve is listed as an Open Question for the user, not silently filled with a default. |
| 4 | **No hedging-as-decision** | "Probably," "should," "I think," "likely" appear nowhere in normative statements. Uncertainty is quarantined to the Assumptions / Open Questions list. |
| 5 | **Source freshness** | Claims about the codebase are pinned to a commit, version, or date, so a reader knows what state they describe. |
| 6 | **Falsifiable only** | No unmeasurable absolutes — "fast," "secure," "scalable," "zero bugs," "always," "never" — without a defined measurement and threshold. |
| 7 | **Irreversibility flagged** | Hard-to-undo decisions (data migration, public API shape, security model, deletes) are flagged for extra scrutiny. |
| 8 | **Single interpretation** | The operable test: if you can write two spec-conformant implementations that differ in observable output, persisted state, or external calls for at least one input, the requirement has two readings and fails until disambiguated — produce both as the evidence. |
| 9 | **Number provenance** | Every threshold, timeout, limit, quota, and size states its origin (requirement, measurement, standard) — not an invented round number. |

### The Unknowns protocol

This is the heart of the gate. When you do not know something:

1. **Do not guess and proceed.** A plausible default written as fact is the failure this gate exists to prevent.
2. **Write it down** in an **Open Questions** section: the question, why it matters, and what is blocked until it is answered.
3. **Surface it to the user.** Open Questions that affect Phase 1 (the 7 Questions) or any irreversible decision must be answered by the user before the gate passes.
4. **If you must proceed with an assumption** (lower-stakes, reversible), label it explicitly as an assumption (check 2), record what would change if it is wrong, and continue. An honest, labeled assumption is acceptable; a disguised one is not.
5. **If a user-blocking Open Question cannot be answered** (the user is unavailable, undecided, or declines): **stop and report the blocked state**, naming the specific question and what it blocks. Do not guess past it. *Only* if the user explicitly directs you to proceed without an answer, convert it to a labeled high-risk assumption (record the chosen default, the consequence if wrong, and how to reverse it) and mark the gate **passed conditionally** with that assumption flagged. A conditional pass is visible debt, not silent debt. In an unattended pipeline (no synchronous user), a user-blocking unknown is a **hard stop**: output a BLOCKED report — each unanswered question, what it blocks, and the work completed so far — then terminate. Resumption requires the user to answer and re-invoke.

### Anti-gaming rules

- "Evidence-backed" means the evidence is *real and checkable.* Citing a file that does not contain the claim is worse than no citation.
- A recalled or remembered fact is **not** evidence. If a claim names a file, function, flag, or schema, verify it still exists before the claim passes check 1. If you lack the access to verify it, you may not assert it — label it an assumption (check 2) and surface the verification as an Open Question.
- Zero Open Questions on a non-trivial spec is a red flag, not a triumph — it usually means unknowns were laundered into facts. Re-read for hidden assumptions before declaring the gate clean.

### Gate enforcement

```
- [ ] All epistemic checks pass (evidence named for each)
- [ ] Open Questions section exists; user-blocking questions are resolved or escalated (step 5)
- [ ] Every assumption is labeled as an assumption
If any check fails → fix before Adversarial Review.
```

---

## PHASE 2.5 / GATE 3 — Adversarial Review (5%)

### The hostile-critic subagent (reusable — referenced by Gate 3 and Phase 3)

Both spec review (Gate 3) and code review (Phase 3) spawn the **same kind of reviewer**. It is defined once here; point to this block from anywhere that spawns one. The artifact under review changes (a spec, then generated code); the reviewer's stance does not.

**Spawn a fresh subagent with no prior context**, hand it only the artifact + the relevant prompt, and instruct it to:
- Act as a **hostile, skeptical critic** — assume problems exist; its job is to find them, not to reassure.
- **Extend no charitable interpretation** — it did not write the artifact and owes it no benefit of the doubt.
- **Question everything:** every inconsistency, every smell, every contradiction, every weird construct, every claim made without evidence, and every place the artifact violates its *own* stated rules.
- **Report findings by severity** (CRITICAL / HIGH / MEDIUM / LOW), each with location, the precise problem, and a specific fix.
- **Invent nothing to hit a count** — a fabricated finding is worse than none; a clean category must be justified, not padded.
- Return the findings as its result (it is not writing a human-facing message).

A fresh **same-model** subagent still strips the author's *conversational* context — the hostile framing is what makes it useful. A **different provider** (Tier 2 below) or a **human** is stronger, and is required where the spec touches the irreversibility list.

### When to run

After Spec Gate and Clarity Gate both pass. Before any code generation.

**The principle:** the agent that wrote your spec shares your blind spots. Verification must come from a reviewer that does **not** share the author's context, and — where reachable — not the author's model either. A reviewer with no context extends no charitable interpretation; it finds the gaps the author normalizes. A same-model fresh subagent (Tier 1) strips the *conversational* context that biases the author, but not the *model-level* priors — so it meets the gate's floor, not its strongest intent. Treat a clean Tier-1 review as necessary, not sufficient, for high-stakes or irreversible specs.

> "When code fails, fix the spec — not the code. Phase 2.5 finds the spec failures before there is any code to fail."

### Reviewer tiers (pick the highest available; tier 1 is always available)

| Tier | Reviewer | Why |
|------|----------|-----|
| **1 — always available** | The **hostile-critic subagent** (defined above), spawned with no prior context and handed only the spec + the adversarial prompt below. Spawn a clean subagent in an agentic environment; otherwise open a brand-new session. | Removes the author's conversational context even with one model available. Never blocked. |
| **2 — gold standard** | A **frontier model from a different provider** than your primary workflow. | A different model has different blind spots and different training priors — the strongest source of independent signal. |
| **3 — highest signal** | A trusted senior human reviewer. | Highest signal, highest effort. |

**Never** use the same session that helped write the spec — it has already absorbed your assumptions. A fresh hostile context is the minimum bar; a different provider is better. (This document names model *classes*, not version numbers, on purpose — specific versions rot.)

### The adversarial prompt

```
You are a skeptical senior developer and hostile critic reviewing this
specification before it goes to an agent for execution. Assume problems exist;
your job is to find them. Do not be helpful. Do not suggest minor polish.
Attack the spec. Extend no charitable interpretation.

Search EVERY category below exhaustively:

1. LOGICAL CONTRADICTIONS — claims that conflict, numbers that don't add up,
   mutually exclusive requirements.
2. CREDIBILITY RISKS — overclaims ("zero", "always", "never", "guaranteed"),
   unverifiable statements, claims a hostile reader would challenge.
3. IMPLICIT DEGREES OF FREEDOM — points where the agent must CHOOSE between
   valid interpretations; anywhere two developers would build it differently.
4. MISSING CONSIDERATIONS — unhandled error states, concurrency/races,
   external dependencies with no fallback, unstated security assumptions.
5. DEFENSIBILITY GAPS — what would a hostile reviewer use to debunk this? what
   would a junior get wrong from this spec? what happens when the happy path fails?
6. EPISTEMIC GAPS — claims about the existing system with no cited evidence;
   assumptions written as facts; round numbers with no stated origin.

OUTPUT — for each issue:
  [SEVERITY] — title
  Location: where in the spec
  Problem: what exactly is wrong
  Fix: the specific rewrite needed

SEVERITY:
  CRITICAL — execution will fail or produce wrong output without this fix
  HIGH     — significant risk of incorrect implementation
  MEDIUM   — minor ambiguity, lower risk
  LOW      — polish, not blocking

DISCIPLINE: Report exactly what you find. Do NOT invent issues to hit a count.
For any category where you find nothing, state explicitly what you checked and
why it holds — a clean category must be justified, not assumed. If the spec is
genuinely strong, say so and show the evidence per category.
```

> **Note on findings counts:** an adversarial review is judged by the *thoroughness of the search*, not by a quota of issues. A prompt that demands "find at least N criticals" manufactures false positives and trains you to distrust the reviewer. Demand coverage and justification instead.

### Gate enforcement

```
- [ ] Every category was searched; clean categories are justified, not skipped
- [ ] Zero CRITICAL issues remain
- [ ] Severity was assigned BEFORE the fix/accept decision; any HIGH downgraded
      from a candidate CRITICAL records why. A reviewer-assigned CRITICAL may be
      downgraded only with a second reviewer's sign-off (Tier 2 or human) — never
      by the author alone; otherwise it stands and is binding
- [ ] Every HIGH has an explicit decision: fix now / accept risk / defer (recorded).
      "Accept risk" on anything touching the irreversibility list (Clarity check 7)
      needs Tier-2 or human sign-off
- [ ] If the spec touches the irreversibility list, Tier 1 alone does NOT pass this
      gate — a Tier-2 (different provider) or Tier-3 (human) review is required
- [ ] Spec Gate AND Clarity Gate re-run if any CRITICAL was fixed (a fix can break either)
- [ ] A CRITICAL from any gate or reviewer is binding regardless of other scores.
      One iteration = re-run Spec + Clarity (and re-run Gate 3 too if the fix added
      materially new spec content). If two iterations cannot clear it, stop and
      escalate to a human — do not loop
```

---

## PHASE 3: Execution (10%)

### The generate–verify–integrate loop

```
1. GENERATE — feed the verified spec to the agent → receive code.
2. VERIFY   — run tests; check output against the spec. Then ATTACK the code:
              spawn the hostile-critic subagent (defined in Phase 2.5) against
              the code — question every code smell, weird construct, silent
              interface change, and inconsistency with the spec. Tests prove
              only what they cover; the critic catches the rest.
              Matches the spec and survives the critic? → continue.
              Fails the spec? → fix the SPEC first, then regenerate.
              Critic flags a code-only smell the spec did not cause and should
              not encode? → regenerate the minimal unit; if it persists it is a
              tooling/seed issue — record it, do not contort the spec to hide it.
3. INTEGRATE — commit; update documentation if the spec itself changed.
```

### Generation is not deterministic

Regenerating from an *unchanged* spec can produce different code and reintroduce or relocate bugs. So: regenerate the **minimal** unit, diff the new output against the previous generation, and treat any change *outside* the spec section you edited as a regression to investigate — not silently accept. Pin temperature/seed where the tool allows. Cap the cycle: if two regenerations do not clear the spec-and-critic check, stop and escalate — a third failure means the problem is the spec, the tooling, or the model, not something more regeneration will fix.

Passing all gates lowers but does not eliminate the need to **read** the generated code. A green test suite is not proof of correctness — the spec and its tests can share the same blind spot (which is exactly why the hostile-critic pass above exists). "Code is just the printout" describes the *goal*, not a licence to skip review.

### The golden rule

> **When code fails, fix the spec — not the code.**

If generated code does not work:
1. Do **not** silently patch the code.
2. Ask: "What was unclear in my spec?"
3. Fix the spec.
4. Regenerate.

Manual patches create divergence between spec and reality. Divergence compounds until the spec is fiction and you are back to manual development. (The one disciplined exception is defined in Phase 4.)

---

## PHASE 4: Quality & Iteration (5%)

### The Rule of Divergence

> **Every time you edit generated code without updating the spec, you create Divergence. Divergence is technical debt that silently disables regeneration.**

If you fix a bug in code but not the spec, you can never regenerate that module without reintroducing the bug. The stream is broken.

| Scenario | Wrong | Right |
|----------|-------|-------|
| Bug in generated code | Patch the code | Fix the spec, regenerate |
| Missing edge case | Add a code patch | Add it to the spec, regenerate |
| Performance issue | Optimize the code in place | Document the constraint in the spec, regenerate |
| "Quick fix" | "Just this once…" | Fix the spec |

### The one exception (logged and reconciled)

The rule above is the default and holds in nearly every case. There is exactly **one** legitimate exception: a **production emergency** — a defect *actively* causing user-facing failures or data loss in a live environment, where the regenerate-from-spec cycle is too slow to stop the harm — or a defect that is **not caused by the spec** (an upstream framework/library bug, an environment quirk). "Urgent-feeling" is not an emergency; the bar is live harm. Dogmatically refusing an obvious fix, or silently abandoning the methodology, are both worse than a disciplined exception.

When the exception applies:
1. **Make the minimal code fix** to stop the bleeding.
2. **Log it immediately** as a Divergence entry — date, what changed, why it bypassed the spec.
3. **Reconcile before the branch merges** (end of day at the latest): either update the spec so regeneration reproduces the fix, **or** — if the fix is a workaround for an external bug that should not live in the spec — record it as a tracked workaround with a link to where its removal is tracked.

**Logged-and-reconciled divergence is acceptable. Silent divergence is the debt.** The test: could a teammate regenerate this module from the spec today and get correct, current behavior? If not, you have unreconciled divergence.

A minimal Divergence Log entry:

```
## Divergence Log
| Date | Module | Code change | Reason spec was bypassed | Reconciliation |
|------|--------|-------------|--------------------------|----------------|
| YYYY-MM-DD | Auth | Added null guard in token refresh | Upstream SDK returns null undocumented | Tracked workaround → ISSUE-123; remove when SDK fixed |
```

### The "Day 2" workflow

1. **Isolate** the specific module — not the whole app.
2. **Re-verify the evidence** — confirm the spec's cited sources still resolve at current HEAD; if the pin is behind, re-check the claims and update it. Evidence drift is as real as code drift.
3. **Update the spec** — add the new edge case, requirement, or fix.
4. **Regenerate** the module from the updated spec.
5. **Verify integration** — run the suite for regressions.

This costs a few minutes more than a hotfix and keeps the spec and reality from drifting apart.

---

## Response protocol

When a trigger fires:

1. **Check for existing docs:** "Do you have existing documentation for this?"
2. **If existing docs:** "Let's run a Documentation Audit to clean them first."
3. **If Phase 1 incomplete:** "Before building, let's clarify strategy." → the 7 Questions.
4. **If Phase 2 incomplete:** "Before coding, let's make the docs AI-ready." → the 4 mandatory sections.
5. **At the gates:** run Spec Gate, then Clarity Gate, naming evidence for each. Report the result honestly, including Open Questions.
6. **If a gate fails:** "The spec fails [specific checks]. Let's fix [specifics] before proceeding."
7. **Before code:** run Adversarial Review. "Zero CRITICALs remain — generating the implementation."
8. **When maintaining:** "Is this change spec-conformant? Let's update the spec first." (Or invoke the logged exception, if it genuinely applies.)

---

## The contract

### You must

**Documentation Audit (if prior docs exist):**
- [ ] Run the Audit Test on all existing documentation
- [ ] Remove aspirational / future-state language
- [ ] Consolidate duplicates to a single source
- [ ] Report the measured before/after reduction

**Phase 1:**
- [ ] Answer all 7 questions at Require level, or surface them as Open Questions
- [ ] Create the Strategic Blueprint with Implementation Implications
- [ ] Write ADRs for major architectural decisions

**Phase 2:**
- [ ] Identify each document's type
- [ ] Add the 4 mandatory sections to every implementation doc
- [ ] Add deep links to every document
- [ ] Use pointers, never duplicates, in strategic docs

**Spec Gate:**
- [ ] Pass every Spec Gate binary check, naming evidence for each
- [ ] Score ≥ 9/10 with per-criterion evidence (a 9 is resolved to a 10 here, not carried forward)

**Clarity Gate:**
- [ ] Pass every Clarity Gate epistemic check, naming evidence for each
- [ ] Maintain an Open Questions section; resolve or escalate user-blocking ones
- [ ] Label every assumption as an assumption

**Adversarial Review:**
- [ ] Submit to a hostile reviewer without the author's context (tier 1 minimum; Tier 2 or human required if the spec touches the irreversibility list)
- [ ] Search every category; justify clean ones
- [ ] Fix all CRITICAL issues; record an explicit decision on every HIGH
- [ ] Re-run Spec Gate and Clarity Gate if any CRITICAL was fixed

**Phase 3–4:**
- [ ] Show generated code before writing files
- [ ] Attack the generated code with a fresh hostile-critic subagent, then run quality gates (lint, type, test)
- [ ] When code fails: fix the spec, regenerate
- [ ] Never create *silent* divergence — log and reconcile the one exception

### You cannot

- Build on existing docs without a Documentation Audit
- Skip to coding without verified docs
- Accept vague specs ("handle errors appropriately")
- Skip any of the three gates — even on docs you wrote yourself
- State an assumption as a fact, or fill an unknown with a silent default
- Guess past an unanswered user-blocking Open Question (stop and escalate instead)
- Put anti-patterns / test cases / error handling in strategic docs
- Use vague references ("see the technical annex")
- Duplicate content across document types
- Iterate on code when the problem is in the spec
- Edit code without logging and reconciling the divergence

---

## Templates

### Strategic document

```
# [Title] (Strategic)

## 1. [Section]
[Strategic content]
**Implementation Implication:** [Concrete effect on code/architecture]

## N. References

### Implementation detail lives here
| Content | Location |
|---------|----------|
| Anti-patterns | [Technical Spec §7](path#anchor) |
| Test Cases | [Testing Doc §3](path#anchor) |
| Error Handling | [Error Handling Doc](path#anchor) |

### Open Questions
| Question | Why it matters | Blocks |
|----------|----------------|--------|
| … | … | … |

*Strategic overview only. Implementation specs live in the linked documents.*
```

### Implementation document

```
# [Title] (Implementation)

**Source pinned to:** commit / version / date

## 1. [Section]
[Technical detail]

## Assumptions
| Assumption | If wrong, then… |
|------------|-----------------|
| … | … |

## Open Questions
| Question | Why it matters | Blocks |
|----------|----------------|--------|
| … | … | … |

## N-3. Anti-Patterns (DO NOT)
| Don't | Do Instead | Why |
|-------|-----------|-----|
| … | … | … |

## N-2. Test Case Specifications
### Unit tests
| Test ID | Component | Input | Expected output | Edge cases |
|---------|-----------|-------|-----------------|------------|
### Integration tests
| Test ID | Flow | Setup | Verification | Teardown |
|---------|------|-------|--------------|----------|

## N-1. Error Handling Matrix
### External service errors
| Error type | Detection | Response | Fallback | Logging | Alert |
|------------|-----------|----------|----------|---------|-------|
### User-facing errors
| Error type | User message | Code | Recovery action |
|------------|--------------|------|-----------------|

## N. References
| Topic | Location | Anchor |
|-------|----------|--------|
| … | [Path](path#anchor) | `anchor` |
```

---

## Quick reference (index — definitions live above, this only points)

- **Pipeline:** Strategy → Docs → Spec Gate → Clarity Gate → Adversarial Review → Execute → Maintain.
- **Time allocation:** see *The methodology* table. One source — not restated here.
- **Spec Gate** (structural — *is it complete?*): binary checks + evidence-backed score. See *Gate 1*.
- **Clarity Gate** (epistemic — *is it true?*): binary checks + Unknowns protocol. See *Gate 2*.
- **Adversarial Review** (independent — *what did we both miss?*): tiered hostile reviewer + the prompt. See *Phase 2.5*.

### Mantras

1. "Documentation IS the work. Code is just the printout." *(the goal, not a licence to skip review)*
2. "When code fails, fix the spec — not the code."
3. "A complete spec built on a false premise produces complete, wrong code."
4. "If the agent has to decide where to find information, you have already lost."
5. "An honest, labeled assumption is fine. A disguised one is the bug."

---

## Changelog

| Version | Changes |
|---------|---------|
| 3.0 | Initial Stream Coding methodology |
| 3.1 | Clearer terminology; mandatory gate |
| 3.3 | Document-type-aware placement of anti-patterns / test cases / error handling |
| 3.3.1 | Corrected time allocation; added Phase 4; added the Rule of Divergence |
| 3.4 | Complete 13-item gate; scoring rubric with weights; mandatory section templates; Documentation Audit folded into Phase 1 |
| 3.5 | Phase 2.5 Adversarial Review added; internal checklist renamed to "Spec Gate"; two-gate pipeline named (but the second gate pointed to a tool that did not exist) |
| 4.0 | **Hardening pass.** Clarity Gate inlined as a defined 9-check epistemic gate (it was a dangling reference to a non-existent tool) — three gates now fully self-contained. Velocity claims reframed as a falsifiable mechanism (removed fixed multipliers). Spec Gate checks made binary and evidence-backed, with explicit anti-gaming rules. Adversarial Review made always-executable via a no-context subagent tier and de-versioned (model *classes*, not version numbers); the perverse "find ≥2 CRITICAL" quota replaced with exhaustive-search-and-justify. Added the Unknowns / Open-Questions protocol. Added one logged-and-reconciled exception to the no-divergence rule. De-duplicated the rubric / time allocation / gate to a single source. Version tags stripped from the title, description, and body prose (kept here and in the footer only). |
| 4.1 | **Self-applied adversarial pass (ran Gate 3 on this document).** Reduced the Spec Gate 9/10 "proceed vs. fix" ambiguity; added an *On the numbers* note so the methodology's own heuristic thresholds stop violating its number-provenance rule; defined **component** / **implementation document** so the headline floors are measurable; added a stall protocol for unanswerable Open Questions (no more permanent deadlock) plus a Spec Gate escape valve for irreducible ambiguity; clarified the 6/10 cap (structural vs. semantic ambiguity) and made the two-readings test operable; noted a same-model Tier-1 reviewer removes context but not model priors; added a binding-CRITICAL / loop-limit rule and severity-before-decision to Adversarial Review; addressed regeneration non-determinism and the "read the generated code" caveat; made the hostile-critic subagent explicit in Gate 3 and extended it to code review in Phase 3; standardised naming (Strategic Blueprint, Audit Test) and de-duplicated gate counts and the time split. |
| 4.2 | **Second self-applied adversarial pass (fresh Tier-1 critic on v4.1).** Fully reconciled the 9/10 rule across the score table, gate enforcement, and contract (a 9 is resolved at the Spec Gate, never carried forward). Added an irreversibility carve-out so a high-stakes spec cannot pass on a Tier-1 same-model review alone. Made the implementation template's Error Handling Matrix match §3 (both sub-tables + the Alert column). Anchored the "two readings" test to observable output / persisted state / external calls. Clarified what one re-run iteration counts and capped the Phase-3 regenerate loop. Added a brownfield entry branch (reverse-engineer a baseline spec, then spec the delta) and scoped Clarity check 1 to pre-existing systems. Added a Phase-3 branch separating generation-artifact smells from spec defects. Defined "production emergency" and the reconcile deadline. Closed the severity self-downgrade loophole. Defined unattended-pipeline blocked-state behavior. Sanctioned the meta-prompts' rubric restatement as the one explicit exception to single-source. |
| 4.3 | Extracted the **hostile-critic subagent** into one reusable, universal block at the top of Phase 2.5, referenced by both Gate 3 (spec review) and Phase 3 (code review) — spawned-agent behavior is now single-source guidance instead of inline repetition. |

---

*Based on Stream Coding by Francesco Marinoni Moretto (github.com/frmoretto/stream-coding), CC BY 4.0.*
*This is a locally hardened adaptation (v4.3); the changes are listed in the Changelog above.*
