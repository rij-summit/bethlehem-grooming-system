# Coding Guidelines

Behavioral guidelines to reduce common LLM coding mistakes. Merge with project-specific instructions as needed.

*Tradeoff:* These guidelines bias toward caution over speed. For trivial tasks, use judgment.

## 1. Think Before Coding

*Don't assume. Don't hide confusion. Surface tradeoffs.*

Before implementing:

- State material assumptions explicitly. If ambiguity could significantly affect the implementation, explain it and ask before making a consequential assumption.
- If multiple materially different interpretations exist, present them — don't pick silently.
- If a simpler approach exists, say so. Push back when warranted.

## 2. Focused Dependency Tracing

**Start local. Expand only when necessary.**

When investigating a task:

- Start with the directly affected file, page, component, feature, or module.
- Inspect immediate dependencies such as scripts, handlers, controllers, services, or validation.
- Trace routes, APIs, models, database constraints, shared utilities, or tests only when required.
- Broaden repository search only if the relevant logic cannot be found or a dependency requires it.
- Do not let the initial scope block inspection of dependencies necessary to complete the task correctly.

## 3. Simplicity First

*Minimum code that solves the problem. Nothing speculative.*

- No features beyond what was asked.
- No abstractions for single-use code.
- No "flexibility" or "configurability" that wasn't requested.
- No speculative error handling for scenarios outside the task or established system behavior.
- If the implementation is substantially longer or more complex than necessary, simplify it.

Ask yourself: "Would a senior engineer say this is overcomplicated?" If yes, simplify.

## 4. Surgical Changes

*Touch only what you must. Clean up only your own mess.*

When editing existing code:

- Do not refactor unrelated code unless the task requires it.
- If directly affected code has a clear structural problem that makes the task harder, riskier, or unnecessarily complex, point it out.
- Apply a refactor only if it is small, contained, and directly supports the task; otherwise ask first.
- Don't "improve" adjacent code, comments, or formatting.
- Follow existing project conventions unless they conflict with the requested change or introduce a clear structural problem.
- If you notice unrelated dead code, mention it — don't delete it.

Shared Existing Behavior:

- When the requested behavior already exists elsewhere, inspect and reuse the existing implementation when practical instead of creating duplicate logic.
- If directly affected code contains separate implementations of the same behavior, consolidate them when a small, contained change can safely create a shared source of truth.
- Preserve the existing behavior of all affected consumers when consolidating shared code.
- Only remove duplicate code made unnecessary by that consolidation. Do not search for or clean up unrelated duplication elsewhere in the codebase.

When extracting shared behavior:

- Place it in the project's existing appropriate shared location when one already exists.
- If no suitable shared location exists, create a clearly named shared file in the appropriate existing project location.
- Do not create a new folder or file solely for organization if the logic is small, single-use, or naturally belongs in an existing component.

When your changes create orphans:

- Remove imports, variables, or functions made unused by your changes.
- Don't remove pre-existing dead code unless asked.

The test: Every changed line should trace directly to the user's request or be necessary cleanup caused by the requested change.

## 5. System Performance Awareness

**Avoid degrading performance with every change.**

For all implementation tasks:

 - Do not introduce unnecessary database queries, network requests, re-renders, DOM work, or repeated computations.
 - Watch for N+1 queries — use eager loading (with()) instead of querying inside a loop, whether in a controller, model accessor, or Blade @foreach.
 - Avoid changes that noticeably worsen page load or interaction performance. Treat any query added inside a loop over user-facing data as significant.
 - Reuse already-loaded data when appropriate instead of fetching the same data repeatedly.
 - Prefer paginating large result sets over loading full tables.
 - Avoid loading unrelated features or data for the current page/tab when they are not needed.
 - If the requested implementation would create a significant performance problem, explain the tradeoff briefly and propose a simpler, efficient approach before implementing.
 - Do not perform unrelated performance refactors unless the task specifically asks for optimization.

## 6. Frontend Performance

*Measure first. Optimize the actual bottleneck. Preserve behavior.*

When working on website performance, use Core Web Vitals as performance targets:

- LCP (Largest Contentful Paint): 2.5 seconds or less
- INP (Interaction to Next Paint): 200 milliseconds or less
- CLS (Cumulative Layout Shift): 0.1 or less

When investigating performance:

- Measure or inspect the current behavior before making changes.
- Identify the actual bottleneck before optimizing.
- Do not remove or change existing functionality unless required.
- Prefer the smallest change that fixes the performance issue.
- Verify that the affected feature still works after optimization.
- Compare performance before and after the change when possible.

## 7. Goal-Driven Execution

*Define success criteria. Loop until verified.*

Transform tasks into verifiable goals:

- "Add validation" → "Verify invalid inputs are rejected; add or update focused tests when appropriate"
- "Fix the bug" → "Reproduce the bug, fix it, and verify the affected behavior"
- "Refactor X" → "Verify relevant behavior before and after the change"

- Run only the smallest relevant tests/checks for the files and behavior changed; avoid unrelated or redundant verification.
- Run broader test suites or production builds only when the scope of the change warrants them.

For multi-step tasks, state a brief plan:

1. [Step] → verify: [check]
2. [Step] → verify: [check]
3. [Step] → verify: [check]

Strong success criteria let you loop independently within the approved task scope. Weak criteria ("make it work") may require clarification.

---

*These guidelines are working if:* fewer unnecessary changes in diffs, fewer rewrites due to overcomplication, and clarifying questions come before implementation rather than after mistakes.
