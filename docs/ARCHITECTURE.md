# microVMs Plugin — Verified Architecture

## Date: 2026-07-08

## Overview

```
┌─────────────────────────────────────────────────────────────────┐
│ Unraid WebGUI (PHP/emhttpd)                                      │
│   MicroVMsMachines.page → MicroVMAdmin.php → rc.microvms         │
└──────────────────────────┬──────────────────────────────────────┘
                           │
              ┌────────────┼────────────────┐
              │            │                │
              ▼            ▼                ▼
┌──────────────┐  ┌──────────────┐  ┌─────────────────┐
│ cloud-hyper  │  │ firecracker  │  │ containerd       │
│ visor (CH)   │  │ (FC)         │  │ (images+thin)    │
│              │  │              │  │                   │
│ API socket   │  │ API socket   │  │ devmapper snaps   │
│ Serial PTY   │  │ Serial FIFO  │  │ namespace:default │
└──────────────┘  └──────────────┘  └─────────────────┘
```

## Source of Truth (per concern)

| Concern | Source | Location |
|---------|--------|----------|
| VM definition (what SHOULD exist) | JSON config file | `$VMDIR/{namespace}/{name}/{vmm}.json` |
| VM runtime state (what IS running) | Containerd labels + state dir + process scan | `ctr containers info` + `/var/run/microvms/{ns}/{name}/` |
| OCI images | containerd | namespace `default` (shared by all VMs) |
| Rootfs snapshots (thin pool) | containerd devmapper | namespace `default` |
| Network (TAP) | kernel (created at start) | scanned from config on start |
| Logs | log files | `/var/log/microvms/{vmm}/{name}.log` + `{name}.serial.log` (CH) |

## Namespace Model

Fixed 4 namespaces — auto-managed, no user creation.

| Namespace | Auto-created when | Default for | On disk |
|-----------|------------------|-------------|---------|
| `default` | Always (containerd start) | Fallback | `$VMDIR/default/` |
| `ch` | CH_ENABLED=yes | Cloud Hypervisor VMs | `$VMDIR/ch/` |
| `fc` | FC_ENABLED=yes | Firecracker VMs | `$VMDIR/fc/` |
| `flintlock` | Liquidmetal enabled | flintlockd orchestration | Hidden from UI |

## State Directory + Containerd Registration

On VM start, two state systems are updated:

**1. State directory** (`/var/run/microvms/{ns}/{name}/`):
```json
// metadata.json
{
  "name": "my-vm",
  "namespace": "ch",
  "vmm": "cloud-hypervisor",
  "pid": 12345,
  "state": "running",
  "started_at": "2026-07-08T06:30:00Z",
  "socket": "/tmp/microvms-my-vm.sock",
  "config": "/mnt/user/microvms/ch/my-vm/cloud-hypervisor.json"
}
```
Plus: `{vmm}.pid` file, `{vmm}.sock` symlink.

**2. Containerd container registration:**
```bash
ctr -a $SOCK -n $ns containers create \
  --label microvm.vmm=cloud-hypervisor \
  --label microvm.state=running \
  --label microvm.pid=12345 \
  --label microvm.namespace=ch \
  --label microvm.ip=192.168.50.209 \
  --label microvm.tap=tap0 \
  --label microvm.started_at=2026-07-08T06:30:00Z \
  docker.io/library/nginx:alpine my-vm
```

On stop: labels updated (`microvm.state=stopped`, `microvm.pid=0`).
On delete: `ctr containers rm`.

## Containerd Role

Containerd manages:
- ✅ OCI image pull + storage (all in `default` namespace)
- ✅ Devmapper thin pool snapshots
- ✅ VM registration (containers with `microvm.*` labels)
- ✅ Namespace isolation (ch, fc, default, flintlock)
- ❌ NOT VM process management (no shim, no runtime)
- ❌ NOT networking

## VM Lifecycle

### Create
```
UI → MicroVMAdmin.php (create)
  1. Resolve namespace from request (default: ch or fc based on VMM)
  2. Pull image (containerd -n default, devmapper snapshotter)
  3. Mount snapshot / create raw rootfs
  4. Inject /fly/init + catatonit + run.json
  5. Unmount
  6. Write {vmm}.json config to $VMDIR/{ns}/{name}/
  7. If autostart → start_vm
```

### Start
```
rc.microvms start_vm {name} [namespace]
  1. Find config: scan $VMDIR/*/*/ for the VM name (or use provided ns)
  2. Read config JSON, determine VMM from filename
  3. Derive kernel path, resolve disk (raw: resolve_path, thin: activate_thin_rootfs)
  4. Build kernel cmdline with ip= parameter
  5. Create TAP if not exists, attach to bridge
  6. Launch VMM (CH: --serial pty, FC: tail -f FIFO | firecracker)
  7. Write state dir: /var/run/microvms/{ns}/{name}/metadata.json + PID file
  8. Register in containerd: ctr containers create --label microvm.*
  9. Setup serial capture (CH: cat PTY >> .serial.log)
```

### Stop
```
rc.microvms stop_vm {name}
  1. Find namespace by scanning $VMDIR/*/
  2. Kill ttyd relay, serial capture, FIFO processes
  3. CH: ACPI power-button → 10s timeout → kill -9
     FC: kill -9 directly
  4. Cleanup: socket, FIFO, TAP device
  5. Update containerd labels: microvm.state=stopped, microvm.pid=0
  6. Update state dir metadata
```

### Remove
```
MicroVMAdmin.php (delete)
  1. Stop VM if running (force kill after timeout)
  2. Delete thin pool snapshot (if thin storage)
  3. Remove from containerd (search all namespaces)
  4. Remove state directory
  5. Delete VM directory ($VMDIR/{ns}/{name}/)
```

## VM List (how UI gets the list)

```php
function microvm_list_vms() {
  // 1. Scan config dirs: glob("$vmdir/*/*/") — finds all VMs across namespaces
  //    → gives: name, namespace, vmm, config

  // 2. Process scan: pidof cloud-hypervisor + pidof firecracker
  //    + /proc/PID/cmdline matching → determines running state

  // 3. Containerd scan: ctr containers list (all ns except flintlock)
  //    → catches orphans registered in containerd but missing config files

  // 4. State dir scan: /var/run/microvms/*/*/metadata.json
  //    → catches orphans with state but missing config
}
```

## Service Boot Sequence

```
rc.microvms start:
  [1/7] Load dm_thin_pool kernel module
  [2/7] Setup thin pool (sparse files → loop devices → dmsetup create)
  [3/7] Start microvms-containerd (devmapper snapshotter)
        → auto-create namespaces: default + ch (if enabled) + fc (if enabled)
  [4/7] Start local OCI registry (crane serve, port 5050)  [if FLINTLOCKD=enable]
  [5/7] Start flintlockd (gRPC 0.0.0.0:9090)              [if FLINTLOCKD=enable]
  [6/7] Clean orphan TAPs, create TAPs for all configured VMs
  [7/7] Autostart VMs: scan $VMDIR/*/*/, check autostart=true, call start_vm
```

## Console Model

### Cloud Hypervisor
```
Input:  console_input PHP → printf > /dev/pts/N (direct PTY write)
Output: cat /dev/pts/N >> {name}.serial.log (background process)
UI:     polls console_output → tail -100 {name}.serial.log
```
PTY allocated by CH (`--serial pty`). Path from log on normal start, or `ch-remote info` after restore.

### Firecracker
```
Input:  console_input PHP → printf > /tmp/microvms-{name}.fifo
Output: FC stdout piped to /var/log/microvms/firecracker/{name}.log
UI:     polls console_output → tail -100 {name}.log
```
FIFO created before FC start: `(tail -f $fifo | firecracker --api-sock ...) >> .log`

```
/mnt/user/microvms/                    ← VMDIR (persistent, user share)
├── ch/                                ← CH namespace
│   └── {name}/
│       ├── cloud-hypervisor.json      ← VM config (source of truth)
│       ├── rootfs.raw                 ← Raw rootfs (if raw storage)
│       └── snapshots/{tag}/           ← CH snapshots
├── fc/                                ← FC namespace
│   └── {name}/
│       ├── firecracker.json
│       └── snapshots/{tag}/           ← FC snapshots
├── default/                           ← Default namespace (fallback)
└── thinpool/
    ├── data                           ← Thin pool sparse file (default 64G)
    └── meta                           ← Thin pool metadata (500M)

/mnt/user/system/microvms/             ← System data (persistent)
├── cloud-hypervisor/kernels/vmlinux   ← CH kernel
├── firecracker/kernels/vmlinux        ← FC kernel
└── containerd/                        ← containerd data root
    └── io.containerd.metadata.v1.bolt/meta.db

/var/run/microvms/                     ← tmpfs (runtime, cleared on reboot)
├── containerd.sock                    ← containerd socket
├── containerd.pid
├── containerd-config.toml             ← generated config
└── {ns}/{name}/                       ← Per-VM state
    ├── {vmm}.pid
    ├── {vmm}.sock → /tmp/microvms-{name}.sock
    └── metadata.json

/var/log/microvms/                     ← tmpfs (logs, cleared on reboot)
├── backend.log                        ← PHP AJAX log
├── containerd.log
├── cloud-hypervisor/{name}.log        ← CH process stdout/stderr
├── cloud-hypervisor/{name}.serial.log ← CH serial PTY capture (guest output)
├── firecracker/{name}.log             ← FC process output (IS serial)

/tmp/microvms-{name}.sock              ← VMM API socket
/tmp/microvms-{name}.fifo              ← Console input FIFO (FC active, CH legacy)

/boot/config/plugins/microvms/         ← Flash (survives reboot)
├── microvms.controlplane.cfg          ← Plugin settings
├── cloud-hypervisor                   ← Binary cache
├── firecracker
├── catatonit
├── crane, ttyd, grpcurl, flintlockd
```

## Guest Init Architecture

```
Kernel boots → /fly/init (symlink → /fly/init)
  1. Mount proc/sys/dev/devpts
  2. Read /fly/run.json (entrypoint, cmd, hostname, dns, console)
  3. Set hostname, write /etc/resolv.conf
  4. Generate /fly/run.sh (properly quoted exec command)
  5. If console=true:
       Start app via catatonit in background
       Spawn shell on /dev/ttyS0
       PID 1 waits forever
     Else:
       exec catatonit -- /fly/run.sh
```

Network configured by kernel `ip=` parameter (no userspace tools needed).

## Scaling Path

| Phase | State Management | Hosts |
|-------|-----------------|-------|
| **Current** | Config files + state files + process scan | Single |
| **Phase 2** | SQLite DB (like Fly.io's flyd) | Single (foundation) |
| **Phase 3** | SQLite + gossip sync (like Corrosion) | Multi-host |
| **Phase 4** | gRPC API (flintlockd or custom) | K8s integration |



## Design: Plugin + Flintlockd Coexistence

### Insight from flintlock source code:

Flintlock uses **containerd's content store** as its VM spec database and **PID files** for runtime state. It doesn't have its own database — containerd IS the database.

```
Flintlock stores VM specs in: containerd content store (JSON blobs with labels)
Flintlock tracks running state in: {stateDir}/{vmid}/cloudhypervisor.pid
Flintlock reconciles: desired (content store) vs actual (PID alive?)
```

### How to support both WebGUI and flintlockd:

**Shared layer (both modes use):**
- containerd = images + snapshots + VM spec storage
- State directory = PID files, sockets, logs
- VMM providers = cloud-hypervisor, firecracker

**WebGUI Direct Mode:**
```
WebGUI → PHP → rc.microvms → VMM
  writes: config JSON (/mnt/user/microvms/{ns}/{name}/{vmm}.json)
  writes: state dir (/var/run/microvms/{ns}/{name}/metadata.json)
  writes: containerd labels (ctr containers create --label microvm.*)
```

**Flintlockd Mode:**
```
gRPC → flintlockd → containerd content store → VMM
  writes: VM spec to containerd content store
  writes: PID file to state dir
  reconciles: spec vs reality
```

### The key: SHARE THE STATE DIRECTORY

Both WebGUI and flintlockd use the **same state directory** format:
```
/var/run/microvms/{namespace}/{name}/
├── cloudhypervisor.pid   (or firecracker.pid)
├── cloudhypervisor.sock → /tmp/microvms-{name}.sock
└── metadata.json
```

Then:
- WebGUI can see VMs created by flintlockd (read PID files)
- Flintlockd can see VMs created by WebGUI (if we also write to content store)
- Both modes can coexist on the same host

### Implementation plan:

1. **Phase 1 (done):** WebGUI uses state dirs in `/var/run/microvms/{ns}/{name}/`
   - Same directory structure as flintlock expects
   - PID file, socket paths match flintlock conventions
   - Containerd container registration shares visibility with flintlockd

2. **Phase 2:** When flintlockd is enabled, it reads the same state dir
   - VMs created by WebGUI are visible to flintlockd
   - VMs created by flintlockd are visible to WebGUI
   - No conflict because state dir is shared

3. **Phase 3:** WebGUI also writes VM spec to containerd content store
   - Full bidirectional: flintlockd can reconcile WebGUI-created VMs
   - WebGUI can show flintlockd-created VMs

### What this means for our state file format:

Match flintlock's directory structure:
```
/var/run/microvms/{namespace}/{name}/
├── {vmm}.pid          ← PID of VMM process
├── {vmm}.sock         ← API socket
├── {vmm}.log          ← VMM log
├── {vmm}.stdout       ← VM serial output
├── metadata.json      ← VM state + config summary
```

This is compatible with flintlock's `fsState` implementation and allows both modes to coexist.
