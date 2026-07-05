# Code Style Guide — microVMs Plugin

## References

| Plugin | Repo | Patterns Used |
|--------|------|---------------|
| **Community Applications** | [Squidly271/community.applications](https://github.com/Squidly271/community.applications) | Repo structure, `pkg_build.sh`, `source/` layout, AJAX-heavy PHP, large single-file backends |
| **Fix Common Problems** | [Squidly271/fix.common.problems](https://github.com/Squidly271/fix.common.problems) | Pure PHP plugin (99.2%), scan-and-report pattern, `source/` + `plugins/` repo layout |
| **Unraid WebGUI** | `dynamix.vm.manager` (built-in) | Page structure, context menu, CSS patterns, `mk_option()` |
| **Tailscale** | [unraid-tailscale](https://github.com/unraid/unraid-tailscale) | GPL header, namespacing, service management, JSON config |
| **ZFS Master** | [ZFS-Master-Unraid](https://github.com/Joly0/ZFS-Master-Unraid) | AJAX backend, SweetAlert2, frontend/backend split |

---

## Repo Structure

Following Squidly271 pattern (`community.applications`, `fix.common.problems`):

```
unraid-microVMs/
├── source/microvms/                     # ← Not used (we use src/ directly)
├── src/usr/local/emhttp/plugins/microvms/   # Plugin source (WebGUI)
│   ├── MicroVMs.page                    # Tab container
│   ├── MicroVMsMachines.page            # VM list + context menu
│   ├── MicroVMsRootFS.page             # rootFS management
│   ├── MicroVMsStats.page              # Statistics
│   ├── MicroVMsSettings.page           # microVMs Controlplane
│   ├── AddMicroVMs.page                # Create VM form
│   ├── backend/MicroVMAdmin.php        # Single AJAX entry point (like CA)
│   ├── include/common.php              # Shared functions
│   ├── images/                          # VMM icons
│   ├── console.html                    # ttyd wrapper
│   ├── microvms.{png,svg}             # Plugin icon
│   └── {start,stop,restart}.sh         # Service hooks
├── src/usr/local/etc/rc.d/rc.microvms   # Service script
├── src/usr/local/bin/microvms-console   # Console helper
├── plugin/microvms.plg                  # Installer (PLG)
├── docs/                                # Documentation
├── build.sh                             # Package builder (like pkg_build.sh)
└── scripts/                             # Dev/icon tools
```

**Key difference from Squidly271:** We use `src/` with the full target path structure instead of `source/{plugin-name}/`. Both patterns are valid — ours makes `rsync` deployment easier.

---

## File Headers

```php
<?php
/*
 * microVMs for Unraid
 * Copyright (C) 2026
 * License: GPL-2.0
 *
 * File: {filename}
 * Description: {purpose}
 */
```

---

## PHP Conventions

### Naming
- Functions: `microvm_snake_case()` (prefix with `microvm_`)
- Variables: `$camelCase` for local, `$UPPER_CASE` for constants
- Config keys: `UPPER_CASE` in .cfg files
- Constants: `define('MICROVM_*', ...)`

### Functions
```php
/**
 * Start a microVM by name.
 *
 * @param string $name VM name (lowercase, hyphens)
 * @return array ['success' => bool, 'output' => string]
 */
function microvm_start_vm($name) { ... }
```

### Error Handling
- Return structured arrays: `['success' => bool, 'error' => string]`
- Use `escapeshellarg()` for all shell inputs
- Use `json_encode()` for all JSON responses
- Set `Content-Type: application/json` in AJAX handlers

### Security
- Sanitize: `$name = preg_replace('/[^a-z0-9\-]/', '', ...)`
- Never interpolate user input without escaping
- Check VM exists before operations

---

## JavaScript Conventions

### In .page files
```html
<script>
var plugin = '<?=$plugin?>';

// --- Context Menu ---
function addMicroVMContext(name, state, vmm) { ... }

// --- VM Actions ---
function vmAction(action, name) { ... }
function vmConsole(name) { ... }

// --- Utilities ---
function escapeHtml(str) { ... }

// --- Init ---
$(function() { ... });
</script>
```

### Patterns (from CA/FCP)
- `$.post('/plugins/' + plugin + '/backend/MicroVMAdmin.php', {...})` for AJAX
- `swal()` for confirmations (Unraid includes SweetAlert)
- Single AJAX endpoint with `cmd` parameter (like CA's approach)
- No external JS frameworks — jQuery is always available

---

## Backend Pattern (MicroVMAdmin.php)

Following Community Applications' single-file backend:

```php
switch ($cmd) {
    // Lifecycle
    case 'list': ...
    case 'start': ...
    case 'stop': ...
    case 'force_stop': ...
    
    // Info
    case 'info': ...
    case 'logs': ...
    case 'status': ...
    
    // CRUD
    case 'create': ...
    case 'delete': ...
    
    // Snapshots
    case 'snapshot': ...
    case 'list_snapshots': ...
    
    // Service
    case 'service': ...
    case 'liquidmetal': ...
}
```

---

## Page Structure (.page files)

```
Menu="..."
Title="..."
Icon="microvms.png"
Tag="..."
Cond="..."
---
<?php /* Logic */ ?>

<style>/* CSS */</style>

<!-- HTML -->

<script>/* JS */</script>
```

### Menu Types
- `Menu="Tasks:65"` → Main tab (MicroVMs.page)
- `Menu="MicroVMs:1"` → Sub-tab (Machines, RootFS, Stats)
- `Menu="OtherSettings"` → Settings page

---

## Shell Script Pattern (rc.microvms)

```bash
#!/bin/bash
# /etc/rc.d/rc.microvms - microVMs service

# --- Configuration ---
PLUGIN="microvms"
CFGFILE="/boot/config/plugins/${PLUGIN}/${PLUGIN}.controlplane.cfg"

# --- Helpers ---
log() { logger -t "rc.microvms" "$1"; echo "$1"; }

# --- Service Functions ---
start_containerd() { ... }
start_flintlockd() { ... }
start_vm() { ... }
stop_vm() { ... }

# --- Main ---
microvm_start() { ... }  # Boot sequence
microvm_stop() { ... }   # Shutdown sequence

# --- Dispatch ---
case "$1" in
  start) microvm_start ;;
  stop)  microvm_stop ;;
  ...
esac
```

---

## PLG Installer Pattern

Following Squidly271 conventions:
1. `<FILE>` downloads with `<URL>` for binaries
2. Tarballs to `tmp/` directory, extracted in `<FILE Run>` block
3. Single install `<FILE Run>` block for all setup
4. Single remove `<FILE Run Method="remove">` block for cleanup
5. `<CHANGES>` block at top with version history
6. `launch="Settings/PageName"` for post-install navigation

---

## Config File Pattern

```ini
# /boot/config/plugins/microvms/microvms.controlplane.cfg
SERVICE="enable"
VMDIR="/mnt/user/microvms"
BRIDGE="br0"
DEFAULT_VMM="cloud-hypervisor"
FLINTLOCKD="disable"
FLINTLOCKD_GRPC_PORT="9090"
```

- INI format (parsed by both bash `source` and PHP `parse_ini_file`)
- All values quoted
- UPPER_CASE keys
- Form saves via Unraid's `update.php` with `#file` hidden field

---

## Key Patterns from References

### From Community Applications (Squidly271)
- **Single massive PHP backend** — one file handles all AJAX commands
- **`$_POST['action']`** dispatch pattern
- **No OOP** — procedural PHP, functions in include files
- **Direct `exec()`** for system operations
- **`pkg_build.sh`** for creating `.txz`/`.tgz` packages
- **Icon in CA** via `apps.txt` metadata

### From Fix Common Problems (Squidly271)
- **Scan-and-report pattern** — check system state, report issues
- **Pure PHP** (99.2%) — minimal JS
- **Scheduled execution** via cron/event hooks
- **Flash-based config** at `/boot/config/plugins/{name}/`

### From dynamix.vm.manager (Unraid built-in)
- `context.attach('#vm-ID', opts)` for right-click menus
- `loadlist()` for AJAX table refresh
- `addVMContext()` builds menu by state
- `mk_option()` helper for `<select>` options

### From Tailscale Plugin
- Service management in rc.d script
- JSON-based state tracking
- Event handlers for array start/stop
- Separate binary management (download + cache on flash)
