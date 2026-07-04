# Code Style Guide — microVM Manager Plugin

## References
- **Unraid WebGUI** (`dynamix.vm.manager`): Page structure, context menu, CSS patterns
- **Tailscale plugin** (`unraid-tailscale`): GPL header, namespacing, OOP for utils
- **ZFS Master** (`ZFS-Master-Unraid`): AJAX backend, SweetAlert2, frontend/backend split

---

## File Headers

Every PHP file must have:
```php
<?php
/*
 * microVM Manager for Unraid
 * Copyright (C) 2026
 *
 * This program is free software: GPL-2.0
 * See LICENSE file for details.
 *
 * File: filename.php
 * Description: Brief description of this file's purpose
 */
```

---

## PHP Conventions

### Naming
- Functions: `microvm_snake_case()` (prefix with `microvm_`)
- Variables: `$camelCase` for local, `$UPPER_CASE` for constants
- Config keys: `UPPER_CASE` in .cfg files

### Functions
- Every function must have a PHPDoc comment:
```php
/**
 * Start a microVM by name.
 *
 * @param string $name VM name (lowercase, hyphens allowed)
 * @return array ['success' => bool, 'output' => string]
 */
function microvm_start_vm($name) { ... }
```

### Error Handling
- Always return structured arrays: `['success' => bool, 'error' => string]`
- Use `escapeshellarg()` for all shell inputs
- Use `json_encode()` for all JSON responses
- Set `Content-Type: application/json` header in AJAX handlers

### Security
- Sanitize POST inputs: `$name = preg_replace('/[^a-z0-9\-]/', '', ...)`
- Never interpolate user input into shell commands without escaping
- Check VM exists before operations

---

## JavaScript Conventions

### Structure in .page files
```html
<script>
// ============================================================
// microVM Manager - Page Name
// ============================================================

var plugin = '<?=$plugin?>';

// --- Context Menu ---
function addMicroVMContext(name, state, engine) { ... }

// --- VM Actions ---
function vmAction(action, name) { ... }
function vmConsole(name) { ... }
function vmResize(name, engine) { ... }

// --- Snapshot Management ---
function vmSnapshots(name) { ... }
function vmSnapshotRestore(name, tag) { ... }
function vmSnapshotDelete(name, tag) { ... }

// --- Utilities ---
function escapeHtml(str) { ... }
function showOutput(msg) { ... }

// --- Init ---
$(function() {
  // switchButton, event bindings, etc.
});
</script>
```

### Naming
- Functions: `camelCase`
- jQuery selectors: cache in variables for reuse
- Use `swal()` for confirmations (Unraid includes SweetAlert)
- Use `$.post()` for AJAX (jQuery always available)

---

## Page Structure (.page files)

```
Menu="..."
Title="..."
Tag="..."
Cond="..."
Markdown="false"
---
<?php /* PHP logic */ ?>

<!-- CSS includes -->
<link ...>
<script src="..."></script>

<!-- Custom CSS -->
<style>
/* Section: Layout */
/* Section: Components */
</style>

<!-- HTML content -->
<table>...</table>

<!-- JavaScript -->
<script>
// Organized by section (see above)
</script>
```

---

## Backend (MicroVMAdmin.php)

### Structure
```php
<?php
// Header comment
// Requires
// Config loading
// Input sanitization

// Command dispatcher (switch)
switch ($cmd) {
    case 'list': ...
    case 'start': ...
    case 'stop': ...
    // ... grouped by category
}
```

### Command categories:
1. **Lifecycle**: list, start, stop, force_stop
2. **Info**: info, logs, logs_terminal
3. **Resize**: resize (CH only)
4. **Snapshots**: snapshot, list_snapshots, delete_snapshot, restore_snapshot
5. **Console**: console, console_stop
6. **CRUD**: create, create_json, delete, delete_rootfs, pull_rootfs
7. **Config**: autostart, service

---

## Shell Scripts (rc.microvm)

### Structure
```bash
#!/bin/bash
# /etc/rc.d/rc.microvm - microVM Manager service script
# Description: Start/stop microVM instances
# Depends: cloud-hypervisor, firecracker, bridge-utils

# --- Configuration ---
PLUGIN="microvm.manager"
CFGFILE="..."

# --- Helpers ---
log() { ... }
resolve_path() { ... }

# --- VM Operations ---
start_vm() { ... }
stop_vm() { ... }

# --- Service ---
microvm_start() { ... }
microvm_stop() { ... }

# --- Main ---
case "$1" in ...
```

---

## Directory Structure (target)

```
src/usr/local/emhttp/plugins/microvm.manager/
├── MicroVMs.page              # Parent tab container
├── MicroVMMachines.page       # Tab 1: VM list (context menu, table)
├── MicroVMRootFS.page         # Tab 2: rootFS management
├── MicroVMStats.page          # Tab 3: Usage statistics
├── MicroVMSettings.page       # Settings page
├── AddMicroVM.page            # Create VM form
├── backend/
│   └── MicroVMAdmin.php       # AJAX command handler
├── include/
│   └── common.php             # Shared PHP functions
├── images/
│   ├── cloud-hypervisor.png   # Engine icon
│   └── firecracker.png        # Engine icon
├── microvm.manager.png        # Plugin settings icon
├── microvm.manager.svg        # SVG source
├── console.html               # Standalone console page (fallback)
├── microvm-console            # PTY helper script
├── start.sh                   # Service start
├── stop.sh                    # Service stop
└── restart.sh                 # Service restart
```

---

## Key Patterns from References

### From VMs page (dynamix.vm.manager)
- `context.attach('#vm-ID', opts)` for right-click menus
- `$.cookie()` for user preferences
- `loadlist()` pattern for AJAX refresh
- `addVMContext()` builds menu dynamically based on state

### From ZFS Master
- `backend/ZFSMAdmin.php` — single AJAX entry point
- SweetAlert2 for all confirmations
- `refreshData()` after operations
- Lua scripts for complex operations

### From Tailscale
- Composer autoload for PHP dependencies
- Event handlers (`event/` directory)
- JSON-based configuration
- Separate utility classes per concern
