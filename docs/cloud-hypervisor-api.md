# Cloud Hypervisor API Reference (v52.0)

## Source: github.com/cloud-hypervisor/cloud-hypervisor (openapi/cloud-hypervisor.yaml)

## Overview
- **Transport**: REST API over Unix Domain Socket
- **Format**: JSON request/response (OpenAPI 3.0.1)
- **Socket**: Specified via `--api-socket /path/to/socket`
- **CLI**: `ch-remote --api-socket /path/to/socket COMMAND`
- **Base URL**: `http://localhost/api/v1`

## Access Pattern
```bash
# Via curl
curl --unix-socket /tmp/microvm-NAME.sock -X METHOD http://localhost/api/v1/ENDPOINT -H "Content-Type: application/json" -d 'JSON'

# Via ch-remote
ch-remote --api-socket /tmp/microvm-NAME.sock COMMAND [args]
```

---

## Endpoints

### VMM Management
| Method | Path | Description | ch-remote |
|--------|------|-------------|-----------|
| GET | `/vmm.ping` | Check VMM alive | `ping` |
| PUT | `/vmm.shutdown` | Kill VMM process | `shutdown-vmm` |

### VM Lifecycle
| Method | Path | Description | ch-remote |
|--------|------|-------------|-----------|
| PUT | `/vm.create` | Create VM (not boot) | N/A (use CLI flags) |
| PUT | `/vm.boot` | Boot created VM | N/A |
| PUT | `/vm.shutdown` | Shutdown VM guest | `shutdown` |
| PUT | `/vm.delete` | Delete VM | N/A |
| PUT | `/vm.reboot` | Reboot VM | `reboot` |
| PUT | `/vm.power-button` | ACPI power button | `power-button` |
| PUT | `/vm.pause` | Pause VM | `pause` |
| PUT | `/vm.resume` | Resume VM | `resume` |
| GET | `/vm.info` | Get VM state/config | `info` |
| GET | `/vm.counters` | Get device counters | N/A |

### Live Resize
| Method | Path | Description | ch-remote |
|--------|------|-------------|-----------|
| PUT | `/vm.resize` | Resize CPU/RAM | `resize --cpus N --memory BYTES` |
| PUT | `/vm.resize-disk` | Resize disk | `resize-disk --id ID --size BYTES` |
| PUT | `/vm.resize-zone` | Resize memory zone | `resize-zone --id ID --size BYTES` |

### Hot-add Devices
| Method | Path | Description | ch-remote |
|--------|------|-------------|-----------|
| PUT | `/vm.add-disk` | Add disk (hot) | `add-disk path=FILE` |
| PUT | `/vm.add-net` | Add network | `add-net tap=TAP` |
| PUT | `/vm.add-device` | Add VFIO device | `add-device path=SYSFS` |
| PUT | `/vm.add-fs` | Add virtio-fs | `add-fs ...` |
| PUT | `/vm.add-pmem` | Add pmem device | `add-pmem file=FILE` |
| PUT | `/vm.add-vdpa` | Add vDPA device | N/A |
| PUT | `/vm.add-vsock` | Add vsock | N/A |
| PUT | `/vm.remove-device` | Remove device | `remove-device --id ID` |

### Snapshots
| Method | Path | Description | ch-remote |
|--------|------|-------------|-----------|
| PUT | `/vm.snapshot` | Create snapshot | `snapshot file:///path/` |
| PUT | `/vm.restore` | Restore from snap | CLI: `--restore source_url=file:///path/` |
| PUT | `/vm.coredump` | Generate coredump | `coredump --destination FILE` |

### Migration
| Method | Path | Description |
|--------|------|-------------|
| PUT | `/vm.send-migration` | Live migrate (send) |
| PUT | `/vm.receive-migration` | Live migrate (receive) |

---

## Key Request Bodies

### VmConfig (vm.create)
```json
{
  "cpus": {"boot_vcpus": 2, "max_vcpus": 8},
  "memory": {"size": 536870912, "hotplug_size": 4294967296},
  "payload": {
    "kernel": "/path/to/vmlinux",
    "cmdline": "console=ttyS0 root=/dev/vda rw init=/init ip=...",
    "initramfs": null
  },
  "disks": [{
    "path": "/path/to/rootfs.raw",
    "readonly": false
  }],
  "net": [{
    "tap": "tap-name",
    "mac": "52:54:00:aa:bb:cc"
  }],
  "serial": {"mode": "Pty"},
  "console": {"mode": "Off"},
  "rng": {"src": "/dev/urandom"}
}
```

### VmResize
```json
{
  "desired_vcpus": 4,
  "desired_ram": 1073741824
}
```

### VmResizeDisk
```json
{
  "id": "_disk0",
  "new_size": 10737418240
}
```

### DiskConfig (add-disk)
```json
{
  "path": "/path/to/disk.raw",
  "readonly": false
}
```

### NetConfig (add-net)
```json
{
  "tap": "tap-name",
  "mac": "52:54:00:xx:xx:xx"
}
```

### Snapshot
```json
{
  "destination_url": "file:///path/to/snapshot-dir"
}
```

### Restore (CLI only — boot arg)
```bash
cloud-hypervisor --api-socket /tmp/vm.sock --restore source_url=file:///path/to/snapshot-dir
```

---

## Serial/Console Options

| Mode | Description | Use Case |
|------|-------------|----------|
| `Off` | Disabled | Production (no overhead) |
| `Null` | /dev/null sink | Default |
| `Pty` | Creates PTY device | Serial console access |
| `Tty` | Connected to host tty | Foreground debugging |
| `File` | Output to file | Logging guest output |
| `Socket` | Unix socket | Remote console access |

### PTY Path Discovery
When `--serial pty` is used, CH logs the PTY path:
```
serial: SerialConfig { common: CommonConsoleConfig { file: Some("/dev/pts/X"), mode: Pty, ...
```

---

## ch-remote CLI Commands (v52.0)

```bash
ch-remote --api-socket SOCK ping
ch-remote --api-socket SOCK info
ch-remote --api-socket SOCK resize --cpus 4 --memory 1073741824
ch-remote --api-socket SOCK resize-disk --id _disk0 --size 10737418240
ch-remote --api-socket SOCK add-disk path=/path/disk.raw
ch-remote --api-socket SOCK add-net tap=tap-new,mac=52:54:00:xx:xx:xx
ch-remote --api-socket SOCK remove-device --id _disk1
ch-remote --api-socket SOCK pause
ch-remote --api-socket SOCK resume
ch-remote --api-socket SOCK snapshot file:///path/to/snap/
ch-remote --api-socket SOCK power-button
ch-remote --api-socket SOCK shutdown
ch-remote --api-socket SOCK shutdown-vmm
ch-remote --api-socket SOCK reboot
ch-remote --api-socket SOCK coredump --destination /path/core
```

---

## CLI Boot (Direct Start without API)
```bash
cloud-hypervisor \
  --api-socket /tmp/vm.sock \
  --kernel /path/to/vmlinux \
  --disk path=/path/rootfs.raw \
  --cmdline "console=ttyS0 root=/dev/vda rw init=/init ip=IP::GW:MASK:::off" \
  --cpus boot=2,max=8 \
  --memory size=512M,hotplug_size=4G \
  --net tap=tap-name,mac=52:54:00:aa:bb:cc \
  --serial pty --console off \
  -v &
```

---

## Comparison: Direct CLI vs API

| Operation | CLI (current plugin) | API (future) |
|-----------|---------------------|--------------|
| Create+Boot | `cloud-hypervisor --kernel ... &` | PUT vm.create + PUT vm.boot |
| Stop | `ch-remote power-button` | PUT vm.power-button |
| Kill | `ch-remote shutdown-vmm` | PUT vmm.shutdown |
| Resize | `ch-remote resize` | PUT vm.resize |
| Snapshot | `ch-remote pause + snapshot` | PUT vm.pause + vm.snapshot |
| Add disk | `ch-remote add-disk` | PUT vm.add-disk |
| Info | `ch-remote info` | GET vm.info |

Current plugin uses CLI approach (simpler, proven). API approach enables more control but requires running VMM in "API-first" mode (create → configure → boot separately).

---

## v52.0 Features Used by Plugin
- ✅ `--serial pty` (console access)
- ✅ `--cpus boot=N,max=M` (resize headroom)
- ✅ `--memory size=X,hotplug_size=Y` (memory resize)
- ✅ `ch-remote resize` (live hotplug)
- ✅ `ch-remote snapshot/restore`
- ✅ `ch-remote power-button` (ACPI shutdown)
- ❌ `--restore` (not yet in plugin, planned)
- ❌ `vm.add-net` (future: dynamic NICs)
- ❌ Live migration (future)
