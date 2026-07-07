# PLAN-NEXT — Remaining Work

## Done (this session) ✅
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

---

## Remaining

### Priority 1: Bugs
- [ ] Thin pool create hangs sometimes (devmapper error snapshots block ctr mount)
  - Add timeout (60s) to pull/mount step
  - Pre-check if image already exists
  - Add "Cancel" button to create progress UI
  - Clean error snapshots on service start
- [ ] IP address collision (no check for used IPs on create)
  - Scan configs for used IPs before allocating

### Priority 2: Features
- [ ] CH virtiofs (host path sharing)
  - virtiofsd already on Unraid — just need `--fs` flag + `--memory shared=on`
  - Add "Shared Folders" field to Add form (host path → guest mount tag)
- [ ] IPAM (IP Address Management)
  - Auto-suggest next available IP from subnet
  - Gateway auto-fill from bridge config
- [ ] Storage tab improvements
  - Image inventory from both namespaces
  - Snapshot list with associated VMs
  - Verify Prune button response handling
- [ ] Update root README.md for GitHub

### Priority 3: Future
- [ ] Custom Rust init (replace shell script, inspired by superfly/init-snapshot)
- [ ] Multi-container per VM (Fly.io rate-limiter-demo pattern)
- [ ] TLS/auth for flintlockd
- [ ] Community Applications submission
- [ ] Multi-NIC support
- [ ] VM migration between hosts
- [ ] Suspend/Resume (Lambda snapshot pattern)

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
```
