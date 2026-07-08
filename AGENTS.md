# AGENTS.md — microVMs Plugin for Unraid

## Project Identity

Unraid plugin enabling Cloud Hypervisor and Firecracker microVMs with WebGUI management, OCI image support, devmapper thin provisioning, and optional Liquidmetal gRPC orchestration.

## Directory Map

```
src/usr/local/emhttp/plugins/microvms/  ← WebGUI plugin (start here for UI)
  backend/MicroVMAdmin.php              ← ALL AJAX commands (30+ commands)
  include/common.php                    ← Shared PHP functions + microvm_resolve_vmpath()
  event/{array_started,stopping_svcs}   ← Unraid lifecycle hooks
  MicroVMsMachines.page                 ← VM list + context menu + Console popup
  AddMicroVMs.page                      ← Create VM form (namespace dropdown)
  MicroVMsRootFS.page                   ← Storage tab (images + snapshots)
  MicroVMsSettings*.page                ← Settings (5 sub-pages + namespace list)

src/usr/local/etc/rc.d/rc.microvms      ← Service manager (bash, 1200+ lines)
src/usr/local/share/microvms/fly-init   ← Guest init script (Fly.io pattern)
plugin/microvms.plg                     ← PLG installer (XML)
docs/                                   ← Design docs, research, progress
  FEATURES-AND-DATA-MODELS.md           ← Complete reference (611 lines)
  ARCHITECTURE.md                       ← Verified architecture
  PLAN-NEXT.md                          ← Remaining work
```

## Critical Design Rules

| Rule | Rationale |
|------|-----------|
| PLG install NEVER touches /mnt/user/ | Array not online at boot time |
| Services start via `event/array_started` | Unraid event system, not PLG script |
| VMM from config filename | `cloud-hypervisor.json` vs `firecracker.json`, no JSON field |
| Process detection: `pidof` + `/proc/PID/cmdline` | Not `pgrep -f` (Docker containerd false positive) |
| AJAX uses `$_REQUEST` | `$_POST` empty in Unraid sub-page tab context |
| CH disk: `image_type=raw` | Required for devmapper writes in CH v52 |
| Thin pool: `ctr images mount` | Single command: pull + unpack + snapshot + mount |
| VM paths: `$VMDIR/{namespace}/{name}/` | Namespace isolation on disk (ch/, fc/, default/) |
| Containerd namespaces: `default`, `ch`, `fc` | Per-VMM, auto-created. `flintlock` reserved for Liquidmetal |
| VM state: containerd containers + state dir | `ctr containers create --label microvm.*` registers VM |
| Network: kernel `ip=` parameter | No iproute2 needed in guest images |
| Init: `/fly/init` + `catatonit` + `/fly/run.json` | Fly.io pattern, generic init for all images |
| Stop: 10s ACPI then force kill | Never hang on unresponsive VM |
| Thin pool persists across restart | Only `reset_thinpool` destroys (requires 'yes' confirmation) |
| Single-script rootfs ops | All mount ops in one exec (Unraid mount namespace issue) |
| Filesystem ops: use exec() not PHP native | PHP copy/mkdir fails on Unraid loop mounts |
| CH console: direct PTY read/write | No FIFO for CH (PTY is bidirectional) |
| FC console: FIFO → stdin, stdout → log | FC process started with `tail -f FIFO \| firecracker` |
| CH restore: API method only | `cloud-hypervisor --api-socket` then `ch-remote restore` (not CLI --restore) |
| Serial log filename: `{name}.serial.log` | Dot separator, not hyphen. Must match console_output handler |

## Key Entry Points

| Task | Start at |
|------|----------|
| Fix VM create | `MicroVMAdmin.php` → case 'create' |
| Fix VM start/stop | `rc.microvms` → `start_vm()` / `stop_vm()` |
| Fix Settings UI | `MicroVMsSettingsGeneral.page` (status tree + form) |
| Fix VM list display | `MicroVMsMachines.page` line ~113 |
| Fix thin pool | `MicroVMAdmin.php` thin path + `rc.microvms` → `activate_thin_rootfs()` |
| Fix console | `MicroVMAdmin.php` → `console_input` / `console_output` |
| Fix snapshots | `common.php` → `microvm_snapshot_vm()` / `microvm_restore_snapshot_ch/fc()` |
| Fix namespace resolution | `common.php` → `microvm_resolve_vmpath()` |
| Add Unraid event | `src/.../event/array_started` (bash script) |
| Full API + data model ref | `docs/FEATURES-AND-DATA-MODELS.md` |

## Patterns That Deviate From Defaults

- **Unraid `.page` files**: NOT standard PHP — they use `markdown="1"` forms with `_(Label)_:` syntax
- **`<form markdown="1">`**: Buttons inside get block-styled — put service buttons OUTSIDE the form
- **`#file` hidden input**: Tells Unraid's `update.php` where to write config
- **`#command` hidden input**: Runs after config save (used for restart)
- **Sub-page tabs**: `Menu="ParentPage:N"` in page header creates tabbed UI
- **`Type="xmenu"`**: Makes a page a tab container (no content itself)
- **Flash is VFAT**: Case-insensitive filesystem — can't rely on case in paths
- **CH v52 restore quirk**: `--kernel` + `--restore` CLI flags boots fresh VM; must use API method instead

## Config Files (discoverable from repo)

| File | Format | Purpose |
|------|--------|---------|
| `microvms.controlplane.cfg` | INI (bash `source`) | Plugin settings (17 keys) |
| `{vmm}.json` per VM | JSON | VM definition at `$VMDIR/{ns}/{name}/` |
| `microvms.plg` | XML | Plugin installer with download URLs |
| `metadata.json` | JSON | Runtime state at `/var/run/microvms/{ns}/{name}/` |

## Namespace Model

| Namespace | Auto-created | Purpose | Delete behavior |
|-----------|-------------|---------|-----------------|
| `default` | Always | Fallback / general | Protected |
| `ch` | When CH enabled | Cloud Hypervisor VMs | Removed when CH disabled (if empty) |
| `fc` | When FC enabled | Firecracker VMs | Removed when FC disabled (if empty) |
| `flintlock` | By Liquidmetal | flintlockd orchestration | Hidden from UI |

## Deploy Pattern (development)

```bash
# Deploy single file to running Unraid:
cat src/.../file | ssh -i ~/.ssh/mastervault root@192.168.50.6 'cat > /path/on/unraid'

# Verify + deploy all changed files:
bash -n src/usr/local/etc/rc.d/rc.microvms && \
cat src/.../rc.microvms | ssh ... 'cat > /etc/rc.d/rc.microvms && chmod +x ...'

# PHP syntax check on server:
ssh ... 'php -l /usr/local/emhttp/plugins/microvms/backend/MicroVMAdmin.php'
```

## Custom Instructions
<!-- This section is for human and agent-maintained operational knowledge.
     Add repo-specific conventions, gotchas, and workflow rules here.
     This section is preserved exactly as-is when re-running codebase-summary. -->
