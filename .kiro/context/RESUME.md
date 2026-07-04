# Session Resume Context — microVM Liquidmetal Unraid Plugin

## Date: 2026-07-01 to 2026-07-04
## Project: C:\Users\seein\Workspaces\github\unraid-microVMs

---

## NEXT SESSION: Refactor to microvm.liquidmetal (Orchestrated Mode)

### What to do:
1. ~~Write cleanup script~~ ✅ (`scripts/cleanup-old-plugin.sh`)
2. Create new plugin structure with name `microvm.liquidmetal`
3. Update all file references (plugin name, paths, settings title)
4. Refactor rc.microvm for orchestrated mode (flintlock-containerd + flintlockd + thin pool)
5. Update paths (see below)
6. Run cleanup on Unraid, deploy new version, test

### Key Decisions (LOCKED):
- **Plugin name**: `microvm.liquidmetal` (was `microvm.manager`)
- **Settings title**: "microVM Liquidmetal"
- **Containerd binary name**: `flintlock-containerd` (standard containerd, just renamed)
- **Mode**: Orchestrated ONLY (flintlockd + containerd, no Direct mode)
- **Clean install**: Destroy all old VMs, fresh start

### Final Path Layout:
```
Binaries (ephemeral, /usr/local/bin/):
  cloud-hypervisor, ch-remote, firecracker, flintlockd, flintlock-containerd, crane, ttyd

Kernels (persistent):
  /mnt/user/system/microvm/kernels/cloud-hypervisor/vmlinux  (60MB)
  /mnt/user/system/microvm/kernels/firecracker/vmlinux       (37MB)

Containerd data (persistent, OCI layers):
  /mnt/user/system/microvm/containerd/

VM data (persistent):
  /mnt/user/microvms/{vm-name}/config.json
  /mnt/user/microvms/{vm-name}/vm.log
  /mnt/user/microvms/{vm-name}/snapshots/

Thin pool (persistent, sparse files):
  /mnt/user/microvms/thinpool/data    (50GB sparse)
  /mnt/user/microvms/thinpool/meta    (500MB sparse)
  /dev/mapper/microvm-thinpool        (dm device, recreated each boot)

Runtime (ephemeral, recreated each boot):
  /var/run/microvm/containerd.sock
  /var/run/microvm/flintlockd.pid
  /var/run/microvm/containerd.pid

Flash cache (persistent, survives reboot):
  /boot/config/plugins/microvm.liquidmetal/
  ├── cloud-hypervisor, ch-remote, firecracker
  ├── flintlockd, flintlock-containerd
  ├── crane, ttyd
  └── microvm.liquidmetal.cfg

Config file:
  /boot/config/plugins/microvm.liquidmetal/microvm.liquidmetal.cfg
```

### Boot Sequence (Orchestrated):
```
1. PLG copies binaries from flash → /usr/local/bin/
2. rc.microvm start:
   a. modprobe dm_thin_pool
   b. Setup thin pool (losetup + dmsetup)
   c. Start flintlock-containerd (separate from Docker's)
   d. Start flintlockd (connects to containerd, manages CH/FC)
   e. Create TAP interfaces
   f. Start VMs (if autostart=yes)
```

---

## Current State (before refactor)

### Plugin Deployed on Unraid (192.168.50.6)
- **Version**: v71 (microvm.manager-2026.07.04.71.tgz)
- **Git commits**: 70+ on main branch (latest: b3f767a)
- **SSH**: `ssh -i ~/.ssh/mastervault root@192.168.50.6`
- **Shell**: `& "C:\Program Files\Git\bin\bash.exe" -c "..."` (Windows + Git Bash)

### Running VMs (will be destroyed during cleanup)
- **nginx-ch** — Cloud Hypervisor v52.0, IP 192.168.50.220
- **nginx-fc** — Firecracker v1.16.0, IP 192.168.50.221

### Installed Binaries on Unraid
- `/usr/local/bin/cloud-hypervisor` (v52.0)
- `/usr/local/bin/ch-remote` (v52.0)
- `/usr/local/bin/firecracker` (v1.16.0)
- `/usr/local/bin/crane` (v0.21.7)
- `/usr/local/bin/ttyd` (v1.7.7)
- `/usr/local/bin/microvm-console`
- All cached on flash: `/boot/config/plugins/microvm.manager/`

### Unraid Environment
- Unraid 6.12.90, kernel 6.12.90-Unraid
- Docker 29.5.1 (containerd at `/var/run/docker/containerd/`, overlay2 storage)
- dm_thin_pool module: AVAILABLE (`/lib/modules/6.12.90-Unraid/kernel/drivers/md/dm-thin-pool.ko.xz`)
- Docker uses overlay2 (NOT devicemapper) → no conflict
- `/dev/mapper/`: only `control` (clean)
- br0 bridge (Unraid default)
- `/usr/bin/containerd` exists (Docker's, DON'T touch)

### Binaries to Download for New Plugin
- **flintlockd**: `https://github.com/liquidmetal-dev/flintlock/releases/download/v0.9.0/flintlockd`
- **containerd** (rename to flintlock-containerd): `https://github.com/containerd/containerd/releases` (static tarball, v1.7.24+)
- **ctr** (optional CLI): same tarball

---

## Architecture (Orchestrated Mode)

```
┌─────────────────────────────────────────────────────────────┐
│                    Unraid WebGUI                             │
│  MicroVMs tab → MicroVMAdmin.php (AJAX backend)            │
└─────────────────────┬───────────────────────────────────────┘
                      │ gRPC (localhost:9090)
                      ▼
┌─────────────────────────────────────────────────────────────┐
│                    flintlockd                                │
│  - Receives VM create/delete/list requests                  │
│  - Manages network (TAP on br0)                             │
│  - Picks engine: Cloud Hypervisor OR Firecracker            │
│  - Injects cloud-init metadata                              │
└────────────┬──────────────────────┬─────────────────────────┘
             │                      │
             ▼                      ▼
┌────────────────────┐   ┌────────────────────────────────────┐
│  Cloud Hypervisor  │   │  Firecracker                       │
│  (--serial pty)    │   │  (--config-file)                   │
└────────────────────┘   └────────────────────────────────────┘
             │                      │
             ▼                      ▼
┌─────────────────────────────────────────────────────────────┐
│              flintlock-containerd                            │
│  - OCI image pull/store                                     │
│  - devmapper snapshotter (thin pool)                        │
│  - Provides rootfs as /dev/mapper/thin-vol                  │
└─────────────────────────────────────────────────────────────┘
             │
             ▼
┌─────────────────────────────────────────────────────────────┐
│           Device-Mapper Thin Pool                           │
│  /dev/mapper/microvm-thinpool                               │
│  Backed by: /mnt/user/microvms/thinpool/{data,meta}        │
└─────────────────────────────────────────────────────────────┘
```

---

## Documentation Available
- `docs/flintlock-implementation-plan.md` — Full implementation details
- `docs/flintlock-devmapper-research.md` — Research + kernel module confirmed
- `docs/cloud-hypervisor-api.md` — Full CH API reference
- `docs/firecracker-api.md` — Full FC API reference
- `docs/feature-api-mapping.md` — UI → Backend → Engine mapping
- `docs/code-style-guide.md` — Coding conventions
- `docs/test-results-v69.md` — All features verified working

---

## Reference Repos
- `D:/github/unraid-webgui` — Unraid 7.2.7 WebGUI source
- `D:/github/unraid-tailscale` — Official Tailscale plugin (modern pattern)
- `D:/github/ZFS-Master-Unraid` — ZFS Master plugin (AJAX, SweetAlert2)

---

## What Works in Current Version (to preserve in refactor)
- Context menu (Unraid native context.js)
- Snapshot management (CH + FC, list/restore/delete)
- Serial console (CH: ttyd + unix socket + /logterminal/ proxy)
- Log viewer (ttyd + tail -f)
- Autostart (switchButton)
- Resize (CH only, swal dialogs)
- RootFS management page
- Add VM form (dl/dt/dd layout)
- Usage Statistics
- Settings page with health checks
