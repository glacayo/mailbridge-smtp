# Skill Registry — MailBridge SMTP

**Generated**: 2026-04-06  
**Project**: mailbridge-smtp
**Purpose**: Catalog of available skills with compact rules for AI agent delegation

---

## SDD Workflow Skills

These skills implement the Spec-Driven Development workflow. They are automatically invoked by the orchestrator during SDD phases.

| Skill | Trigger | Mode |
|-------|---------|------|
| sdd-init | Initialize SDD context in a project | Auto (sdd init command) |
| sdd-explore | Explore and investigate ideas before committing to a change | Phase 1 |
| sdd-propose | Create a change proposal with intent, scope, and approach | Phase 2 |
| sdd-spec | Write specifications with requirements and scenarios | Phase 3 |
| sdd-design | Create technical design document with architecture decisions | Phase 4 |
| sdd-tasks | Break down a change into an implementation task checklist | Phase 5 |
| sdd-apply | Implement tasks from the change, writing actual code | Phase 6 |
| sdd-verify | Validate that implementation matches specs, design, and tasks | Phase 7 |
| sdd-archive | Sync delta specs to main specs and archive a completed change | Phase 8 |
| sdd-onboard | Guided end-to-end walkthrough of the SDD workflow | Tutorial mode |

---

## Code/Task Skills

### judgment-day
**Trigger**: User says "judgment day", "review adversarial", "dual review", "doble review", "juzgar", "que lo juzguen"

**Compact Rules**:
- Launch two independent blind judge sub-agents simultaneously
- Judges must NOT see each other's work — complete isolation
- Review target must be clearly defined (file, function, PR, etc.)
- Synthesize findings: identify consensus issues and conflicting opinions
- Apply fixes that BOTH judges agree on, or escalate conflicts
- Re-judge after fixes: BOTH judges must pass to complete
- Escalate to user after 2 iterations if judges still disagree

---

### skill-creator
**Trigger**: User asks to create a new skill, add agent instructions, or document patterns for AI

**Compact Rules**:
- Follow SKILL.md format with frontmatter (name, description, license, metadata)
- Include: Purpose, When to Run, What to Do, Input Contract, Output Contract, Examples, Rules
- Write compact rules section (5-15 lines) — actionable patterns sub-agents need
- NEVER include: installation steps, motivational content, or verbose explanations
- Store in `.agents/skills/{skill-name}/SKILL.md`
- Update skill-registry.md after creating skill

---

### skill-registry
**Trigger**: User says "update skills", "skill registry", "actualizar skills", "update registry"

**Compact Rules**:
- Scan ALL skill directories (user-level and project-level)
- Skip: sdd-* skills, _shared, skill-registry itself
- Deduplicate: project-level wins over user-level
- Read each SKILL.md frontmatter → extract name, description, trigger
- Generate compact rules (5-15 lines) per skill — MOST IMPORTANT output
- Include ONLY: actionable rules, key patterns, gotchas that cause bugs
- Write to `.atl/skill-registry.md` AND save to Engram
- Compact rules are what sub-agents receive — make them accurate

---

### find-skills
**Trigger**: User asks "how do I do X", "find a skill for X", "is there a skill that can..."

**Compact Rules**:
- Search available skills in all directories
- Match user intent to skill triggers and descriptions
- Suggest best match with explanation of WHY it fits
- If no direct match, suggest closest alternative or skill-creator
- Never claim a skill exists without verifying

---

## Project Conventions

### Root Files
| File | Purpose |
|------|---------|
| mailbridge-smtp.php | Main plugin file |
| README.md | Plugin documentation |
| .gitignore | Git ignore rules (WordPress plugin standards) |
| .atl/skill-registry.md | This file — skill catalog |

---

## Stack-Specific Guidance

### WordPress Plugin Development

**Architecture Pattern**: Single-file plugin with Singleton class
**Security Standards**:
- Use `defined('ABSPATH') || exit;` at file start
- Use `wp_nonce_field()` / `wp_verify_nonce()` for form/AJAX security
- Use `current_user_can('manage_options')` for capability checks
- Use `sanitize_text_field()`, `sanitize_email()`, `esc_attr()`, `esc_html()`, `esc_url()` for output
- Encrypt sensitive data with AES-256-GCM using `wp_salt('auth')` as key source

**WordPress Hooks**:
- Admin: `admin_menu`, `admin_init`, `admin_enqueue_scripts`
- Mail: `phpmailer_init`
- AJAX: `wp_ajax_{action}`
- Lifecycle: `register_activation_hook`, `register_deactivation_hook`

**File Organization**:
```
{plugin-name}.php       # Main plugin file
uninstall.php           # Cleanup on uninstall
assets/
  css/
    admin.css          # Admin styles
  js/
    admin.js           # Admin scripts (ES6 class preferred)
```

**Testing Gap**:
- No PHPUnit/wp-phpunit configured
- No automated testing infrastructure
- For testing: consider Brain Monkey for unit tests without WordPress bootstrap

---

## Usage Notes

1. **Delegating to skills**: Reference skill name and include compact rules in prompt
2. **SDD workflow**: Orchestrator automatically invokes sdd-* phases in sequence
3. **Adding new skills**: Run skill-creator, then skill-registry to update
4. **Project-level overrides**: Place custom skills in `.agent/skills/` to override user-level

---

## Maintenance

**Last Updated**: 2026-04-06 (SDD Init)  
**Update Command**: "update skills" or "skill registry"  
**Engram Backup**: skill-registry topic key saved
