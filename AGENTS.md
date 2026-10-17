# Coding Guidelines

Behavioral guidelines to reduce common LLM coding mistakes. Merge with project-specific instructions as needed.

_Tradeoff:_ These guidelines bias toward caution over speed.

- No features beyond what was asked.

## 1. Think Before Coding

_Don't assume. Don't hide confusion. Surface tradeoffs._

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

_Minimum code that solves the problem. Nothing speculative._

- No features beyond what was asked.
- No abstractions for single-use code.
- No "flexibility" or "configurability" that wasn't requested.
- No speculative error handling for scenarios outside the task or established system behavior.
- If the implementation is substantially longer or more complex than necessary, simplify it.

Ask yourself: "Would a senior engineer say this is overcomplicated?" If yes, simplify.

## 4. Surgical Changes

_Keep changes focused, but improve directly related structure when doing so safely reduces duplication or scattered behavior._

When editing existing code:

- Do not refactor unrelated code merely because it could be improved.
- Start with the directly affected area, then inspect related consumers and implementations when necessary to understand the requested behavior.
- If the requested behavior is duplicated, scattered, or implemented inconsistently across related files, prefer consolidating it into an appropriate shared source of truth when the consolidation is reasonably scoped and can preserve existing behavior.
- Related duplication may be cleaned up even when it spans multiple files, provided those files implement or consume the same behavior relevant to the current task.
- Do not limit a correct consolidation only to the first file encountered if doing so would leave other active implementations of the same behavior inconsistent.
- Do not turn a focused task into a repository-wide cleanup. Stop expanding once the directly related implementations and consumers are covered.
- If consolidation would require a broad architecture migration, large rewrite, or changes across unrelated subsystems, do not perform it implicitly. Identify the larger refactor separately.
- Don't "improve" unrelated code, comments, formatting, or naming.
- Follow existing project conventions unless they conflict with the requested change or create a clear structural problem.
- If you notice unrelated dead code or unrelated duplication, mention it instead of removing it.

### Shared Existing Behavior

When the requested behavior already exists elsewhere:

- Inspect and reuse the existing implementation when practical instead of creating another implementation.
- Search sufficiently to determine whether the same task-related behavior already has multiple implementations.
- When multiple related implementations can safely share one source of truth, consolidate them rather than adding another variation.
- Preserve the behavior of all affected consumers during consolidation.
- Remove only duplication made unnecessary by that consolidation.
- Prefer an existing shared location when one is already appropriate.
- If no suitable shared location exists, create a clearly named shared file only when multiple real consumers justify it.
- Do not create abstractions, files, or folders for hypothetical future reuse.

The goal is incremental improvement: each task should leave the directly affected area at least as maintainable as before and, when practical, less duplicated or scattered without requiring a large unrelated refactor.

### When Your Changes Create Orphans

- Remove imports, variables, functions, styles, or files made unused by your changes.
- Don't remove pre-existing unrelated dead code unless asked.

The test: every changed line should either implement the requested behavior, consolidate directly related existing behavior, or be necessary cleanup caused by those changes.

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

_Measure first. Optimize the actual bottleneck. Preserve behavior._

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

## 7. UI/UX Design Skill

For tasks that materially create, redesign, review, audit, or improve frontend UI/UX,
use the Hallmark skill as a supporting design guide when relevant.

This includes:

- page and component redesigns
- forms and modals
- dashboards and management interfaces
- customer-facing interfaces
- visual hierarchy, spacing, typography, layout, and information density
- UI audits and design-quality reviews
- requests to make the interface feel less generic or AI-generated

Do not invoke Hallmark for changes that are purely backend, database,
business logic, validation, routing, infrastructure, or other non-visual implementation work.

Hallmark is advisory. Existing Bethlehem UI conventions, accessibility,
workflow requirements, business rules, functionality, and project-specific
instructions take precedence.

Do not introduce visual novelty solely to satisfy Hallmark.

Prefer practical, consistent, accessible, and task-focused improvements
over flashy, decorative, or unconventional design.

For existing interfaces, preserve and extend the established Bethlehem
design language rather than independently redesigning each screen.

If a Hallmark recommendation conflicts with an established project pattern,
only depart from the existing pattern when there is a clear usability,
accessibility, consistency, or workflow reason to do so.

## 8. Goal-Driven Execution

_Define success criteria. Loop until verified._

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

## 9. Debugging and Regression Skills

**Find the root cause before changing behavior. Preserve meaningful regression coverage.**

For bugs, failing tests, regressions, build failures, migration failures,
unexpected runtime behavior, or integration failures, use the
`systematic-debugging` skill when relevant.

During debugging:

- Reproduce the failure before attempting a fix whenever practical.
- Determine whether the root cause is production code, test setup, mocks or
  fixtures, environment/configuration, dependency state, migrations, or a
  stale/brittle assertion.
- Fix the root cause rather than weakening production behavior or changing an
  assertion only to make a test pass.
- Do not delete, skip, or relax a failing test unless its expectation is proven
  obsolete, duplicated, or incorrect.
- Use the smallest relevant test or reproduction while investigating. Broaden
  verification only when dependencies, integration risk, or the task scope
  warrants it.

When systematic debugging confirms an actual application behavior defect,
use the `test-driven-development` skill when practical:

- Create or update a focused regression test that reproduces the defect.
- Confirm the test fails for the expected reason before changing production logic.
- Apply the smallest correct production fix.
- Confirm the regression test passes afterward.
- Run the additional relevant checks required by the affected scope.

TDD is not required for purely visual CSS/layout changes, copy changes,
generated files, or configuration-only edits unless the task is correcting
testable behavior or a regression.

Do not create superficial source-string, markup-presence, or implementation-detail
tests solely to satisfy TDD. Prefer observable behavior and the project's existing
test conventions.

## 10. Codebase Structure and File Organization

**Improve the structure gradually as development exposes real problems. Avoid both uncontrolled accumulation and oversized refactors.**

Use the `writing-plans` skill when a task involves a substantial structural change, such as:

- reorganizing multiple related folders or files
- splitting an oversized or mixed-responsibility file
- consolidating behavior scattered across many related files
- restructuring CSS, JavaScript, Blade, controllers, services, or other modules
- planning a meaningful multi-file refactor where dependencies or migration order matter

The `writing-plans` skill is advisory for structural reasoning. The user's request and this `AGENTS.md` remain the authority for scope and execution.

### Incremental Structural Improvement

During ordinary development, do not merely avoid making existing structural problems worse.

When working on a feature or behavior:

- Notice whether the directly related implementation is unnecessarily scattered, duplicated, or placed in clearly inappropriate files.
- If the related code can be consolidated safely within a reasonable scope, prefer improving it as part of the task rather than adding another duplicate implementation.
- Follow the behavior through its actual related consumers when necessary; file boundaries alone do not define the task boundary.
- Prefer one clear source of truth for the same behavior when multiple active implementations are unnecessary.
- Prefer files with a clear responsibility when an existing file has accumulated unrelated concerns.
- Keep closely related code together when that matches how the feature is actually maintained.
- Reuse established project folders and conventions when they remain appropriate.

### Refactor Boundary

Incremental improvement must remain understandable and verifiable.

Do not:

- perform a repository-wide cleanup because one task exposed a structural problem
- reorganize unrelated modules
- introduce a new architecture solely because it appears cleaner
- split code into excessive tiny files
- create abstractions without multiple real consumers
- migrate large parts of the codebase in one task when the same improvement can be made safely in stages

If the ideal consolidation is too broad or risky for the current task:

1. complete the safe directly related portion when useful
2. preserve compatibility with the remaining code
3. identify the remaining structural work separately instead of forcing the entire migration into one change

### Structural Safety

When moving, splitting, or consolidating code:

- inspect actual dependencies before changing locations
- preserve behavior and business rules
- preserve routes, imports, asset references, public interfaces, and build behavior
- for CSS, preserve loading order, cascade, specificity, responsive behavior, and print behavior
- verify all affected consumers after consolidation
- avoid temporary duplicate implementations unless they are genuinely needed for a staged migration

For small, obvious consolidations, do not invoke `writing-plans` merely because several files are touched.

Use `writing-plans` when the structural decision itself is complex enough that mapping files, responsibilities, dependencies, and migration steps first would reduce risk.

Do not automatically adopt optional skill workflow conventions such as creating worktrees, making commits, or changing the execution process unless the user or task specifically requires them.

The goal is not a perfectly organized repository after every task. The goal is for development to **progressively reduce task-related duplication and structural debt without creating risky, oversized refactors.**

---

_These guidelines are working if:_ fewer unnecessary changes in diffs, fewer rewrites due to overcomplication, and clarifying questions come before implementation rather than after mistakes.
