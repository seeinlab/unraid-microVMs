# Progress Log — microVMs Plugin for Unraid

## v2026.07.05 — Current

### Architecture
```
WebGUI → MicroVMAdmin.php → rc.microvms → cloud-hypervisor/firecracker (Direct Mode)
                                              ↑
                              microvms-containerd (devmapper thin pool for rootfs)

Remote API → grpcurl → flintlockd:9090 → containerd → thin pool → CH/FC (Liquidmetal Mode)
                                              ↑
                              crane registry (127.0.0.1:5050, OCI images)
```

### Design Decisions
- **UI uses Direct Mode only** — no flintlockd in UI path
- **Flintlockd/Liquidmetal** — for remote/programmatic automation only (gRPC API)
- **containerd always starts** — manages thin pool device IDs for both modes
- **KVM independent** — only needs /dev/kvm (kernel module), does NOT require libvirt
- **VMM, not Engine** — terminology throughout
- **Sub-page tabs** — Settings split into General, Cloud Hypervisor, Firecracker, Liquidmetal
- **pidof for detection** — not pgrep (avoids Docker containerd false positive)
- **$_REQUEST in backend** — not $_POST (sub-page context strips POST body)
- **Devmapper optional** — can disable (raw file mode only)
- **Auto-enable VMM** — if set as default but disabled, auto-enables on restart

### What's Working ✅

#### Boot Sequence

**CRITICAL:** PLG install runs at boot BEFORE array. Services start AFTER array via start.sh.

```
PLG install (boot, before array):
  - Install binaries, symlinks, log dirs only
  - NO /mnt/user/ access, NO service start

Array start (start.sh → rc.microvms start):
  [pre] Check /dev/kvm + /mnt/user exists
  [1-2] If DEVMAPPER=enable: Load dm_thin_pool + setup thinpool
  [3/7] Start microvms-containerd ← always
  [4/7] Start crane registry     ← if FLINTLOCKD=enable
  [5/7] Start flintlockd         ← if FLINTLOCKD=enable
  [6/7] Re-create TAP interfaces
  [7/7] Autostart microVMs
```

#### Settings Page (Sub-page Tabs)
- **General**: Status box (tree), containerd control, Enable, Storage, Bridge, VMM, defaults, devmapper
- **Cloud Hypervisor**: Enable, Kernel URL
- **Firecracker**: Enable, Kernel URL
- **Liquidmetal**: flintlockd/registry control, Enable, Crane storage, gRPC port, extra flags

#### Direct Mode (WebGUI) ✅
- Create VM: SweetAlert progress popup, OCI pull via crane
- Start/Stop/Force Stop: CH (ACPI + timeout), FC (signal)
- Delete: blocked if running/has snapshots, cleans thin device
- Console: ttyd popup
- Snapshot: CH only (create/restore/delete)
- Resize: hot-add CPU/RAM (CH only)
- Storage: Thin Pool (devmapper) or Raw File (user choice)
- Infra-as-code config: `cloud-hypervisor.json` / `firecracker.json`

#### Liquidmetal Mode (Remote Automation) ✅
- CreateMicroVM → containerd pull → thin pool snapshot → CH/FC boots
- GetMicroVM, ListMicroVMs, DeleteMicroVM
- Kernel OCI images auto-pushed to crane registry on boot
- **NOT used by UI** — gRPC API for external tools only

### Naming & Paths
```
Plugin:          microvms
Service:         /etc/rc.d/rc.microvms
Config:          /boot/config/plugins/microvms/microvms.controlplane.cfg
System data:     /mnt/user/system/microvms/{containerd,cloud-hypervisor,firecracker,crane}/
VM data:         /mnt/user/microvms/{vm-name}/{cloud-hypervisor,firecracker}.json
Runtime:         /var/run/microvms/{containerd.sock,flintlockd.pid,...}
Logs:            /var/log/microvms/{containerd,flintlockd,registry,backend}.log
                 /var/log/microvms/{cloud-hypervisor,firecracker}/{vm-name}.log
VM sockets:      /tmp/microvms-{name}.sock
Thin pool:       /dev/mapper/microvms-thinpool
Console:         /usr/local/bin/microvms-console
```

### Binaries
| Binary | Version | Path |
|--------|---------|------|
| cloud-hypervisor | v52.0 | /usr/local/bin/cloud-hypervisor |
| ch-remote | v52.0 | /usr/local/bin/ch-remote |
| firecracker | v1.16.0 | /usr/local/bin/firecracker |
| microvms-containerd | v1.7.27 | /usr/local/bin/microvms-containerd |
| flintlockd | v0.9.0 | /usr/local/bin/flintlockd |
| crane | v0.21.7 | /usr/local/bin/crane |
| grpcurl | v1.9.1 | /usr/local/bin/grpcurl |
| ttyd | v1.7.7 | /usr/local/bin/ttyd |

### Kernels
| VMM | Version | Path |
|-----|---------|------|
| Cloud Hypervisor | Linux 6.2.0 (PVH) | /mnt/user/system/microvms/cloud-hypervisor/kernels/vmlinux |
| Firecracker | Linux 5.10.225 | /mnt/user/system/microvms/firecracker/kernels/vmlinux |

---

### 🚧 Next Steps

1. **Option C**: Replace dmsetup with `ctr snapshots` (containerd manages all device IDs)
2. **PLG installer**: Test full install from clean state (remove + reinstall)
3. **Upgrade containerd**: v1.7.27 → v1.7.33 (security patches, LTS)
4. **TLS/auth for flintlockd**: Auto self-signed cert + basic auth token

### 🐛 Known Issues (Fixed This Session)
- ~~pgrep -f false positives~~ → `pidof` for exact binary match
- ~~$_POST empty in sub-pages~~ → `$_REQUEST`
- ~~Registry PID file empty~~ → `pidof crane` + direct kill
- ~~Buttons inside markdown form~~ → moved outside form as plain HTML
- ~~Basic/Advanced toggle cosmetic bugs~~ → removed, single flat form per tab

### 🐛 Remaining Issues
- Containerd start slow on FUSE filesystem (BoltDB lock, timeout 30s)
- ACPI shutdown fails on VMs without acpid — Force Stop works
- Thin pool teardown fails if devices busy
- Stale BoltDB locks after unclean shutdown

---

## Pre-refactor versions (microvm.manager)

### v70-v71 — FC snapshot, code cleanup
### v60-v69 — Firecracker, dual-engine, context menu, console, resize
### v50-v59 — CH snapshot/restore, OCI pull, rootFS page
### v40-v49 — Initial CH, WebGUI, TAP networking, autostart
