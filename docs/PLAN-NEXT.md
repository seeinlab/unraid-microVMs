# PLAN-NEXT — Remaining Work

## Done (this session) ✅
- [x] Namespace management (default/ch/fc auto-created, flintlock reserved)
- [x] VM paths migrated to $VMDIR/{namespace}/{name}/
- [x] CH snapshot restore fixed (API method, not CLI --restore flag)
- [x] CH console after restore fixed (direct PTY write, correct .serial.log filename)
- [x] FC snapshot/restore verified working
- [x] Storage tab unified (no more CH/FC split)
- [x] Add form has namespace dropdown (auto-selects ch/fc based on VMM)
- [x] Settings shows namespace list with roles

## Previously Completed ✅
- [x] /fly/run.json + catatonit init refactor (Fly.io pattern)
- [x] Console rework: output box + terminal input (no more ttyd popup)
- [x] FC snapshots enabled (create + restore + list)
- [x] CH Edit form with proper max limits (sliders)
- [x] Max vCPUs field in Add form (CH-only, hidden for FC)
- [x] TAP reuse: delete on destroy + orphan cleanup on start
- [x] Prune button fixed (--all flag)
- [x] Autostart default Yes
- [x] Rename: Container Image, Remove microVM, Create Snapshot, Edit
- [x] Single-script rootfs creation (Unraid mount namespace fix)
- [x] Kernel ip= for network (no iproute2 dependency)
- [x] virtiofsd confirmed on Unraid (v1.13.1, ready to use)
- [x] Containerd namespace merge (per-VMM → unified default namespace)

---

## Remaining

### Priority 1: Bugs
- [ ] `/var/log` tmpfs fills up (128MB) — flintlockd.log grows unbounded
  - Truncate flintlockd.log on service start or limit verbosity
  - Move microvms logs to persistent storage (e.g. /mnt/user/appdata/microvms/logs/)
  - Causes console to break (serial log can't write)
- [ ] Liquidmetal enable doesn't auto-start crane registry + flintlockd
  - Toggling Liquidmetal to enabled in Settings and clicking Apply doesn't start services
  - Check: `#command` might not trigger restart, or FLINTLOCKD config value isn't read correctly after save
- [x] ~~Flintlockd deadlocks on second VM creation~~ — FIXED: stale containerd leases. Clean leases before start.
- [ ] **Flintlockd macvtap not supported on Unraid** (by design)
  - Unraid bonds all NICs into br0; macvtap needs standalone physical NIC
  - Always use type=1 (TAP/bridge) for flintlockd on Unraid
- [ ] IP address collision on create (no check for used IPs)
  - Scan configs for used IPs before allocating
- [ ] ctr client v2.2.3 vs server v1.7.27 version mismatch warnings
  - Cosmetic but noisy — investigate if newer ctr binary is available for Unraid
- [x] ~~CH serial capture inconsistency~~ — FIXED: console_input writes directly to PTY (bypasses FIFO)
  - Normal start: `tail -f FIFO → PTY` (works)
  - Restore: direct PTY write (different approach)
  - Should unify to one approach for maintainability
- [ ] VM list should detect running processes (not just config files)
  - Scan pidof + /proc/PID/cmdline for running VMs without config = show as "orphan"
  - Allow Force Stop on orphans from UI
  - Prevents devmapper lock-up from invisible processes
- [ ] Thin pool create hangs sometimes (devmapper error snapshots block ctr mount)
  - Add timeout (60s) to pull/mount step
  - Pre-check if image already exists
  - Add "Cancel" button to create progress UI
  - Clean error snapshots on service start
  - Kill orphan VMM processes on service start

### Priority 2: Features
- [ ] CH virtiofs (host path sharing)
  - virtiofsd already on Unraid — just need `--fs` flag + `--memory shared=on`
  - Add "Shared Folders" field to Add form (host path → guest mount tag)
- [ ] IPAM (IP Address Management)
  - Auto-suggest next available IP from subnet
  - Gateway auto-fill from bridge config
- [x] ~~Storage tab improvements~~ — FIXED: full redesign (Thin Pool status bars, Volumes table, Images table, Pull & Convert, Run GC, per-namespace)
- [ ] Create from JSON — full create flow (pull image, create rootfs, inject init/env/mounts, register containerd, autostart)
  - Currently `create_json` only writes config file to disk, doesn't build VM
  - Should reuse same logic as `create` command but accept a JSON config object
- [ ] Update root README.md for GitHub

### Priority 3: Future
- [ ] Multi-container per VM (Fly.io rate-limiter-demo pattern)
- [ ] TLS/auth for flintlockd
- [ ] Community Applications submission
- [ ] Multi-NIC support
- [ ] VM migration between hosts
- [ ] Suspend/Resume as user-facing feature (CH: ch-remote pause/resume, FC: PATCH /vm state. Add `paused` state + Pause/Resume buttons in context menu)
- [ ] OpenBao/Vault secrets integration (encrypted secrets layer, resolved at VM start, not baked into rootfs)
- [ ] Add/Edit form UI polish — 1:1 field mapping with JSON config (bidirectional: UI↔JSON editable both ways)

---

## Development Notes

### Deploy
```bash
cat src/.../file | ssh -i ~/.ssh/mastervault root@192.168.50.6 'cat > /path'
```

### Testing Images
| Image | Size | Test Case |
|-------|------|-----------|
| nginx:alpine | ~40MB | Web server, ENTRYPOINT+CMD |
| nginx:stable-trixie | ~180MB | Debian-based, needs 256MB+ disk |
| alpine:3.20 | ~7MB | Minimal, shell-only |
| redis:alpine | ~30MB | Non-HTTP service |

### Key Architecture
```
Guest rootfs:
  /fly/init         ← generic init (reads run.json, sets up DNS/hostname)
  /fly/run.json     ← per-VM config (entrypoint, cmd, console, network)
  /sbin/catatonit   ← PID 1 (signal forwarding + zombie reaping)
  /init → /fly/init ← symlink for kernel

Host:
  kernel cmdline: console=ttyS0 root=/dev/vda rw init=/fly/init ip=A::G:M:::off
  /fly/run.json network section: DNS only (kernel handles IP)

VM Directory Layout:
  $VMDIR/{namespace}/{name}/       ← per-VM directory
  $VMDIR/{namespace}/{name}/cloud-hypervisor.json  ← CH config
  $VMDIR/{namespace}/{name}/firecracker.json       ← FC config

Namespaces:
  default   ← user VMs (containerd namespace for images/containers)
  ch        ← auto-created for Cloud Hypervisor VMs
  fc        ← auto-created for Firecracker VMs
  flintlock ← reserved for Liquidmetal orchestration
```
