# Research: Agent Context Engineering for Revat

## The Problem

Agents working on Revat need domain context to extend the app correctly — how attribution works, how reports are built, how widgets compose, what the database schema means. Currently this knowledge lives in scattered places (build-plan specs, code comments, CLAUDE.md) or only in Jordan's head. We need a structured way for agents to self-serve context.

## Research Summary

### The Discipline: Context Engineering

"Context engineering" has replaced "prompt engineering" as the core discipline for AI-assisted development (Karpathy, 2025; Martin Fowler, 2026). The key insight: **a focused 300-token context outperforms an unfocused 113K-token context.** Performance degrades around 40% window utilization.

### Evidence: What Actually Works

| Finding | Source |
|---------|--------|
| LLM-generated context files **hurt** performance (-3% success, +20% cost) | ETH Zurich, March 2026 |
| Human-written context helps modestly (+4%) but only for **non-inferable details** | ETH Zurich |
| Concrete code examples "heavily influence the outcome" | Spotify Engineering |
| Spec staleness is the #1 failure mode — agents trust docs absolutely | Codified Context paper |
| Three-tier hot/warm/cold architecture works at scale (108K LOC, 283 sessions) | Codified Context paper |
| Lazy-loaded skills cut context usage by 35% (80K → 52K tokens) | Multiple practitioners |

### The Three-Tier Architecture (Industry Consensus)

```
Tier 1: HOT — Always loaded (CLAUDE.md, <700 lines)
├── Universal rules, build commands, conventions
├── Only things that apply to EVERY session
└── Test: "Would removing this cause mistakes?" If no, cut it.

Tier 2: WARM — Lazy-loaded on demand (Skills, scoped rules)
├── Domain-specific knowledge loaded when relevant
├── Agent reads name+description, decides whether to load body
└── This is where domain SOPs live

Tier 3: COLD — Retrieved via tools (MCP, docs, specs)
├── Detailed reference material retrieved dynamically
├── Database schema via Laravel Boost MCP
└── Build plan specs, ADRs, data model docs
```

### What Makes Context Effective (Include)

- Non-obvious commands and conventions agents can't infer from code
- Gotchas and known failure modes
- Architecture decisions and their **rationale** (why, not just what)
- Domain-specific constraints and business rules
- Concrete code patterns with file paths (not abstract principles)
- Before/after examples showing the RIGHT way

### What Hurts (Exclude)

- Standard conventions the model already knows
- File-by-file codebase descriptions (agents can explore)
- Abstract principles ("write clean code")
- Information that changes frequently (use MCP/tools instead)
- Anything derivable from reading the code
- LLM-auto-generated descriptions of the codebase

### Cross-Tool Standard: AGENTS.md

AGENTS.md (Linux Foundation, 20+ tools) supports hierarchical scoping — files in subdirectories override parent files. Useful if we ever use non-Claude tools. But for now, Claude Skills are the superior mechanism because they support lazy loading.

---

## Current State: What Revat Already Has

### Strengths
- **Three-layer CLAUDE.md hierarchy** (monorepo → build-plan → app)
- **7 domain skills** (cashier, horizon, flux, pest, pulse, tailwind, volt) — all framework-level
- **Build plan** with 10 epics, 80 story specs — great for "what to build"
- **Laravel Boost MCP** — dynamic DB schema, error logs, doc search
- **Memory system** — captures lessons and feedback across sessions
- **Attribution data model doc** — `docs/attribution-data-model.md`

### Gap
All existing skills are **framework-level** (how to use Pest, Flux, Volt, etc.). None describe **how Revat works** — the domain logic, business rules, and architectural patterns specific to this application. An agent can look up Flux docs but has no way to know:

- How the PIE (Program/Initiative/Effort) hierarchy works and why
- How attribution resolution chains operate
- How reports compose from widgets and data sources
- How multi-tenancy scoping is enforced
- How the connector/integration system works
- How billing/plan limits are enforced
- What the onboarding flow does and why

This is the "Tier 2" gap — domain knowledge that should be lazy-loaded when an agent works in that area.

---

## Recommendation: Domain Context Skills

### Approach: Skills over Docs Folder

A `docs/sop/` folder would work but has a critical limitation: **it's always cold** — agents would need to know to look there and manually read files. Skills are superior because:

1. **Lazy-loaded automatically** — agent sees name+description, loads body only when relevant
2. **Trigger on intent** — "I'm working on reports" auto-surfaces the reports skill
3. **Already integrated** — we have 7 skills; adding more is zero infrastructure work
4. **Cacheable** — static skill content benefits from prompt caching

### Proposed Domain Skills

Create skills in `.claude/skills/` for each major domain area:

```
.claude/skills/
├── cashier-stripe-development/     # EXISTS — framework-level
├── configuring-horizon/            # EXISTS — framework-level
├── fluxui-development/             # EXISTS — framework-level
├── pest-testing/                   # EXISTS — framework-level
├── pulse-development/              # EXISTS — framework-level
├── tailwindcss-development/        # EXISTS — framework-level
├── volt-development/               # EXISTS — framework-level
│
├── revat-attribution/              # NEW — domain-level
│   └── SKILL.md                    # PIE hierarchy, resolution chains,
│                                   # attribution models, key tables
│
├── revat-reports-widgets/          # NEW — domain-level
│   └── SKILL.md                    # Report composition, widget types,
│                                   # data sources, rendering pipeline
│
├── revat-connectors/               # NEW — domain-level
│   └── SKILL.md                    # Integration system, connector types,
│                                   # sync patterns, data mapping
│
├── revat-multitenancy/             # NEW — domain-level
│   └── SKILL.md                    # Workspace scoping, team/role model,
│                                   # permission enforcement, data isolation
│
├── revat-billing/                  # NEW — domain-level
│   └── SKILL.md                    # Plan limits, feature gates,
│                                   # usage tracking, upgrade flows
│
├── revat-onboarding/               # NEW — domain-level
│   └── SKILL.md                    # Wizard flow, workspace setup,
│                                   # initial data seeding, first-run UX
│
└── revat-data-pipeline/            # NEW — domain-level
    └── SKILL.md                    # Extraction → Transformation → Loading,
                                    # job orchestration, error handling
```

### What Goes in Each Skill

Each domain skill should contain:

1. **Purpose** (1-2 sentences) — what this subsystem does and why it exists
2. **Key Concepts** — domain terms and their relationships (e.g., PIE hierarchy)
3. **Architecture** — how it's built, key classes/files, data flow
4. **Patterns** — the RIGHT way to extend it, with concrete code examples
5. **Gotchas** — known failure modes, non-obvious constraints, things that break
6. **Related** — pointers to other skills/docs that connect

**Format principles:**
- Written for agents, not humans — explicit file paths, class names, method signatures
- Concrete examples over abstract rules
- "Do this / Don't do this" over explanatory prose
- Under 300 lines per skill — if longer, split into sub-skills

### Complementary: Reference Docs (Tier 3 Cold Storage)

Keep `docs/` for detailed reference material that skills can point to:

```
docs/
├── attribution-data-model.md       # EXISTS — detailed schema reference
├── database-schema-guide.md        # NEW — key tables, relationships, why
├── api-design.md                   # NEW — API conventions, versioning
└── architecture-decisions/         # NEW — ADRs for major decisions
    ├── 001-pie-hierarchy.md
    ├── 002-multi-tenancy-model.md
    └── ...
```

Skills reference these docs (`@docs/attribution-data-model.md`) for deep dives, but the skill body contains enough context for most tasks.

### Maintenance Strategy

The #1 failure mode is **spec staleness**. Mitigations:

1. **Same-commit rule**: If you change domain code, update the corresponding skill/doc in the same commit
2. **Skill review cadence**: Review domain skills monthly (15-30 min each)
3. **Agent feedback loop**: When an agent gets confused, that's a signal the skill needs updating — capture in lessons.md
4. **Code-first, not doc-first**: Skills describe patterns and constraints, not implementation details. The code is the source of truth; skills provide the "why" and "how to extend."

### Implementation Priority

1. **revat-attribution** — Most complex domain, most value from context
2. **revat-multitenancy** — Affects everything, easy to get wrong
3. **revat-connectors** — Active development area
4. **revat-data-pipeline** — Complex job orchestration
5. **revat-reports-widgets** — Future development
6. **revat-billing** — Relatively contained
7. **revat-onboarding** — Relatively contained

---

## Sources

- [Codified Context: Infrastructure for AI Agents](https://arxiv.org/html/2602.20478v1) — Three-tier architecture, 108K LOC case study
- [ETH Zurich: AGENTS.md File Value Review](https://www.infoq.com/news/2026/03/agents-context-file-value-review/) — Evidence on what helps vs hurts
- [Context Engineering for Coding Agents (Martin Fowler)](https://martinfowler.com/articles/exploring-gen-ai/context-engineering-coding-agents.html)
- [Effective Context Engineering (Anthropic)](https://www.anthropic.com/engineering/effective-context-engineering-for-ai-agents)
- [Context Engineering: Background Coding Agents (Spotify)](https://engineering.atspotify.com/2025/11/context-engineering-background-coding-agents-part-2)
- [Claude Skills: Lazy-Loaded Context](https://taylordaughtry.com/posts/claude-skills-are-lazy-loaded-context/)
- [AGENTS.md Specification](https://agents.md/)
- [Best Practices for Claude Code](https://code.claude.com/docs/en/best-practices)
- [Interpretable Context Methodology](https://arxiv.org/html/2603.16021) — Staged pipeline approach
- [Lazy Skills: Token-Efficient Approach](https://boliv.substack.com/p/lazy-skills-a-token-efficient-approach) — 35% token savings
