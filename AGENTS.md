# AGENTS.md — microVMs Plugin for Unraid

## Project Identity

Unraid plugin enabling Cloud Hypervisor and Firecracker microVMs with WebGUI management, OCI image support, devmapper thin provisioning, and optional Liquidmetal gRPC orchestration.

## Directory Map

```
src/usr/local/emhttp/plugins/microvms/  ← WebGUI plugin (start here for UI)
  backend/MicroVMAdmin.php              ← ALL AJAX commands (create/start/stop/delete/resize)
  include/common.php                    ← Shared PHP functions
  event/{array_started,stopping_svcs}   ← Unraid lifecycle hooks
  MicroVMsMachines.page                 ← VM list + context menu
  AddMicroVMs.page                      ← Create VM form
  MicroVMsSettings*.page                ← Settings (5 sub-pages)

src/usr/local/etc/rc.d/rc.microvms      ← Service manager (bash, 800+ lines)
plugin/microvms.plg                     ← PLG installer (XML)
docs/                                   ← Design docs, API refs, progress
.agents/summary/                        ← Generated documentation
```

## Critical Design Rules

| Rule | Rationale |
|------|-----------|
| PLG install NEVER touches /mnt/user/ | Array not online at boot time |
| Services start via `event/array_started` | Unraid event system, not PLG script |
| VMM from config filename | `cloud-hypervisor.json` vs `firecracker.json`, no JSON field |
| Process detection: `pidof` | Not `pgrep -f` (Docker containerd false positive) |
| AJAX uses `$_REQUEST` | `$_POST` empty in Unraid sub-page tab context |
| CH disk: `image_type=raw` | Required for devmapper writes in CH v52 |
| Thin pool: `ctr images mount` | Single command: pull + unpack + snapshot + mount |
| JSON cmdline needs unescape | `sed 's\|\\\/\|/\|g'` after grep extraction |

## Key Entry Points

| Task | Start at |
|------|----------|
| Fix VM create | `MicroVMAdmin.php` → case 'create' |
| Fix VM start/stop | `rc.microvms` → `start_vm()` / `stop_vm()` |
| Fix Settings UI | `MicroVMsSettingsGeneral.page` (status tree + form) |
| Fix VM list display | `MicroVMsMachines.page` line ~113 |
| Fix thin pool | `MicroVMAdmin.php` thin path + `rc.microvms` → `activate_thin_rootfs()` |
| Add Unraid event | `src/.../event/array_started` (bash script) |

## Patterns That Deviate From Defaults

- **Unraid `.page` files**: NOT standard PHP — they use `markdown="1"` forms with `_(Label)_:` syntax
- **`<form markdown="1">`**: Buttons inside get block-styled — put service buttons OUTSIDE the form
- **`#file` hidden input**: Tells Unraid's `update.php` where to write config
- **`#command` hidden input**: Runs after config save (used for restart)
- **Sub-page tabs**: `Menu="ParentPage:N"` in page header creates tabbed UI
- **`Type="xmenu"`**: Makes a page a tab container (no content itself)
- **Flash is VFAT**: Case-insensitive filesystem — can't rely on case in paths

## Config Files (discoverable from repo)

| File | Format | Purpose |
|------|--------|---------|
| `microvms.controlplane.cfg` | INI (bash `source`) | Plugin settings |
| `{vmm}.json` per VM | JSON | VM definition (infra-as-code) |
| `microvms.plg` | XML | Plugin installer with download URLs |

## Deploy Pattern (development)

```bash
# Deploy single file to running Unraid:
cat src/.../file | ssh -i ~/.ssh/mastervault root@192.168.50.6 'cat > /path/on/unraid'

# Rebuild + deploy tgz (for PLG install testing):
cd src && tar -czf ../plugin/microvms-*.tgz usr/ && cd ..
cat plugin/microvms-*.tgz | ssh ... 'cat > /boot/config/plugins/microvms/...'
```

## Custom Instructions
<!-- This section is for human and agent-maintained operational knowledge.
     Add repo-specific conventions, gotchas, and workflow rules here.
     This section is preserved exactly as-is when re-running codebase-summary. -->
