# MicroVMs Plugin — Features & Data Models

> Auto-generated reference for the microVMs Unraid plugin.
> Source: `MicroVMAdmin.php`, `common.php`, `rc.microvms`, `MicroVMsSettingsGeneral.page`

---

## Features Overview

| Feature | Status | Cloud Hypervisor | Firecracker |
|---------|--------|:---:|:---:|
| Create VM from OCI image | ✅ Done | ✅ | ✅ |
| Create VM from existing rootfs | ✅ Done | ✅ | ✅ |
| Create VM from JSON config | ✅ Done | ✅ | ✅ |
| Start / Stop VM | ✅ Done | ✅ | ✅ |
| Force Stop (kill -9) | ✅ Done | ✅ | ✅ |
| ACPI graceful shutdown | ✅ Done | ✅ (power-button) | ❌ (direct kill) |
| Live resize (CPU/RAM) | ✅ Done | ✅ (ch-remote resize) | ❌ Not supported |
| Snapshot (pause → save state) | ✅ Done | ✅ (ch-remote snapshot) | ✅ (FC API /snapshot/create) |
| Restore snapshot | ✅ Done | ✅ (ch-remote restore) | ✅ (FC API /snapshot/load) |
| Serial console (ttyd + FIFO) | ✅ Done | ✅ (PTY → FIFO bridge) | ✅ (stdin FIFO) |
| Console input | ✅ Done | ✅ (write to PTY) | ✅ (write to FIFO) |
| Log viewer (ttyd tail -f) | ✅ Done | ✅ | ✅ |
| Autostart on array start | ✅ Done | ✅ | ✅ |
| Thin pool (devmapper) storage | ✅ Done | ✅ | ✅ |
| Raw file storage | ✅ Done | ✅ | ✅ |
| TAP networking (br0 bridge) | ✅ Done | ✅ | ✅ |
| Kernel ip= parameter networking | ✅ Done | ✅ | ✅ |
| Namespace isolation | ✅ Done | ✅ | ✅ |
| Containerd container registry | ✅ Done | ✅ | ✅ |
| Liquidmetal / flintlockd gRPC | ✅ Done | ✅ | ✅ |
| Local OCI registry (crane) | ✅ Done | ✅ | ✅ |
| Kernel download (per VMM) | ✅ Done | ✅ | ✅ |
| WebGUI Settings (multi-tab) | ✅ Done | ✅ | ✅ |
| WebGUI VM list + context menu | ✅ Done | ✅ | ✅ |
| WebGUI Create VM form | ✅ Done | ✅ | ✅ |
| Prune unused OCI images | ✅ Done | ✅ | ✅ |
| Delete VM (with thin cleanup) | ✅ Done | ✅ | ✅ |
| Live migration | 🚧 Planned | ✅ (CH supports) | ❌ |

---

## Data Models

### VM Configuration JSON

Each VM is stored as `{vmm}.json` in its directory. The filename determines the VMM:
- `cloud-hypervisor.json` → Cloud Hypervisor
- `firecracker.json` → Firecracker
- `config.json` → Legacy (defaults to Cloud Hypervisor)



#### Full Schema

```jsonc
{
  // Identity
  "name": "string",              // VM identifier (lowercase, alphanumeric + hyphens)
  "namespace": "string",         // Containerd namespace (default: "default", "flintlock" reserved)

  // Compute
  "vcpus": "integer",            // Boot vCPU count (default from DEFAULT_CPUS)
  "max_vcpus": "integer",        // Max vCPUs for hotplug (default: vcpus × 2)
  "memory_mb": "integer",        // Boot memory in MB (default from DEFAULT_MEMORY)
  "max_memory_mb": "integer",    // Max memory for hotplug (default: memory_mb × 2)

  // Storage
  "storage": {
    "type": "string",            // "raw" | "thin"
    "size_mb": "integer",        // Disk size in MB (raw mode only)
    "image_ref": "string",       // OCI image reference (thin mode, e.g. "nginx:alpine")
    "thin_device_id": "string"   // Containerd-managed device ID (thin mode, auto-assigned)
  },

  // Network
  "network": {
    "ip": "string",              // Static IP (e.g. "192.168.50.100")
    "gateway": "string",         // Gateway IP (default: "192.168.50.1")
    "mac": "string",             // MAC address (format: "52:54:00:xx:xx:xx", auto-generated)
    "bridge": "string",          // Host bridge interface (from BRIDGE config, default "br0")
    "tap_id": "integer"          // TAP interface ID (auto-assigned, unique across all VMs)
  },

  // Image source
  "image": {
    "source": "string",          // "oci" | "existing"
    "ref": "string"              // OCI image ref or path to existing rootfs
  },

  // Kernel
  "kernel": {
    "cmdline": "string"          // Base kernel cmdline (default: "console=ttyS0 root=/dev/vda rw init=/fly/init")
                                 // Network appended at runtime: "ip=<IP>::<GW>:255.255.255.0:::off"
  },

  // Behavior
  "autostart": "boolean",        // Auto-start on array start (default: false)
  "console": "boolean",          // Enable serial console (default: true)

  // Liquidmetal (optional, set when created via flintlockd)
  "flintlock_uid": "string",     // Flintlockd-assigned UID
  "flintlock_namespace": "string" // Flintlock namespace (usually "default")
}
```

#### Legacy Flat Format (config.json)

Older VMs may use flat fields: `boot_vcpus`, `max_vcpus`, `disk`, `cmdline`, `ip`, `mac`, `bridge`, `tap_id`, `storage_type`, `disk_size_mb`, `thin_device_id`. The `common.php` helpers (`microvm_get_network()`, `microvm_get_storage()`) normalize both formats.

---

### Plugin Configuration (controlplane.cfg)

Location: `/boot/config/plugins/microvms/microvms.controlplane.cfg`
Format: INI (bash `source`-able, `parse_ini_file()` in PHP)

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `SERVICE` | string | `"disable"` | Master enable/disable (`"enable"` / `"disable"`) |
| `VMDIR` | path | `"/mnt/user/microvms"` | Persistent VM storage directory |
| `BRIDGE` | string | `"br0"` | Host bridge for TAP interfaces |
| `DEFAULT_CPUS` | integer | `1` | Default vCPUs for new VMs |
| `DEFAULT_MEMORY` | integer | `256` | Default memory (MB) for new VMs |
| `DEFAULT_VMM` | string | `"cloud-hypervisor"` | Default VMM engine |
| `AUTOSTART` | string | `"no"` | Global autostart gate (`"yes"` / `"no"`) |
| `CH_ENABLED` | string | `"yes"` | Cloud Hypervisor engine enabled |
| `FC_ENABLED` | string | `"no"` | Firecracker engine enabled |
| `DEVMAPPER` | string | `"enable"` | Thin pool devmapper (`"enable"` / `"disable"`) |
| `THINPOOL_DATA_SIZE_GB` | integer | `50` | Thin pool data file size in GB |
| `THINPOOL_META_SIZE` | string | `"500M"` | Thin pool metadata file size |
| `FLINTLOCKD` | string | `"disable"` | Liquidmetal gRPC services |
| `FLINTLOCKD_GRPC_PORT` | integer | `9090` | gRPC listen port |
| `FLINTLOCKD_EXTRA_FLAGS` | string | `"--insecure --default-provider cloudhypervisor"` | Extra flintlockd flags |
| `CRANE_REGISTRY_DIR` | path | `"${SYSTEM_DIR}/crane/registry"` | Local OCI registry storage |
| `CH_KERNEL_URL` | string | (empty) | Custom CH kernel download URL |
| `FC_KERNEL_URL` | string | (empty) | Custom FC kernel download URL |

---

### Containerd State

**Socket:** `/var/run/microvms/containerd.sock`
**Config:** `/var/run/microvms/containerd-config.toml` (generated at start)
**Persistent root:** `/mnt/user/system/microvms/containerd`
**Ephemeral state:** `/var/run/microvms/containerd-state`

#### Namespaces

| Namespace | Purpose |
|-----------|---------|
| `default` | All VM containers registered here (unified) |
| `ch` | Created when CH_ENABLED=yes (per-VMM namespace) |
| `fc` | Created when FC_ENABLED=yes (per-VMM namespace) |
| `flintlock` | **Reserved** — used by flintlockd orchestration only |

#### Container Labels

When a VM is started, it's registered as a containerd container with these labels:

| Label | Example | Description |
|-------|---------|-------------|
| `microvm.vmm` | `cloud-hypervisor` | VMM engine type |
| `microvm.state` | `running` / `stopped` | Current VM state |
| `microvm.pid` | `12345` | Process ID (0 when stopped) |
| `microvm.namespace` | `default` | Logical namespace |
| `microvm.ip` | `192.168.50.100` | Assigned IP address |
| `microvm.tap` | `tap0` | TAP interface name |
| `microvm.started_at` | `2026-07-08T06:18:19Z` | ISO 8601 start timestamp |

---

### Runtime State Directory

Location: `/var/run/microvms/{namespace}/{vm-name}/`

| File | Content |
|------|---------|
| `{vmm}.pid` | Process ID of the VMM |
| `{vmm}.sock` | Symlink to `/tmp/microvms-{name}.sock` |
| `metadata.json` | Runtime metadata (see below) |

#### metadata.json

```json
{
  "name": "my-vm",
  "namespace": "default",
  "vmm": "cloud-hypervisor",
  "pid": 12345,
  "state": "running",
  "started_at": "2026-07-08T06:18:19Z",
  "socket": "/tmp/microvms-my-vm.sock",
  "config": "/mnt/user/microvms/default/my-vm/cloud-hypervisor.json"
}
```



---

## API Reference

### Backend Commands (MicroVMAdmin.php)

All commands are POST requests to `/plugins/microvms/backend/MicroVMAdmin.php`.
Parameters sent via `$_REQUEST` (supports both GET and POST).

#### Lifecycle

| Command (`cmd=`) | Parameters | Response | Description |
|------------------|-----------|----------|-------------|
| `list` | — | `[{name, vmm, config, state, socket}]` | List all VMs (scans VMDIR + containerd + state dir) |
| `start` | `name` | `{success, output}` | Start VM via `rc.microvms start_vm` |
| `stop` | `name` | `{success, output, acpi_timeout?}` | Graceful stop (ACPI for CH, kill for FC) |
| `force_stop` | `name` | `{success, message}` | `kill -9` the VMM process |
| `status` | `name` | `{success, name, state}` | Ping CH socket to check running state |

#### Info & Logs

| Command | Parameters | Response | Description |
|---------|-----------|----------|-------------|
| `info` | `name` | CH API info JSON or config JSON | Live info from `ch-remote info` (falls back to config) |
| `vm_config` | `name` | Raw config JSON | Read config file from disk (for Edit form) |
| `logs` | `name` | `{success, log}` | Last 100 lines of VM log file |
| `logs_terminal` | `name` | `{success, url}` | Start ttyd log viewer, returns `/logterminal/` URL |
| `vm_log` | `name`, `engine` | `{success, log}` | Per-VMM log (CH: `.serial.log`, FC: `.log`) |
| `console_output` | `name` | `{success, log}` | Last 100 lines of serial log (ANSI stripped) |
| `view_log` | `service` | `{success, log}` | View service or VM log (200 lines, ANSI stripped) |

#### Console

| Command | Parameters | Response | Description |
|---------|-----------|----------|-------------|
| `console` | `name` | `{success, url, name}` | Start ttyd serial console, returns WebSocket URL |
| `console_stop` | `name` | `{success, message}` | Kill ttyd relay process |
| `console_input` | `name`, `input` | `{success}` | Send command to VM (CH: PTY, FC: FIFO) |

#### CRUD

| Command | Parameters | Response | Description |
|---------|-----------|----------|-------------|
| `create` | `name`, `cpus`, `memory`, `max_memory`, `ip`, `gateway`, `source`, `oci_image`, `disk_size`, `rootfs_path`, `engine`, `storage_type`, `autostart`, `console`, `namespace`, `max_cpus` | `{success, message, started?}` | Full VM creation (pull image, create rootfs, write config) |
| `create_json` | `config` (JSON string) | `{success, message}` | Create VM from raw JSON config |
| `delete` | `name` | `{success, message}` | Stop + remove VM (blocks if snapshots exist) |
| `delete_rootfs` | `name` | `{success, message}` | Delete rootfs file only (keep config) |
| `pull_rootfs` | `name`, `image`, `disk_size` | `{success, path}` | Pull OCI image and create raw rootfs |

#### Resize & Snapshots

| Command | Parameters | Response | Description |
|---------|-----------|----------|-------------|
| `resize` | `name`, `cpus?`, `memory?` | `{cpus?: bool, memory?: bool}` | Live resize (CH only, via `ch-remote resize`) |
| `snapshot` | `name`, `tag?` | `{success, path, output}` | Create snapshot (tag defaults to timestamp) |
| `list_snapshots` | `name` | `{success, snapshots: [{tag, date, timestamp, size, path}]}` | List all snapshots for a VM |
| `delete_snapshot` | `name`, `tag` | `{success, message}` | Delete a snapshot directory |
| `restore_snapshot` | `name`, `tag` | `{success, message}` | Restore from snapshot (stops current, boots from snap) |

#### Configuration & Services

| Command | Parameters | Response | Description |
|---------|-----------|----------|-------------|
| `autostart` | `name`, `enabled` (`"true"`/`"false"`) | `{success, autostart}` | Toggle VM autostart flag |
| `service` | `action` (`start`/`stop`/`restart`) | `{success, message}` | Control the main microvms service |
| `toggle_setting` | `key`, `value` | `{success, message}` | Toggle config key (CH_ENABLED, FC_ENABLED, FLINTLOCKD, DEVMAPPER) |
| `service_action` | `service`, `action` | `{success, output}` | Start/stop/restart individual service (flintlockd, containerd, registry) |
| `download_kernel` | `engine` | `{success, message}` | Download kernel for specific VMM |
| `download_kernels` | `cloud_hypervisor?`, `firecracker?` (0/1) | `{success, message}` | Batch kernel download |

#### Namespace Management

| Command | Parameters | Response | Description |
|---------|-----------|----------|-------------|
| `list_namespaces` | — | `{success, namespaces: []}` | List containerd namespaces |
| `create_namespace` | `namespace` | `{success, message}` | Create namespace (rejects "flintlock") |
| `delete_namespace` | `namespace` | `{success, message}` | Delete namespace (protects "default", "flintlock") |

#### Liquidmetal (gRPC Orchestration)

| Command | Parameters | Response | Description |
|---------|-----------|----------|-------------|
| `liquidmetal` | `action=status` | `{success, running, services: {flintlockd, containerd, registry}, grpc_port}` | Service status |
| `liquidmetal` | `action=start` | `{success, message}` | Start containerd + registry + flintlockd |
| `liquidmetal` | `action=stop` | `{success, message}` | Stop flintlockd + registry + containerd |

#### Maintenance

| Command | Parameters | Response | Description |
|---------|-----------|----------|-------------|
| `prune_images` | — | `{success, message}` | Remove unused OCI images from containerd |



---

## Architecture

### Namespace Model

```
VMDIR/
├── default/          ← Default namespace (most VMs live here)
│   ├── web-server/
│   └── api-service/
├── staging/          ← Custom namespace (user-created)
│   └── test-vm/
└── (flintlock reserved — never used for direct-mode VMs)
```

- Namespace = subdirectory under `VMDIR`
- VM path: `$VMDIR/$namespace/$name/`
- If namespace not specified in request, backend scans all namespace dirs to find the VM
- Containerd mirrors namespaces: `ctr -n $namespace containers list`
- `flintlock` namespace is reserved for Liquidmetal orchestration (blocked from user creation)

---

### Storage Model

#### Raw Mode (`storage.type = "raw"`)

```
$VMDIR/$ns/$name/rootfs.raw    ← Sparse ext4 file (dd + mkfs.ext4)
```

- Created via `dd if=/dev/zero of=rootfs.raw bs=1M count=$diskSize`
- Formatted with `mkfs.ext4 -F`
- OCI content extracted via `tar -xf` after mount
- At VM start: path resolved via `resolve_path()` to avoid FUSE overhead
  - Scans `/mnt/cache`, `/mnt/mtier`, `/mnt/ztier`, `/mnt/rtier` for real device path
- CH disk arg: `path=$disk,readonly=false,image_type=raw`
- FC config: `{"drive_id":"rootfs","path_on_host":"$disk","is_root_device":true,"is_read_only":false}`

#### Thin Pool Mode (`storage.type = "thin"`)

```
$VMDIR/thinpool/
├── data              ← Sparse file (default 50G), loop-mounted
└── meta              ← Sparse file (default 500M), loop-mounted

Device mapper:
  /dev/mapper/microvms-thinpool    ← Pool device
  /dev/mapper/microvms-thinpool-snap-*  ← Per-VM snapshots
```

- Powered by `dm_thin_pool` kernel module + containerd devmapper snapshotter
- Setup: `setup_thinpool()` in rc.microvms creates loop devices → `dmsetup create`
- Block size: 128 sectors (64KB), low water mark: 32768 sectors (16MB)
- VM creation (thin): `ctr images mount --snapshotter devmapper --rw --platform linux/amd64 $image $mountpoint`
- VM start: `activate_thin_rootfs()` → queries containerd snapshot mounts → returns `/dev/mapper/...` path
- Persists across reboots (data files on array). Only `reset_thinpool` destroys.
- Cannot disable devmapper if any VM uses thin storage (guarded in `toggle_setting`)

#### Init Injection (Fly.io pattern)

Both storage modes inject these files into the rootfs:

| File | Source | Purpose |
|------|--------|---------|
| `/fly/init` | `/usr/local/share/microvms/fly-init` | PID 1 init (network setup, console, exec app) |
| `/sbin/catatonit` | `/usr/local/share/microvms/catatonit` | Minimal zombie reaper |
| `/init` | Symlink → `/fly/init` | Kernel init= target |
| `/fly/run.json` | Generated per-VM | Entrypoint, cmd, network, console config |

---

### Network Model

```
Guest VM ←→ virtio-net ←→ TAP device ←→ br0 bridge ←→ LAN
```

- Each VM gets a unique TAP: `tap{tap_id}` (ID auto-assigned via `microvm_next_tap_id()`)
- TAP creation: `ip tuntap add dev tap$N mode tap && ip link set tap$N master $BRIDGE up`
- IP assigned via kernel `ip=` boot parameter: `ip=$IP::$GW:255.255.255.0:::off`
- No iproute2 needed inside guest — kernel handles interface setup
- MAC auto-generated: `52:54:00:xx:xx:xx` (KVM convention prefix)
- TAP deleted on VM stop, recreated on start (or at service boot via `create_taps()`)
- Orphan TAPs cleaned on service start (TAPs not matching any running VM process)

---

### Console Model

#### Cloud Hypervisor

```
CH process → --serial pty → /dev/pts/N (allocated by CH)
                                ↓ (cat)
                        serial.log file ← output capture
                                ↑ (tail -f fifo > pty)
                        /tmp/microvms-$name.fifo ← input path
                                ↑
                        ttyd WebSocket (/logterminal/)
```

- CH started with `--serial pty --console off`
- PTY path extracted from CH log: `grep -oP '/dev/pts/\d+'`
- Output: `cat $pty >> serial.log` (background process, PID saved)
- Input: `printf '%s\n' "$cmd" > $pty` (direct PTY write from `console_input`)
- ttyd relay: connects FIFO ↔ browser via `/logterminal/microvm-$name.console/`

#### Firecracker

```
FIFO (mkfifo) → tail -f | FC process → stdout → .log file
                     ↑                              ↓
              /tmp/microvms-$name.fifo      /var/log/.../name.log
                     ↑                              ↓
              ttyd WebSocket              console_output reads this
```

- FC started with FIFO piped to stdin: `(tail -f $fifo | firecracker --api-sock ...) >> .log`
- Input: `printf '%s\n' "$cmd" > $fifo` (FIFO write from `console_input`)
- Output: FC's stdout/stderr IS the serial output (goes to `.log` file)
- FIFO path saved to `/var/tmp/microvms-$name.fifo`
- ttyd relay uses `microvms-console-fc` helper script

---

### Snapshot Model

#### Cloud Hypervisor

```
Pause → ch-remote snapshot file://$snapdir → Resume
```

- Files saved: `$VMDIR/$ns/$name/snapshots/$tag/` (CH internal format)
- Restore flow:
  1. Kill existing CH process
  2. Start new `cloud-hypervisor --api-socket $sock` (no --kernel, API-only mode)
  3. `ch-remote restore source_url=file://$snapdir,resume=true`
  4. Poll `ch-remote ping` until responsive (up to 10s)
  5. Re-setup serial PTY capture

#### Firecracker

```
Pause (PATCH /vm {state:Paused}) → PUT /snapshot/create → Resume (PATCH /vm {state:Resumed})
```

- Files saved: `$snapdir/snapshot` (VM state) + `$snapdir/mem` (memory file)
- Restore flow:
  1. Kill existing FC process
  2. Start new `firecracker --api-sock $sock` (no config, blank state)
  3. `PUT /snapshot/load` with `snapshot_path`, `mem_backend`, `resume_vm=true`
  4. TAP device must exist on host before load (FC expects same network topology)

---

### Process Lifecycle

#### Service Boot Sequence (`rc.microvms start`)

```
[1/7] Load dm_thin_pool kernel module
[2/7] Setup thin pool (sparse files → loop devices → dmsetup)
[3/7] Start microvms-containerd (devmapper snapshotter)
[4/7] Start local OCI registry (crane serve, port 5050)  [if FLINTLOCKD=enable]
[5/7] Start flintlockd (gRPC 0.0.0.0:9090)              [if FLINTLOCKD=enable]
[6/7] Clean orphan TAPs, create TAPs for all configured VMs
[7/7] Autostart VMs (where autostart=true AND AUTOSTART=yes)
```

#### VM Start Flow

```
1. Find config file ($VMDIR/$ns/$name/{vmm}.json)
2. Determine VMM from filename
3. Derive kernel path: /mnt/user/system/microvms/{vmm}/kernels/vmlinux
4. Resolve disk path (raw: resolve_path(), thin: activate_thin_rootfs())
5. Build kernel cmdline with network ip= parameter
6. Create/verify TAP device on bridge
7. Launch VMM process (background, log to /var/log/microvms/{vmm}/$name.log)
8. Write state directory (/var/run/microvms/$ns/$name/)
9. Register in containerd (ctr containers create --label microvm.*)
10. Setup serial capture (CH: PTY→log + FIFO, FC: already via pipe)
```

#### VM Stop Flow

```
1. Kill ttyd console relay (if running)
2. Kill serial capture process (CH)
3. Clean up FIFO and PTY files
4. Update state directory metadata (state→stopped, pid→0)
5. Update containerd labels (microvm.state=stopped)
6. Detect VMM from config filename
7. Delete TAP interface
8. [FC]: kill process directly
   [CH]: ch-remote power-button → wait 10s → force kill if unresponsive
9. Remove socket file
```



---

## Directory Layout

### Persistent Storage (survives reboots, on array)

```
/boot/config/plugins/microvms/
└── microvms.controlplane.cfg         ← Plugin settings (flash drive, VFAT)

/mnt/user/microvms/                   ← VMDIR (user share)
├── thinpool/
│   ├── data                          ← Thin pool data (sparse, default 50G)
│   └── meta                          ← Thin pool metadata (sparse, default 500M)
├── default/                          ← Default namespace
│   └── {vm-name}/
│       ├── cloud-hypervisor.json     ← VM config (or firecracker.json)
│       ├── rootfs.raw                ← Raw rootfs (if storage.type=raw)
│       └── snapshots/
│           └── {tag}/                ← Snapshot files (CH or FC format)
└── {namespace}/                      ← Custom namespaces
    └── {vm-name}/...

/mnt/user/system/microvms/            ← System data (shared across VMs)
├── cloud-hypervisor/
│   └── kernels/vmlinux               ← CH kernel binary
├── firecracker/
│   └── kernels/vmlinux               ← FC kernel binary
├── containerd/                       ← Containerd persistent root
│   ├── io.containerd.metadata.v1.bolt/meta.db
│   └── io.containerd.snapshotter.v1.devmapper/
└── crane/
    └── registry/                     ← Local OCI registry storage
```

### Runtime (tmpfs, cleared on reboot)

```
/var/run/microvms/                    ← RUNTIME_DIR
├── containerd.sock                   ← Containerd gRPC socket
├── containerd.pid                    ← Containerd PID file
├── flintlockd.pid                    ← Flintlockd PID file
├── crane-registry.pid                ← Registry PID file
├── containerd-config.toml            ← Generated containerd config
├── containerd-state/                 ← Containerd ephemeral state
├── flintlockd-state/                 ← Flintlockd ephemeral state
├── thinpool-data-loop                ← Loop device path (for teardown)
├── thinpool-meta-loop                ← Loop device path (for teardown)
└── {namespace}/{vm-name}/            ← Per-VM state
    ├── {vmm}.pid
    ├── {vmm}.sock → /tmp/microvms-{name}.sock
    └── metadata.json

/tmp/
├── microvms-{name}.sock              ← VMM API socket (CH or FC)
├── microvms-{name}.fifo              ← Console input FIFO
├── microvms-{name}-fc.json           ← Generated FC boot config (ephemeral)
└── microvm-mount-{name}/             ← Temp mount point during rootfs creation

/var/tmp/
├── ttyd-microvms-{name}.pid          ← Console ttyd PID
├── ttyd-microvms-{name}-log.pid      ← Log viewer ttyd PID
├── microvms-{name}.fifo              ← FIFO path reference file
├── microvms-{name}-serial.pid        ← CH serial capture PID
└── microvm-{name}.console.sock       ← ttyd Unix socket (proxied by nginx)

/var/log/microvms/
├── backend.log                       ← PHP backend AJAX log
├── containerd.log                    ← Containerd output
├── flintlockd.log                    ← Flintlockd output
├── registry.log                      ← Crane registry output
├── cloud-hypervisor/
│   ├── {name}.log                    ← CH process stdout/stderr
│   └── {name}.serial.log            ← CH serial PTY capture (kernel + app output)
└── firecracker/
    └── {name}.log                    ← FC process output (IS the serial output)
```

### Plugin Files (installed by PLG)

```
/usr/local/bin/
├── cloud-hypervisor                  ← CH binary (static, ~5.5MB)
├── ch-remote                         ← CH management CLI
├── firecracker                       ← FC binary
├── crane                             ← OCI image tool
├── grpcurl                           ← gRPC CLI (for flintlockd)
├── microvms-containerd               ← Containerd binary (renamed)
├── flintlockd                        ← Liquidmetal gRPC daemon
├── ttyd                              ← Terminal-over-WebSocket
└── microvms-console-fc               ← FC console relay helper

/usr/local/share/microvms/
├── fly-init                          ← Generic init binary (Fly.io pattern)
└── catatonit                         ← Minimal zombie reaper

/usr/local/emhttp/plugins/microvms/   ← WebGUI plugin
├── backend/MicroVMAdmin.php          ← All AJAX commands
├── include/common.php                ← Shared PHP functions
├── event/array_started               ← Unraid lifecycle hook
├── event/stopping_svcs               ← Unraid shutdown hook
├── MicroVMs.page                     ← Main menu entry
├── MicroVMsMachines.page             ← VM list + context menu
├── MicroVMsRootFS.page               ← RootFS management
├── MicroVMsStats.page                ← Statistics view
├── AddMicroVMs.page                  ← Create VM form
├── MicroVMsSettings.page             ← Settings tab container (Type=xmenu)
├── MicroVMsSettingsGeneral.page      ← General settings (status tree)
├── MicroVMsSettingsCH.page           ← Cloud Hypervisor settings
├── MicroVMsSettingsFC.page           ← Firecracker settings
└── MicroVMsSettingsLM.page           ← Liquidmetal settings

/etc/rc.d/rc.microvms                 ← Service manager script (800+ lines bash)
```
