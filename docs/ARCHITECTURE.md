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
| VM definition (what SHOULD exist) | JSON config file | `/mnt/user/microvms/{name}/{vmm}.json` |
| VM runtime state (what IS running) | State file + process scan | `/var/run/microvms/{name}.state` + `pidof` |
| OCI images | containerd | namespace `default` |
| Rootfs snapshots (thin pool) | containerd devmapper | namespace `default` |
| Network (TAP) | kernel (created at start) | scanned from config on start |
| Logs | log files | `/var/log/microvms/{vmm}/{name}.log` |

## State File Format

`/var/run/microvms/{name}.state` (tmpfs — cleared on reboot)

```json
{
  "name": "my-vm",
  "vmm": "cloud-hypervisor",
  "pid": 12345,
  "state": "running",
  "started_at": "2026-07-08T06:30:00Z",
  "socket": "/tmp/microvms-my-vm.sock",
  "tap": "tap3",
  "fifo": "/tmp/microvms-my-vm.fifo"
}
```

States: `running`, `stopped`, `creating`, `error`

## VM Lifecycle

### Create
```
UI → MicroVMAdmin.php (create)
  1. Pull image (containerd -n default)
  2. Mount snapshot / create raw rootfs
  3. Inject /fly/init + catatonit + run.json
  4. Unmount
  5. Write {vmm}.json config
  6. Write state file: {state: "stopped"}
  7. If autostart → start_vm
```

### Start
```
rc.microvms start_vm {name}
  1. Read config JSON
  2. Create TAP if not exists
  3. Build kernel cmdline (ip= from config)
  4. Launch VMM (CH or FC)
  5. Write state file: {state: "running", pid: N}
  6. Set up FIFO (FC) or serial capture (CH)
```

### Stop
```
rc.microvms stop_vm {name}
  1. CH: ACPI power-button → 10s timeout → kill -9
     FC: kill -9 (no graceful)
  2. Cleanup: FIFO, PTY, TAP (if configured)
  3. Write state file: {state: "stopped", pid: null}
```

### Remove
```
MicroVMAdmin.php (delete)
  1. Stop VM if running
  2. Delete config JSON + rootfs
  3. Delete thin pool snapshot (if thin)
  4. Remove state file
```

## VM List (how UI gets the list)

```php
function microvm_list_vms() {
  // 1. Scan config files (persistent definitions)
  //    → gives: name, vmm, config, socket path

  // 2. Read state files (runtime truth)
  //    → gives: pid, state, started_at

  // 3. Cross-check: if state=running but pid dead → mark stopped
  //    (self-healing on crash)

  // 4. Detect orphans: running VMM processes with no config
  //    → show as "orphan" with Force Stop option
}
```

## Containerd Role (limited, focused)

Containerd is NOT the VM lifecycle manager. It handles:
- ✅ OCI image pull + storage
- ✅ Devmapper thin pool snapshots
- ✅ Garbage collection of unused images/snapshots
- ❌ NOT VM process management
- ❌ NOT state tracking
- ❌ NOT networking

## File Layout

```
/mnt/user/microvms/                    ← Array (persistent)
├── {name}/
│   ├── cloud-hypervisor.json          ← VM config (source of truth)
│   ├── rootfs.raw                     ← Raw rootfs (if raw storage)
│   └── snapshots/{tag}/               ← CH/FC snapshots
├── thinpool/
│   ├── data                           ← Thin pool sparse file (64G)
│   └── meta                           ← Thin pool metadata (500M)

/mnt/user/system/microvms/             ← Array (persistent)
├── cloud-hypervisor/kernels/vmlinux   ← CH kernel
├── firecracker/kernels/vmlinux        ← FC kernel
└── containerd/                        ← containerd data root
    └── io.containerd.metadata.v1.bolt/meta.db

/var/run/microvms/                     ← tmpfs (runtime, cleared on reboot)
├── containerd.sock                    ← containerd socket
├── containerd-config.toml             ← generated config
├── {name}.state                       ← VM state files (NEW)

/var/log/microvms/                     ← tmpfs (logs, cleared on reboot)
├── containerd.log
├── cloud-hypervisor/{name}.log        ← VMM process log
├── cloud-hypervisor/{name}.serial.log ← VM serial output
├── firecracker/{name}.log             ← FC serial output (stdout)

/tmp/microvms-{name}.sock              ← VMM API socket
/tmp/microvms-{name}.fifo              ← Console input FIFO

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
  writes: config JSON (user-facing, /mnt/user/microvms/{name}/)
  writes: state file (/var/run/microvms/{name}.state)
  writes: PID file (same dir flintlock would use)
```

**Flintlockd Mode:**
```
gRPC → flintlockd → containerd content store → VMM
  writes: VM spec to containerd content store
  writes: PID file to state dir
  reconciles: spec vs reality
```

### The key: SHARE THE STATE DIRECTORY

If both WebGUI and flintlockd use the **same state directory** format:
```
/var/run/microvms/{name}/
├── cloudhypervisor.pid   (or firecracker.pid)
├── cloudhypervisor.sock
├── cloudhypervisor.log
```

Then:
- WebGUI can see VMs created by flintlockd (read PID files)
- Flintlockd can see VMs created by WebGUI (if we also write to content store)
- Both modes can coexist on the same host

### Implementation plan:

1. **Phase 1 (now):** WebGUI uses state files in `/var/run/microvms/{name}/`
   - Same directory structure as flintlock expects
   - PID file, socket, log paths match flintlock conventions

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
