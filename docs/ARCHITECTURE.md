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
