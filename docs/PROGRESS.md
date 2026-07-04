# Progress Log — MicroVM Liquidmetal Plugin for Unraid

## v2026.07.04 — Orchestrated Mode (Current)

### Commits
- `2bd7aeb` feat: local OCI registry in boot sequence (crane --disk)
- `995a5f6` fix: containerd root on persistent storage, flintlockd gRPC proven working
- `6a7a390` feat: Docker-style create popup, ACPI stop, delete protection, UI cleanup
- `c874813` fix: TAP ID collision - auto-assign unique tap_id per VM
- `dbaf625` fix: UTF-16 encoding bug in MicroVMAdmin.php, vmAction log panel
- `20262ff` fix: TAP numeric naming, kernel version detection, UI improvements
- `f1eff58` refactor: microvm.liquidmetal v2026.07.04.1 — orchestrated mode

### ✅ Working & Verified

#### Boot Sequence (7 steps)
1. `dm_thin_pool` kernel module loaded
2. Thin pool setup (50GB sparse data + 500MB meta, loop-backed)
3. `flintlock-containerd` v1.7.27 (devmapper snapshotter, persistent root)
4. Local OCI registry (crane serve --disk on :5050, auto-pushes kernels)
5. `flintlockd` v0.9.0 (gRPC on :9090, CH + FC providers)
6. TAP interfaces (auto-created from VM configs)
7. Autostart VMs (reads config.json autostart flag)

#### Flintlockd gRPC Orchestration
- `grpcurl` installed and working on Unraid
- `CreateMicroVM` → containerd pull → thin pool snapshot → CH v52 boots ✅
- `GetMicroVM` → returns spec + status ✅
- `ListMicroVMs` → lists by namespace ✅
- `DeleteMicroVM` → by UID ✅
- **CH v52.0 confirmed fully API-compatible with flintlock v0.9.0**
- Kernel OCI image: `localhost:5050/kernel/ch:latest` (Linux 6.2.0, PVH)
- Rootfs OCI image: any Docker/OCI image (e.g. `docker.io/library/alpine:3.18`)

#### WebGUI — Direct Mode (current UI path)
- **Create VM**: SweetAlert progress popup (Docker-style), shows engine/API info
- **Start VM**: Direct via rc.microvm → cloud-hypervisor/firecracker
- **Stop VM**: ACPI power-button with 90s timeout, Force Stop popup if no response
- **Delete VM**: Blocked if running, blocked if has snapshots, handles FUSE artifacts
- **Console**: Opens in popup via ttyd (silent, no bottom log)
- **Snapshot**: Cloud Hypervisor snapshots working (create/restore/delete)
- **Resize**: Hot-add CPU/RAM via ch-remote (CH only)
- **Autostart**: switchButton toggle, starts VM on create if ON
- **TAP naming**: `tap{id}@br0` with auto-assigned unique IDs
- **Kernel detection**: `grep -aoP` for version from vmlinux binary
- **Settings page**: FV3-style, Basic/Advanced toggle, VIEW LOG buttons

#### Binaries Installed
| Binary | Version | Path |
|--------|---------|------|
| cloud-hypervisor | v52.0 | /usr/local/bin/cloud-hypervisor |
| ch-remote | v52.0 | /usr/local/bin/ch-remote |
| firecracker | v1.16.0 | /usr/local/bin/firecracker |
| flintlockd | v0.9.0 | /usr/local/bin/flintlockd |
| flintlock-containerd | v1.7.27 | /usr/local/bin/flintlock-containerd |
| crane | v0.21.7 | /usr/local/bin/crane |
| grpcurl | v1.9.1 | /usr/local/bin/grpcurl |
| ttyd | v1.7.7 | /usr/local/bin/ttyd |

#### Kernels
| Engine | Version | Path | OCI Registry |
|--------|---------|------|--------------|
| Cloud Hypervisor | Linux 6.2.0 (PVH) | /mnt/user/system/liquidmetal/cloud-hypervisor/kernels/vmlinux | localhost:5050/kernel/ch:latest |
| Firecracker | Linux 5.10.225 | /mnt/user/system/liquidmetal/firecracker/kernels/vmlinux | localhost:5050/kernel/fc:latest |

#### Compatibility Matrix
| Component | Version | Compatible With |
|-----------|---------|----------------|
| flintlockd | v0.9.0 | Firecracker v1.11+ ✅, Cloud Hypervisor v41+ ✅ |
| flintlock-containerd | v1.7.27 | flintlockd v0.9.0 ✅ |
| cloud-hypervisor | v52.0 | flintlockd v0.9.0 ✅ (API unchanged at /api/v1/) |
| firecracker | v1.16.0 | flintlockd v0.9.0 ✅ |

---

### 🚧 Not Yet Wired (Next Steps)

1. **Test WebGUI → flintlockd**: The PHP backend is fully wired (auto-detects flintlockd). Need to test from browser.
2. **UI shows gRPC calls**: Progress popup should display actual flintlockd API calls in dark log panel
3. **PLG installer**: Not tested (manual install so far)
4. **nchan real-time**: VM status streaming via ListMicroVMsStream
5. **macvtap support**: Available in flintlockd, not exposed in UI
6. **Upgrade containerd**: v1.7.27 → v1.7.33 (latest LTS, security patches, exact SDK match)

---

### 📋 Containerd Version Research

| Question | Answer |
|----------|--------|
| Current binary | flintlock-containerd v1.7.27 |
| Flintlock go.mod dependency | containerd v1.7.33 (client SDK) |
| Highest compatible | **v1.7.33** (any 1.7.x works) |
| containerd 2.x compatible? | ❌ NO — different Go module path, API changes |
| Upgrade benefit | Security CVE fixes (6 patches), exact SDK match |
| Upgrade risk | None — drop-in replacement, same config/socket/API |
| 1.7.x LTS support | Until September 2026 |

---

### 🐛 Known Issues
- Thin pool teardown sometimes fails if devices are busy
- flintlockd kernel image must have PVH header for CH (stock liquidmetal images don't work)
- Busybox VMs don't respond to ACPI power-button (no acpid)

---

## Pre-refactor versions (microvm.manager)

### v70-v71
- FC snapshot support (create/restore/delete)
- Code cleanup, PHPDoc headers

### v60-v69
- Firecracker integration, dual-engine
- Context menu, serial console, live resize

### v50-v59
- CH snapshot/restore, OCI pull via crane, rootFS page

### v40-v49
- Initial CH integration, WebGUI, TAP networking, autostart

---

## Architecture

```
WebGUI → MicroVMAdmin.php → grpcurl → flintlockd:9090 (gRPC)
                                            │
                              ┌─────────────┴─────────────┐
                              ▼                           ▼
                     Cloud Hypervisor v52       Firecracker v1.16
                              │                           │
                              ▼                           ▼
                     flintlock-containerd v1.7.27
                     (devmapper snapshotter → thin pool)
                              │
                              ▼
                     Local OCI Registry (crane :5050)
                     /mnt/user/system/liquidmetal/crane/registry/
```

## Source Repos (D:/github/)
- `flintlock` — flintlockd source (Go)
- `cloud-hypervisor` — CH source (Rust)
- `containerd` — containerd source (Go)
- `image-builder` — Liquidmetal kernel/OS image builder
