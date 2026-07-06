# Progress Log — microVMs Plugin for Unraid

## v2026.07.06 — Current (Verified Working)

### ✅ Full Plugin Lifecycle
- Install from UI ✓, Uninstall ✓, Reinstall ✓, Reboot ✓
- No Unraid corruption (event/array_started pattern)
- Binaries cached on flash, persist reboots

### ✅ VM Operations (Cloud Hypervisor)
- Create (Thin Pool) ✓ — `ctr images mount` + init injection + LAN reachable
- Create (Raw rootFS) ✓ — `crane export` + dd + mkfs + init injection + LAN reachable
- Start ✓, Force Stop ✓, Resize (hot CPU/RAM) ✓
- Snapshot (create/restore/delete) ✓
- Console (ttyd) ✓, Info (JSON popup) ✓, Logs ✓
- Delete microVM & rootFS ✓ (blocked if running/has snapshots)
- Autostart on array start ✓
- TAP ID reuse (lowest available) ✓
- Network: kernel ip= autoconfigure + custom /init ✓

### ✅ VM Operations (Firecracker)
- Create (Thin Pool) ✓ — boots successfully
- Create (Raw rootFS) ✓ — boots + network works
- Start ✓, Stop ✓
- Binary v1.16.1 (correct non-debug variant) ✓

### ✅ Settings Page
- Sub-page tabs: General, Cloud Hypervisor, Firecracker, Liquidmetal
- Status tree display (service health at a glance)
- Service controls: Stop/Start/Restart/View Log
- Per-VMM enable/disable
- Devmapper enable/disable (with guard: blocks if VMs use thin)
- Auto-enable VMM when set as default

### ✅ Storage
- Thin Pool: `ctr images mount --snapshotter devmapper --rw` (full containerd OCI flow)
- Raw rootFS: `crane export` → dd → mkfs → extract
- Both inject custom `/init` with OCI ENTRYPOINT/CMD
- CH disk: `image_type=raw` (required for devmapper writes in v52)
- Separate namespaces per VMM (matches flintlock design)
- Image ref normalization for containerd

### ✅ Init Script Injection
- Reads ENTRYPOINT + CMD from `crane config` at create time
- Shell-escapes each arg (`escapeshellarg`)
- Mounts /proc, /sys, /dev, /dev/pts
- Parses kernel `ip=A::G:M:::off` → configures eth0
- Sets DNS (8.8.8.8, 1.1.1.1)
- `exec` the OCI entrypoint (nginx, redis, httpd, etc.)

### ✅ Critical Bugs Fixed
| Bug | Fix |
|-----|-----|
| Unraid shares disappear on reboot | event/array_started (no /mnt/user at boot) |
| CH thin pool read-only disk | `image_type=raw` flag |
| CH root device `\/dev\/vda` | sed unescape JSON backslashes |
| FC binary segfault | Extract correct non-.debug binary (v1.16.1) |
| containerd false positive | `pidof` instead of `pgrep -f` |
| $_POST empty in sub-pages | `$_REQUEST` |
| VMM always shows CH | Determine by config filename |
| Thin pool "no layers found" | Use `ctr images mount` (handles unpack) |
| CMD quoting (`daemon off;`) | `escapeshellarg` each array element |
| Image ref rejected | Normalize to `docker.io/library/...` |
| Snapshot already exists | Remove stale before prepare |

### Binaries
| Binary | Version | Status |
|--------|---------|--------|
| cloud-hypervisor | v52.0 | ✅ Working |
| ch-remote | v52.0 | ✅ Working |
| firecracker | v1.16.1 | ✅ Working |
| microvms-containerd | v1.7.27 | ✅ Working |
| flintlockd | v0.9.0 | ✅ Working (optional) |
| crane | v0.21.7 | ✅ Working |
| grpcurl | v1.9.1 | ✅ Working |
| ttyd | v1.7.7 | ✅ Working |

---

## Remaining Work

1. **Per-VM Logs button** — show /var/log/microvms/{vmm}/{name}.log in context menu
2. ~~**Storage tab** — "Prune Unused Images" button, image/snapshot inventory~~ ✅ Done
3. **TLS/auth for flintlockd** — deferred
4. ~~**Max memory config** — currently hardcoded as memory×2~~ ✅ Done (configurable via Add VM form)
5. **macvtap label** — form says macvtap but uses TAP+bridge (cosmetic)
6. **Update root README.md** — for GitHub

---

## Pre-refactor versions

### v70-v71 — FC snapshot, code cleanup
### v60-v69 — Firecracker, dual-engine, context menu, console, resize
### v50-v59 — CH snapshot/restore, OCI pull, rootFS page
### v40-v49 — Initial CH, WebGUI, TAP networking, autostart
