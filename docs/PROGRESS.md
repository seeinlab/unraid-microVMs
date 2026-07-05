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
- **KVM dependency** — waits for libvirtd before starting (shares /dev/kvm)
- **VMM, not Engine** — terminology throughout

### What's Working ✅

#### Boot Sequence
```
[pre] Check /dev/kvm + wait for libvirtd
[1/7] Load dm_thin_pool kernel module
[2/7] Setup microvms-thinpool (loop-backed sparse files)
[3/7] Start microvms-containerd (devmapper snapshotter) ← always
[4/7] Start crane registry (127.0.0.1:5050)            ← if FLINTLOCKD=enable
[5/7] Start flintlockd (0.0.0.0:9090 gRPC)            ← if FLINTLOCKD=enable
[6/7] Create TAP interfaces
[7/7] Autostart VMs
```

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
- Enabled/disabled independently from direct mode (Settings toggle)
- **NOT used by UI** — gRPC API for external tools only

#### Settings (microVMs Controlplane)
- General: enable/disable, VM storage, bridge, defaults, thin pool size
- Liquidmetal: enable/disable, crane storage, gRPC port, service status
- Info sections: VM Manager (libvirt), Cloud Hypervisor, Firecracker

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

1. **Option C**: Replace dmsetup calls with `ctr snapshots` commands (containerd manages all device IDs)
2. **Upgrade containerd**: v1.7.27 → v1.7.33 (security patches, LTS)
3. **PLG installer**: Test full install from clean state
4. **Per-VMM enable/disable**: Settings toggle for CH and FC independently
5. **Kernel download**: Auto-download on first install if missing

### 🐛 Known Issues
- Containerd start slow on FUSE filesystem (BoltDB lock, timeout increased to 30s)
- ACPI shutdown fails on VMs without acpid (busybox/nginx images) — Force Stop works
- Thin pool teardown fails if devices still in use (need kill VMs first)
- Stale BoltDB locks after unclean shutdown (need manual cleanup)

---

## Pre-refactor versions (microvm.manager)

### v70-v71 — FC snapshot, code cleanup
### v60-v69 — Firecracker, dual-engine, context menu, console, resize
### v50-v59 — CH snapshot/restore, OCI pull, rootFS page
### v40-v49 — Initial CH, WebGUI, TAP networking, autostart
