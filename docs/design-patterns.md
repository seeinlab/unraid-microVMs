# microVMs Plugin — Design Patterns

## Directory Structure

```
/usr/local/bin/                          ← Binaries (on flash, cached)
  cloud-hypervisor                         VMM: Cloud Hypervisor v52
  ch-remote                                VMM: CH management CLI
  firecracker                              VMM: Firecracker v1.16
  microvms-containerd                      Container runtime (v1.7.27)
  flintlockd                               gRPC orchestrator (v0.9.0)
  crane                                    OCI image tool
  grpcurl                                  gRPC CLI client
  ttyd                                     Web terminal

/mnt/user/system/microvms/               ← System data (persistent, on array)
  cloud-hypervisor/kernels/vmlinux         PVH kernel for CH (Linux 6.2.0)
  firecracker/kernels/vmlinux              Kernel for FC (Linux 5.10.225)
  containerd/                              Containerd root (snapshots, content, metadata)
  crane/registry/                          Local OCI registry storage

/mnt/user/microvms/                      ← VM data (persistent, on array)
  {vm-name}/
    cloud-hypervisor.json                  VM config (CH) — infra-as-code
    firecracker.json                       VM config (FC) — infra-as-code
    rootfs.raw                             Raw rootfs (if storage.type=raw)
    snapshots/{tag}/                       CH snapshots
  thinpool/
    data                                   Thin pool data (50GB sparse)
    meta                                   Thin pool metadata (500MB sparse)

/var/run/microvms/                       ← Runtime (tmpfs, lost on reboot)
  containerd.sock                          Containerd gRPC socket
  containerd.pid                           Containerd PID
  containerd-config.toml                   Containerd config (generated)
  containerd-state/                        Containerd state
  flintlockd.pid                           Flintlockd PID
  flintlockd-state/                        Flintlockd state + VM sockets
  crane-registry.pid                       Crane registry PID

/var/log/microvms/                       ← Logs (like libvirt pattern)
  containerd.log                           microvms-containerd daemon
  flintlockd.log                           flintlockd gRPC daemon
  registry.log                             crane OCI registry
  backend.log                              PHP backend (WebGUI actions)
  cloud-hypervisor/                        Per-VM logs (CH)
    {vm-name}.log
  firecracker/                             Per-VM logs (FC)
    {vm-name}.log

/boot/config/plugins/microvms/           ← Flash config (persists reboots)
  microvms.controlplane.cfg                Plugin settings

/usr/local/emhttp/plugins/microvms/      ← WebGUI plugin files
  MicroVMs.page                            Tab container
  MicroVMsMachines.page                    VM list + actions
  MicroVMsSettings.page                    Settings container (xmenu)
  MicroVMsSettingsGeneral.page             Settings: General tab
  MicroVMsSettingsCH.page                  Settings: Cloud Hypervisor tab
  MicroVMsSettingsFC.page                  Settings: Firecracker tab
  MicroVMsSettingsLM.page                  Settings: Liquidmetal tab
  MicroVMsRootFS.page                      RootFS management
  MicroVMsStats.page                       Statistics
  AddMicroVMs.page                         Create VM form
  backend/MicroVMAdmin.php                 AJAX command handler
  include/common.php                       Shared functions
  console.html                             ttyd console wrapper
  images/{cloud-hypervisor,firecracker}.png
  microvms.{png,svg}                       Plugin icon

/tmp/microvms-{name}.sock                 ← VM API sockets (CH/FC direct mode)
```

---

## Naming Conventions

| Concept | Convention | Example |
|---------|-----------|---------|
| Plugin name | `microvms` | /usr/local/emhttp/plugins/microvms/ |
| VM config file | `{vmm}.json` | cloud-hypervisor.json, firecracker.json |
| VMM (not "Engine") | lowercase with hyphen | cloud-hypervisor, firecracker |
| Thin pool | `microvms-thinpool` | /dev/mapper/microvms-thinpool |
| VM socket | `microvms-{name}.sock` | /tmp/microvms-my-server.sock |
| TAP interface | `tap{id}` | tap3 |
| Containerd namespaces | per-VMM/manager | flintlock, cloud-hypervisor, firecracker |
| Log files | service.log or {vmm}/{name}.log | containerd.log, cloud-hypervisor/nginx.log |
| Config file | microvms.controlplane.cfg | on flash |

---

## Storage Patterns

### Thin Pool (devmapper)
```
One shared pool: microvms-thinpool
  ├── Containerd managed (IDs 0+, BoltDB)     ← for flintlockd
  └── Direct mode managed (IDs 1000000+)      ← for rc.microvms
      Future: direct mode also via containerd API (Option C)
```

### Storage Types (per-VM choice)
| Type | Backend | Device | Use case |
|------|---------|--------|----------|
| `thin` | devmapper thin pool | /dev/mapper/microvms-{name} | Space-efficient, fast clone |
| `raw` | ext4 file on disk | /mnt/user/microvms/{name}/rootfs.raw | Simple, no devmapper dependency |

---

## Process Architecture

### Boot Sequence (rc.microvms start)
```
[pre] Check /dev/kvm available
[1-2] If DEVMAPPER=enable:
        [1/7] Load dm_thin_pool kernel module
        [2/7] Setup thin pool (microvms-thinpool)
      Else: skipped
[3/7] Start microvms-containerd (devmapper snapshotter) ← always when enabled
[4/7] Start crane registry (127.0.0.1:5050)            ← only if FLINTLOCKD=enable
[5/7] Start flintlockd (0.0.0.0:9090 gRPC)            ← only if FLINTLOCKD=enable
[6/7] Re-create TAP interfaces (from VM configs)
[7/7] Autostart microVMs
```

### Two Operating Modes

#### Direct Mode (WebGUI)
```
WebGUI → MicroVMAdmin.php → rc.microvms → cloud-hypervisor/firecracker
  - User creates/starts/stops VMs from browser
  - rc.microvms manages CH/FC processes directly
  - Thin pool OR raw file for rootfs
  - Logs: /var/log/microvms/{vmm}/{name}.log
```

#### Liquidmetal Mode (Remote Automation)
```
grpcurl/API → flintlockd:9090 → containerd → thin pool → CH/FC
  - Programmatic VM lifecycle via gRPC
  - OCI images pulled from crane registry
  - containerd manages all snapshots via devmapper
  - Enabled/disabled independently from direct mode
```

---

## Config Format (Infra-as-Code)

### File: `cloud-hypervisor.json` or `firecracker.json`
VMM determined by filename — no `vmm` field inside.

```json
{
  "name": "my-web-server",
  "vcpus": 2,
  "memory_mb": 512,
  "storage": {
    "type": "thin",
    "size_mb": 500,
    "thin_device_id": 1000001
  },
  "network": {
    "ip": "192.168.50.220",
    "gateway": "192.168.50.1",
    "mac": "52:54:00:ce:08:fc",
    "bridge": "br0",
    "tap_id": 3
  },
  "image": {
    "source": "oci",
    "ref": "docker.io/nginx:stable-trixie"
  },
  "kernel": {
    "cmdline": "console=ttyS0 root=/dev/vda rw init=/init"
  },
  "autostart": true
}
```

### Principles
- No absolute paths stored (derived at runtime from VMM + storage type)
- Portable: copy to another Unraid with same plugin → works
- Schema: `docs/vm-config-schema.json` (OpenAPI 3.0.3)
- Backward compatible: code also reads legacy `config.json`

---

## Containerd Namespaces

| Namespace | Used by | Purpose |
|-----------|---------|---------|
| `flintlock` | flintlockd | gRPC-managed VMs (OCI pull + devmapper) |
| `cloud-hypervisor` | Direct mode | CH VM snapshots |
| `firecracker` | Direct mode | FC VM snapshots |

All share the same `microvms-thinpool`. Device IDs managed by containerd BoltDB (no conflicts).

---

## Network Pattern

```
Host: br0 (bridge)
  ├── tap0 → VM: my-server (192.168.50.220)
  ├── tap1 → VM: nginx-prod (192.168.50.221)
  └── tap2 → VM: test-fc (192.168.50.222)
```

- TAP IDs auto-assigned (lowest available)
- MAC auto-generated (52:54:00:xx:xx:xx)
- IP configured via kernel cmdline (`ip=...::gateway:netmask:::off`)
- Bridge shared with Unraid VM Manager (libvirt)

---

## Compatibility Matrix

| Component | Version | Notes |
|-----------|---------|-------|
| microvms-containerd | v1.7.27 | Max compatible: v1.7.33 (LTS until Sept 2026) |
| flintlockd | v0.9.0 | Supports CH v41+ and FC v1.11+ |
| cloud-hypervisor | v52.0 | API unchanged from v41 |
| firecracker | v1.16.0 | Supported by flintlockd |
| crane | v0.21.7 | OCI tool + built-in registry |
| grpcurl | v1.9.1 | gRPC CLI client |
| Unraid | 6.12+ | Kernel 6.x with KVM, br0, dm_thin_pool |

---

## Settings (microVMs Controlplane)

Sub-page tabs (like UnassignedDevices pattern):

### Tab: General
- Status box with tree display (service health)
- Containerd: [Stop/Start/Restart] [View Log]
- Enable microVMs, VM Storage, Network Bridge, Default VMM
- Default vCPUs, Default Memory, Autostart
- Devmapper: Enabled/Disabled, Thin Pool Size (GB)

### Tab: Cloud Hypervisor
- Enable Cloud Hypervisor: Yes/No
- Kernel URL (custom or default)

### Tab: Firecracker
- Enable Firecracker: Yes/No
- Kernel URL (custom or default)

### Tab: Liquidmetal
- Flintlockd: [Stop/Start/Restart] [View Log]
- Registry: [Stop/Start/Restart] [View Log]
- Enable Liquidmetal, Crane Registry Storage
- Flintlockd gRPC Port, Extra Flags

### Status Box Tree
```
+--- microvms
++-- kvm                : /dev/kvm available
++-- vmm
+++--- cloud-hypervisor : available — cloud-hypervisor v52.0 (Linux 6.2.0)
+++--- firecracker      : available — Firecracker v1.16.0 (Linux 5.10.225)
++-- runtime
+++--- containerd       : running — containerd github.com/containerd/containerd v1.7.27
++-- option
+++--- devmapper        : active — thin provisioned pool
++-- liquidmetal        : ready/failed
+++--- flintlockd       : running — flintlock v0.9.1 gRPC :9090
+++--- registry         : running — crane 127.0.0.1:5050
```

### Process Detection
- `pidof microvms-containerd` (not pgrep — avoids Docker containerd false positive)
- `pidof crane` (registry)
- `pidof flintlockd`

### AJAX Backend
- Uses `$_REQUEST` (not `$_POST` — sub-page tab context strips POST body)
- `view_log` accepts service name, maps to path server-side
- `service_action` for start/stop/restart
- `toggle_setting` for enable/disable with devmapper guard
