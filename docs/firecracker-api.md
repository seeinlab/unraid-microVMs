# Firecracker API Reference (v1.16.0+)

## Source: github.com/firecracker-microvm/firecracker (swagger/firecracker.yaml)

## Overview
- **Transport**: REST API over Unix Domain Socket
- **Format**: JSON request/response
- **Socket**: Specified via `--api-sock /path/to/socket`
- **Versioning**: 1.17.0-dev (latest from main)

## Access Pattern
```bash
# All API calls via Unix socket using curl
curl --unix-socket /tmp/microvm-NAME.sock -X METHOD http://localhost/ENDPOINT -d 'JSON'
```

---

## Endpoints

### Instance Info
| Method | Path | Description |
|--------|------|-------------|
| GET | `/` | Returns instance info (id, state, vmm_version, app_name) |

### Boot & Machine Config (Pre-boot only)
| Method | Path | Description |
|--------|------|-------------|
| PUT | `/boot-source` | Set kernel path and boot args |
| PUT | `/machine-config` | Set vCPU count, memory size, SMT |
| PUT | `/cpu-config` | Configure CPU feature flags |

### Drives
| Method | Path | Description |
|--------|------|-------------|
| PUT | `/drives/{drive_id}` | Create/update drive (pre-boot) |
| PATCH | `/drives/{drive_id}` | Update drive properties (post-boot, limited) |

### Network
| Method | Path | Description |
|--------|------|-------------|
| PUT | `/network-interfaces/{iface_id}` | Create/update network interface (pre-boot) |

### Actions
| Method | Path | Description |
|--------|------|-------------|
| PUT | `/actions` | Execute action: InstanceStart, SendCtrlAltDel, FlushMetrics |

### Snapshots
| Method | Path | Description |
|--------|------|-------------|
| PUT | `/snapshot/create` | Create VM snapshot (pause first) |
| PUT | `/snapshot/load` | Load/restore VM from snapshot |
| PATCH | `/vm` | Update VM state (Paused/Resumed) |

### Balloon (Memory management)
| Method | Path | Description |
|--------|------|-------------|
| PUT | `/balloon` | Create/update balloon device |
| GET | `/balloon` | Get balloon config |
| PATCH | `/balloon` | Update balloon (post-boot) |
| GET | `/balloon/statistics` | Get balloon stats |

### Entropy
| Method | Path | Description |
|--------|------|-------------|
| PUT | `/entropy` | Configure entropy device |

### Metrics & Logging
| Method | Path | Description |
|--------|------|-------------|
| PUT | `/metrics` | Configure metrics output |
| PUT | `/logger` | Configure logging |

### MMDS (MicroVM Metadata Service)
| Method | Path | Description |
|--------|------|-------------|
| PUT | `/mmds/config` | Configure MMDS |
| PUT | `/mmds` | Set MMDS data |
| GET | `/mmds` | Get MMDS data |
| PATCH | `/mmds` | Update MMDS data |

---

## Key Request Bodies

### BootSource
```json
{
  "kernel_image_path": "/path/to/vmlinux",
  "boot_args": "console=ttyS0 root=/dev/vda rw",
  "initrd_path": null
}
```

### MachineConfig
```json
{
  "vcpu_count": 2,
  "mem_size_mib": 256,
  "smt": false
}
```

### Drive
```json
{
  "drive_id": "rootfs",
  "path_on_host": "/path/to/rootfs.raw",
  "is_root_device": true,
  "is_read_only": false,
  "rate_limiter": null
}
```

### NetworkInterface
```json
{
  "iface_id": "eth0",
  "guest_mac": "52:54:00:aa:bb:cc",
  "host_dev_name": "tap-vmname"
}
```

### Actions
```json
{"action_type": "InstanceStart"}
{"action_type": "SendCtrlAltDel"}
{"action_type": "FlushMetrics"}
```

### Snapshot Create
```json
{
  "snapshot_type": "Full",
  "snapshot_path": "/path/to/snapshot",
  "mem_file_path": "/path/to/mem"
}
```

### Snapshot Load
```json
{
  "snapshot_path": "/path/to/snapshot",
  "mem_backend": {"backend_path": "/path/to/mem", "backend_type": "File"},
  "enable_diff_snapshots": false
}
```

### VM State (Pause/Resume)
```json
{"state": "Paused"}
{"state": "Resumed"}
```

---

## Config File Format (--config-file)
```json
{
  "boot-source": {
    "kernel_image_path": "/path/vmlinux",
    "boot_args": "console=ttyS0 root=/dev/vda rw init=/init ip=..."
  },
  "drives": [
    {"drive_id": "rootfs", "path_on_host": "/path/rootfs.raw", "is_root_device": true, "is_read_only": false}
  ],
  "network-interfaces": [
    {"iface_id": "eth0", "guest_mac": "52:54:00:xx:xx:xx", "host_dev_name": "tap-name"}
  ],
  "machine-config": {
    "vcpu_count": 2,
    "mem_size_mib": 256
  }
}
```

---

## Limitations vs Cloud Hypervisor
| Feature | Firecracker | Cloud Hypervisor |
|---------|-------------|-----------------|
| ACPI shutdown | ❌ SendCtrlAltDel only | ✅ power-button |
| CPU hotplug | ❌ | ✅ vm.resize |
| Memory hotplug | ❌ (balloon only) | ✅ vm.resize |
| Device hotplug | ❌ | ✅ add-disk/net |
| Serial PTY | ❌ stdout only | ✅ --serial pty |
| Snapshot | ✅ Full/Diff | ✅ |
| Restore | ✅ | ✅ |
| Disk resize | ❌ | ✅ resize-disk |
| VFIO/GPU | ❌ | ✅ |
| Windows guest | ❌ | ✅ |

---

## Example: Full Lifecycle via API
```bash
SOCK=/tmp/microvm-test.sock

# 1. Start firecracker
firecracker --api-sock $SOCK &

# 2. Configure boot source
curl --unix-socket $SOCK -X PUT http://localhost/boot-source \
  -d '{"kernel_image_path":"/path/vmlinux","boot_args":"console=ttyS0 root=/dev/vda rw"}'

# 3. Configure rootfs
curl --unix-socket $SOCK -X PUT http://localhost/drives/rootfs \
  -d '{"drive_id":"rootfs","path_on_host":"/path/rootfs.raw","is_root_device":true,"is_read_only":false}'

# 4. Configure network
curl --unix-socket $SOCK -X PUT http://localhost/network-interfaces/eth0 \
  -d '{"iface_id":"eth0","guest_mac":"52:54:00:aa:bb:cc","host_dev_name":"tap-test"}'

# 5. Configure machine
curl --unix-socket $SOCK -X PUT http://localhost/machine-config \
  -d '{"vcpu_count":2,"mem_size_mib":256}'

# 6. Start VM
curl --unix-socket $SOCK -X PUT http://localhost/actions -d '{"action_type":"InstanceStart"}'

# 7. Pause for snapshot
curl --unix-socket $SOCK -X PATCH http://localhost/vm -d '{"state":"Paused"}'

# 8. Create snapshot
curl --unix-socket $SOCK -X PUT http://localhost/snapshot/create \
  -d '{"snapshot_type":"Full","snapshot_path":"/path/snap","mem_file_path":"/path/mem"}'

# 9. Resume
curl --unix-socket $SOCK -X PATCH http://localhost/vm -d '{"state":"Resumed"}'
```
