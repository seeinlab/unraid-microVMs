# microVMs Plugin — Documentation Index

> **For AI assistants:** This file is your starting point. It contains metadata about all documentation files so you can determine which to consult for specific questions without reading them all.

## Quick Reference

| Question | Consult |
|----------|---------|
| Full verified architecture | docs/ARCHITECTURE.md |
| How does the system work? | .agents/summary/architecture.md |
| What files do what? | .agents/summary/components.md |
| How to call the API? | .agents/summary/interfaces.md |
| What's the data format? | .agents/summary/data_models.md |
| How does create/start/stop work? | .agents/summary/workflows.md |
| What versions are needed? | .agents/summary/dependencies.md |
| What's broken or missing? | docs/PLAN-NEXT.md |
| Init process design (Fly.io pattern) | docs/RESEARCH-init-process.md |
| VM state management research | docs/RESEARCH-state-management.md |
| Unraid array integration gotchas | docs/RESEARCH-unraid-array.md |
| Why flintlockd was dropped as default | docs/RESEARCH-init-process.md (bottom) |
| Console architecture | docs/PLAN-console.md |
| Init refactor plan | docs/PLAN-init-refactor.md |

## File Descriptions

### codebase_info.md
Project identity, technology stack, repository structure, key binaries. Read this first for overall context.

### architecture.md
System architecture with Mermaid diagrams: dual operating modes (Direct vs Liquidmetal), boot sequence, storage architecture, network topology. Explains WHY things are structured this way.

### components.md
Every file's purpose, the PHP backend command dispatch table, service script commands, event hooks. Explains WHAT each piece does.

### interfaces.md
Complete API reference: AJAX endpoints, CH/FC socket APIs, containerd ctr CLI commands, flintlockd gRPC, configuration file format, VM config JSON schema. Explains HOW to interact with the system.

### data_models.md
VM config JSON structure (with class diagram), controlplane config keys, filesystem layout with persistence scopes.

### workflows.md
Step-by-step sequence diagrams for: create VM (thin + raw), start VM, stop VM, plugin install, array start event. Shows the FLOW of operations.

### dependencies.md
All external binaries with versions and sources, kernel modules, Unraid platform dependencies, compatibility matrix.

### review_notes.md
Consistency check results, completeness gaps, known issues, recommendations for improvement.

## Key Design Rules

1. **PLG install NEVER accesses /mnt/user/** — array not online yet
2. **Services start via event/array_started** — not in PLG script
3. **VMM determined by config filename** — `cloud-hypervisor.json` or `firecracker.json`
4. **Process detection uses `pidof`** — not `pgrep -f`
5. **AJAX uses `$_REQUEST`** — not `$_POST` (sub-page tab context)
6. **Thin pool uses `ctr images mount`** — single command for pull+unpack+snapshot
7. **CH disk needs `image_type=raw`** — required for devmapper writes in v52

## Source Layout (for code navigation)

```
src/usr/local/emhttp/plugins/microvms/
├── backend/MicroVMAdmin.php    ← ALL AJAX commands
├── include/common.php          ← Shared functions
├── event/array_started         ← Boot trigger
├── event/stopping_svcs         ← Shutdown trigger
├── MicroVMsMachines.page       ← VM list + context menu
├── AddMicroVMs.page            ← Create form
├── MicroVMsSettings*.page      ← Settings tabs (5 files)
├── start.sh / stop.sh          ← Simple wrappers
└── images/                     ← VMM icons

src/usr/local/etc/rc.d/rc.microvms  ← Service management (800+ lines)
plugin/microvms.plg                  ← Installer definition
```
