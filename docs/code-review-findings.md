# Code Review Findings — MicroVM Manager Plugin

**Date:** 2026-07-04  
**Files Reviewed:**
- `backend/MicroVMAdmin.php`
- `include/common.php`
- `MicroVMMachines.page`
- `/etc/rc.d/rc.microvm`
- `docs/feature-api-mapping.md` (reference)

---

## Critical Severity

### 1. [CRITICAL] Command Injection in `force_stop` — unescaped `$name` in shell commands

**File:** `MicroVMAdmin.php` (lines ~42-47)  
**Issue:** The `$name` variable from `$_POST['name']` is used directly in `pgrep -f 'microvm-{$name}'` and `kill -9 $pid` without sanitization. While `$pid` comes from pgrep output (numeric), the `$name` is injected raw into the pgrep pattern string.

```php
$pid = trim(shell_exec("pgrep -f 'microvm-{$name}' 2>/dev/null | head -1"));
if ($pid) {
    exec("kill -9 $pid 2>&1");
```

**Impact:** A crafted `name` like `' ; rm -rf / ;'` could potentially execute arbitrary commands. The single quotes provide some protection, but escaping is still needed.

**Fix:** Use `escapeshellarg()` on `$name` in all shell commands, or validate name format before use (as done in the `create` handler with `preg_replace`).

---

### 2. [CRITICAL] Command Injection in `create` — unescaped variables in rootfs creation

**File:** `MicroVMAdmin.php` (lines ~142-145)  
**Issue:** When creating rootfs, several commands use unescaped `$rootfs` and `$name`:

```php
exec("dd if=/dev/zero of=$rootfs bs=1M count=$diskSize 2>/dev/null");
exec("mkfs.ext4 -F $rootfs 2>/dev/null");
exec("mount $rootfs /tmp/microvm-mount-$name");
exec("tar -xf $tmpTar -C /tmp/microvm-mount-$name 2>&1");
```

**Impact:** Although `$name` is sanitized earlier via `preg_replace`, `$rootfs` is derived from `$vmPath` which uses `$vmdir` (from config, generally safe). If `$diskSize` were not cast to int, or if paths have spaces, commands would break. The same pattern exists in `pull_rootfs`.

**Fix:** Use `escapeshellarg()` on all path variables in exec calls.

---

### 3. [CRITICAL] Undefined Variable Reference in `microvm_list_vms()` — `$vm['name']`

**File:** `include/common.php` (line ~41)  
**Issue:** Inside the Firecracker branch of `microvm_list_vms()`:

```php
$running = !empty(trim(shell_exec("pgrep -f 'microvm-{$vm['name']}' 2>/dev/null")));
```

The variable `$vm` does not exist in this scope — it should be `$name`. The `$vm` array is only built later. This will always produce a PHP notice and an incorrect pgrep pattern like `pgrep -f 'microvm-'`.

**Impact:** Firecracker VMs will always show as `stopped` in the UI regardless of actual state.

**Fix:** Change `$vm['name']` to `$name`.

---

## High Severity

### 4. [HIGH] Memory resize stores wrong value in config.json

**File:** `MicroVMAdmin.php` (line ~84)  
**Issue:** 

```php
$vmConfig['memory_mb'] = intval($memory) / 1048576; // bytes to MB
```

The frontend sends memory in bytes (e.g., 536870912 for 512MB). Dividing by 1048576 gives MB correctly. However, `ch-remote resize --memory` expects bytes, but the `microvm_resize_vm()` function calls `intval($memory)` and passes that to ch-remote. The comment says "bytes to MB" — the *result* stored in config is correct.

**BUT:** The condition check is wrong:
```php
if (!empty($result['memory']) && $memory) {
```

`$result['memory']` is a boolean (from `microvm_resize_vm`). If ch-remote fails, `$result['memory']` is `false`, but `!empty(false)` is `true`... Actually no, `!empty(false)` is `false`. So this is correct. The real issue is:

**The resize function's `$memory` parameter is ambiguous** — it's passed as raw bytes from the frontend to ch-remote, but the config stores MB. If someone calls resize with MB (as the config stores), ch-remote will get the wrong value (MB instead of bytes). There's no validation or documentation of the expected unit.

**Fix:** Add explicit unit handling: validate the memory parameter is in bytes before passing to ch-remote, and clearly document the interface contract.

---

### 5. [HIGH] `pull_rootfs` command — no `escapeshellarg` on file paths in exec calls

**File:** `MicroVMAdmin.php` (lines ~283-292)  
**Issue:** Same as finding #2 — `$rootfs` and `$pullName` used raw in exec:

```php
exec("dd if=/dev/zero of=$rootfs bs=1M count=$diskSize 2>/dev/null");
exec("mkfs.ext4 -F $rootfs 2>/dev/null");
exec("mount $rootfs /tmp/microvm-mount-$pullName");
exec("tar -xf $tmpTar -C /tmp/microvm-mount-$pullName 2>&1");
exec("umount /tmp/microvm-mount-$pullName");
```

While `$pullName` is sanitized with `preg_replace`, paths with special characters could still cause issues.

**Fix:** Use `escapeshellarg()` consistently.

---

### 6. [HIGH] Race condition in `create` — rootfs mounted but not unmounted on crane failure

**File:** `MicroVMAdmin.php` (create handler)  
**Issue:** If the crane export succeeds but subsequent commands (dd, mkfs, mount, tar) fail mid-way, the mount point `/tmp/microvm-mount-$name` may be left mounted or partially created. There's no error checking between `exec("mount...")` and `exec("tar...")`.

**Fix:** Check return values of mount/dd/mkfs commands and clean up on failure.

---

### 7. [HIGH] `delete` command — allows deletion even if VM has no snapshots check race

**File:** `MicroVMAdmin.php` (delete handler, lines ~106-109)  
**Issue:** `glob("$snapDir/*")` is called twice:

```php
if (is_dir($snapDir) && count(glob("$snapDir/*")) > 0) {
    $snapCount = count(glob("$snapDir/*"));
```

This is a TOCTOU (time-of-check-time-of-use) issue — a snapshot could be deleted between the two calls, making the count incorrect. Not exploitable in practice, but wasteful.

**Fix:** Call glob once and store the result.

---

### 8. [HIGH] `console_stop` uses wrong pid file path

**File:** `MicroVMAdmin.php` (console_stop handler, line ~224)  
**Issue:** 

```php
$pidFile = "/tmp/ttyd-microvm-{$name}.pid";
```

But the `console` handler writes the pid file to:
```php
$pidFile = "/var/tmp/ttyd-microvm-{$name}.pid";
```

The `console_stop` command checks `/tmp/` but the console handler writes to `/var/tmp/`. This means `console_stop` will never find the pid file and will always report "No active console relay."

**Impact:** Console relay processes can never be stopped via the `console_stop` API command.

**Fix:** Change `console_stop` to use `/var/tmp/ttyd-microvm-{$name}.pid`.

---

### 9. [HIGH] `force_stop` uses wrong ttyd pid file path

**File:** `MicroVMAdmin.php` (force_stop handler, line ~39)  
**Issue:** 

```php
$ttydPid = "/tmp/ttyd-microvm-{$name}.pid";
```

But the `console` handler stores the pid in `/var/tmp/ttyd-microvm-{$name}.pid`. Same mismatch as #8.

**Impact:** Force-stopping a VM won't kill its associated console relay.

**Fix:** Change to `/var/tmp/ttyd-microvm-{$name}.pid`.

---

## Medium Severity

### 10. [MEDIUM] Kernel path inconsistency between `create` and `rc.microvm`

**File:** `MicroVMAdmin.php` (create handler) vs `rc.microvm`  
**Issue:** The `create` handler sets:

```php
$kernel = "$vmdir/kernels/vmlinux";
```

But `rc.microvm` overrides with:
```bash
kernel="${VMDIR}/kernels/${engine}/vmlinux"
```

And the MicroVMMachines.page checks:
```php
$kernel_exists = is_file("$vmdir/kernels/cloud-hypervisor/vmlinux") || is_file("$vmdir/kernels/firecracker/vmlinux");
```

The config.json will store the wrong kernel path (`kernels/vmlinux` vs `kernels/cloud-hypervisor/vmlinux`). The rc.microvm script silently overrides this anyway, but it means the config.json contains stale/wrong data.

**Fix:** Change the `create` handler to use `"$vmdir/kernels/$engine/vmlinux"`.

---

### 11. [MEDIUM] `microvm_pull_oci_image()` in common.php uses hardcoded mount path

**File:** `include/common.php` (lines ~175-185)  
**Issue:** The function uses a fixed mount path `/tmp/microvm-mount` without a unique identifier:

```php
exec("mkdir -p /tmp/microvm-mount && mount $outputPath /tmp/microvm-mount");
```

If two images are pulled concurrently, they will collide at this mount point. The `pull_rootfs` command in MicroVMAdmin.php correctly uses `/tmp/microvm-mount-$pullName`.

Also, this function appears to be dead code — the `create` and `pull_rootfs` commands in MicroVMAdmin.php implement their own OCI pull logic inline rather than calling this function.

**Fix:** Either remove the dead code or refactor `create`/`pull_rootfs` to use it (with the mount path fixed).

---

### 12. [MEDIUM] `microvm_pull_oci_image()` has unescaped `$outputPath` in exec

**File:** `include/common.php` (line ~179)  
**Issue:**

```php
exec("dd if=/dev/zero of=$outputPath bs=1M count=500 2>/dev/null");
```

`$outputPath` is not escaped. Same for mount command.

**Fix:** Use `escapeshellarg()`.

---

### 13. [MEDIUM] Missing `$name` validation in most commands

**File:** `MicroVMAdmin.php`  
**Issue:** The `create` command sanitizes `$name` with `preg_replace('/[^a-z0-9\-]/', '', ...)`, but most other commands (`stop`, `start`, `info`, `resize`, `snapshot`, `delete`, etc.) use the raw `$_POST['name']` without any validation. An empty name or a name with special characters could cause unexpected behavior.

**Fix:** Add name validation at the top of the switch statement (after the `list` case) — reject requests with empty or invalid names early.

---

### 14. [MEDIUM] `resolve_path()` in rc.microvm is defined inside `start_vm()` — bash function scoping

**File:** `rc.microvm` (inside `start_vm()`)  
**Issue:** In bash, functions defined inside other functions are globally scoped. This isn't a bug per se, but `resolve_path()` is re-defined on every `start_vm` call. If multiple VMs start concurrently, this is fine (bash is single-threaded per script execution), but it's confusing.

Also, `resolve_path` only checks disks 1-4 and a few tier paths. If the user has more disks or uses an SSD pool, the kernel/rootfs won't be found.

**Fix:** Move `resolve_path` to top-level scope, and make the disk search more comprehensive (e.g., iterate `/mnt/disk*`).

---

### 15. [MEDIUM] rc.microvm `start_vm` — socket existence check is unreliable

**File:** `rc.microvm` (start_vm, line ~73)  
**Issue:**

```bash
[ -S "$sock" ] && return 0  # Already running
```

If a VM crashed but didn't clean up its socket file, this check will prevent starting the VM. A stale socket is not proof of a running process.

**Fix:** Also check if a process is using the socket (e.g., with `fuser` or checking if `ch-remote ping` succeeds).

---

### 16. [MEDIUM] Firecracker boot_args should use `console=hvc0` not `console=ttyS0`

**File:** `MicroVMAdmin.php` (create handler) and `rc.microvm`  
**Issue:** The `create` handler builds the cmdline as:

```php
'cmdline' => "console=ttyS0 root=/dev/vda rw init=/init ip=..."
```

For Firecracker, the correct serial console device is `hvc0` (virtio-console), not `ttyS0`. The `rc.microvm` script actually patches `hvc0` → `ttyS0` for Cloud Hypervisor:

```bash
local ch_cmdline=$(echo "$cmdline" | sed 's/console=hvc0/console=ttyS0/g')
```

But the config already has `ttyS0`, so this sed does nothing. Meanwhile, Firecracker VMs get `ttyS0` which is wrong for them.

The `feature-api-mapping.md` confirms Firecracker should use `console=hvc0`.

**Fix:** Store `console=hvc0` in config.json, and let rc.microvm convert to `ttyS0` for CH only (which it already tries to do). OR: store a generic cmdline and apply engine-specific console at start time.

---

### 17. [MEDIUM] JS `startAll()` and `stopAll()` fire all requests simultaneously

**File:** `MicroVMMachines.page`  
**Issue:** The PHP-generated JS calls `vmAction()` for each VM in a loop without delays. All AJAX requests fire simultaneously, and each calls `location.reload()` after 3 seconds. This could overload the system (especially with many VMs) and cause multiple page reloads.

```javascript
function startAll() {
  <?php foreach ($vms as $vm): ?>
    <?php if ($vm['state'] !== 'running'): ?>
    vmAction('start', '<?=htmlspecialchars($vm['name'])?>');
    <?php endif; ?>
  <?php endforeach; ?>
}
```

**Fix:** Serialize the requests (chain them with promises/callbacks) or add a delay between starts.

---

### 18. [MEDIUM] `delete` command claims it blocks deletion when snapshots exist, but the UI says "This will delete... all data (config, rootfs, snapshots)"

**File:** `MicroVMAdmin.php` (delete handler) vs `MicroVMMachines.page` (vmDelete JS)  
**Issue:** The backend blocks deletion if snapshots exist:
```php
if (is_dir($snapDir) && count(glob("$snapDir/*")) > 0) {
    echo json_encode(['success' => false, 'error' => "Cannot delete: VM has $snapCount snapshot(s)..."]);
```

But the UI confirmation dialog says: "This will delete the VM and all its data (config, rootfs, snapshots)."

This creates user confusion — the dialog implies snapshots will be deleted, but the backend refuses.

**Fix:** Either update the UI text to say "Remove snapshots first" or change the backend to allow deletion with snapshots (after user confirmation).

---

## Low Severity

### 19. [LOW] `microvm_list_vms()` doesn't escape shell in Firecracker pgrep (beyond the undefined var)

**File:** `include/common.php`  
**Issue:** Even after fixing the `$vm['name']` → `$name` bug, the pgrep pattern `'microvm-{$name}'` is not escaped. If a VM name contained regex special characters (unlikely given the create sanitizer, but possible for manually created VMs), pgrep could behave unexpectedly.

---

### 20. [LOW] `$output` variable reused without clearing in `microvm_resize_vm()`

**File:** `include/common.php` (microvm_resize_vm)  
**Issue:**

```php
exec("ch-remote ... resize --cpus " . intval($cpus) . " 2>&1", $out, $ret);
// ...
exec("ch-remote ... resize --memory " . intval($memory) . " 2>&1", $out, $ret);
```

The `$out` array accumulates across both exec calls. This doesn't affect behavior since only `$ret` is checked, but it's sloppy.

---

### 21. [LOW] Missing `Content-Type` header check in MicroVMAdmin.php

**File:** `MicroVMAdmin.php`  
**Issue:** The script reads from `$_POST` but doesn't verify the request method is POST or that it comes from a trusted origin (no CSRF token check). Unraid's emhttp typically provides session-level auth, but this endpoint could still be vulnerable to CSRF attacks within the same browser session.

---

### 22. [LOW] `microvm_load_config()` returns defaults that may not match INI file format

**File:** `include/common.php`  
**Issue:** The defaults are returned as an associative array if the cfg file doesn't exist. If the cfg file exists but is empty/corrupt, `parse_ini_file()` returns an empty array, and callers use `??` to get defaults. This works fine, but means the defaults are scattered across multiple locations (common.php defaults, MicroVMAdmin.php `??` chains, and rc.microvm defaults).

---

### 23. [LOW] `rc.microvm` uses `local` keyword in functions — fine for bash, but may not work with `sh`

**File:** `rc.microvm`  
**Issue:** The shebang is `#!/bin/bash` so `local` is valid. However, Unraid sometimes uses different shells for rc scripts. This is likely fine since Unraid ships bash.

---

### 24. [LOW] `vmDelete` JS doesn't handle the "has snapshots" error gracefully

**File:** `MicroVMMachines.page`  
**Issue:** When the backend returns an error that the VM has snapshots, the `vmAction` response handler just dumps the JSON to `showOutput`. There's no swal or user-friendly message.

Actually — `vmDelete` uses a direct `$.post` that doesn't parse the response for `success: false`. It just dumps to `showOutput` and reloads. The user might see the page reload without understanding why deletion failed.

**Fix:** Check `data.success` in the vmDelete callback and show a swal error if false.

---

### 25. [LOW] Dead code — `microvm_pull_oci_image()` function never called

**File:** `include/common.php`  
**Issue:** This function is defined but never called anywhere. The `create` and `pull_rootfs` commands in MicroVMAdmin.php implement OCI pulling inline.

**Fix:** Remove or use the shared function.

---

## Inconsistencies with feature-api-mapping.md

### 26. [MEDIUM] Feature-api-mapping shows Firecracker console as "Not supported" in context menu — but code shows it for FC too

**File:** `MicroVMMachines.page` JS context menu  
**Issue:** The feature mapping says Console should not appear for Firecracker VMs. The JavaScript code only shows resize/snapshot for CH but shows Console for ALL running VMs regardless of engine:

```javascript
if (state == 'running') {
    opts.push({text:'Console', icon:'fa-terminal', ...});  // Always shown
```

The backend does check and return an error, but the menu item should not appear for FC VMs at all.

**Fix:** Wrap the Console menu item in an `if (engine == 'cloud-hypervisor')` check.

---

### 27. [LOW] Feature-api-mapping says FC Info uses `GET /` via curl — but code uses `ch-remote info` for all

**File:** `include/common.php` (`microvm_get_vm_info`)  
**Issue:** The function always uses `ch-remote --api-socket $sock info` regardless of engine. For Firecracker, this will fail silently (ch-remote doesn't understand FC's API socket).

The `info` command in MicroVMAdmin.php falls back to returning config.json if ch-remote returns nothing, which masks this bug — FC users get config data, not live state.

**Fix:** Detect engine and use `curl --unix-socket` for Firecracker's API.

---

## Summary

| Severity | Count |
|----------|-------|
| Critical | 3 |
| High | 6 |
| Medium | 9 |
| Low | 9 |
| **Total** | **27** |

### Top Priority Fixes:
1. Fix undefined variable `$vm['name']` → `$name` in `common.php` (breaks FC state detection)
2. Fix pid file path mismatch (`/tmp/` vs `/var/tmp/`) in `console_stop` and `force_stop`
3. Add `escapeshellarg()` to all shell commands with user input
4. Validate `$name` early in MicroVMAdmin.php for all commands
5. Fix kernel path in `create` handler to use engine-specific directory
