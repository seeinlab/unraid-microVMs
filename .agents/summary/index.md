# microVMs Plugin — Documentation Index

> **For AI assistants:** This file is your starting point. It contains metadata about all documentation files so you can determine which to consult for specific questions without reading them all.

## Quick Reference

| Question | Consult |
|----------|---------|
| Full features + data models + API | docs/FEATURES-AND-DATA-MODELS.md |
| Verified architecture | docs/ARCHITECTURE.md |
| What's broken or missing? | docs/PLAN-NEXT.md |
| Init process design (Fly.io pattern) | docs/RESEARCH-init-process.md |
| VM state management research | docs/RESEARCH-state-management.md |
| Unraid array integration gotchas | docs/RESEARCH-unraid-array.md |
| Why flintlockd was dropped as default | docs/RESEARCH-init-process.md (bottom) |
| Console architecture | docs/PLAN-console.md |
| Init refactor plan | docs/PLAN-init-refactor.md |
| How does the system work? | .agents/summary/architecture.md |
| What files do what? | .agents/summary/components.md |
| How to call the API? | .agents/summary/interfaces.md |
| What's the data format? | .agents/summary/data_models.md |
| How does create/start/stop work? | .agents/summary/workflows.md |
| What versions are needed? | .agents/summary/dependencies.md |

## Key Design Rules

1. **PLG install NEVER accesses /mnt/user/** — array not online yet
2. **Services start via event/array_started** — not in PLG script
3. **VMM determined by config filename** — `cloud-hypervisor.json` or `firecracker.json`
4. **Process detection uses `pidof` + `/proc/PID/cmdline`** — not `pgrep -f`
5. **AJAX uses `$_REQUEST`** — not `$_POST` (sub-page tab context)
6. **Thin pool uses `ctr images mount`** — single command for pull+unpack+snapshot
7. **CH disk needs `image_type=raw`** — required for devmapper writes in v52
8. **VM paths: `$VMDIR/{namespace}/{name}/`** — namespace isolation on disk
9. **CH console: direct PTY write** — no FIFO (PTY is bidirectional)
10. **FC console: FIFO → stdin** — FC started with `tail -f FIFO | firecracker`
11. **CH restore: API only** — `cloud-hypervisor --api-socket` then `ch-remote restore`
12. **Serial log: `{name}.serial.log`** — dot separator (must match console_output)

## Source Layout (for code navigation)

```
src/usr/local/emhttp/plugins/microvms/
├── backend/MicroVMAdmin.php    ← ALL AJAX commands (30+)
├── include/common.php          ← Shared functions + microvm_resolve_vmpath()
├── event/array_started         ← Boot trigger
├── event/stopping_svcs         ← Shutdown trigger
├── MicroVMsMachines.page       ← VM list + context menu + Console popup
├── AddMicroVMs.page            ← Create form (namespace dropdown)
├── MicroVMsRootFS.page         ← Storage tab (images + snapshots)
├── MicroVMsSettings*.page      ← Settings tabs (5 files)
└── images/                     ← VMM icons

src/usr/local/etc/rc.d/rc.microvms  ← Service management (1200+ lines)
src/usr/local/share/microvms/fly-init ← Guest init script (Fly.io pattern)
plugin/microvms.plg                  ← Installer definition
docs/FEATURES-AND-DATA-MODELS.md     ← Complete API + data model reference
```

## Namespace Model

| Namespace | Auto-created | Purpose |
|-----------|-------------|---------|
| `default` | Always | Fallback / general use |
| `ch` | When CH enabled | Cloud Hypervisor VMs |
| `fc` | When FC enabled | Firecracker VMs |
| `flintlock` | By Liquidmetal | flintlockd orchestration (hidden from UI) |

## VM Directory Layout

```
/mnt/user/microvms/              ← VMDIR (persistent, user share)
├── ch/                          ← CH namespace
│   └── my-vm/
│       ├── cloud-hypervisor.json
│       ├── rootfs.raw           (raw storage only)
│       └── snapshots/
├── fc/                          ← FC namespace
│   └── my-fc-vm/
│       ├── firecracker.json
│       └── snapshots/
└── default/                     ← Default namespace

/var/run/microvms/               ← Runtime (tmpfs)
├── containerd.sock
├── containerd.pid
└── {ns}/{name}/
    ├── {vmm}.pid
    └── metadata.json
```
