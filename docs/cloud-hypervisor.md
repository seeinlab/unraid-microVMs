# Cloud Hypervisor Reference

## Binary Info
- Static binary: `cloud-hypervisor-static` (5.5MB)
- Management CLI: `ch-remote-static` (1.7MB)
- Both are fully static (no dependencies)
- Download: https://github.com/cloud-hypervisor/cloud-hypervisor/releases/latest

## REST API (UNIX socket)

### Endpoints

| Operation | Method | Endpoint |
|-----------|--------|----------|
| Ping VMM | GET | `/api/v1/vmm.ping` |
| Create VM | PUT | `/api/v1/vm.create` |
| Boot VM | PUT | `/api/v1/vm.boot` |
| VM Info | GET | `/api/v1/vm.info` |
| Shutdown VM | PUT | `/api/v1/vm.shutdown` |
| Delete VM | PUT | `/api/v1/vm.delete` |
| Shutdown VMM | PUT | `/api/v1/vmm.shutdown` |
| Resize | PUT | `/api/v1/vm.resize` |
| Add disk | PUT | `/api/v1/vm.add-disk` |
| Add net | PUT | `/api/v1/vm.add-net` |
| Remove device | PUT | `/api/v1/vm.remove-device` |
| Pause | PUT | `/api/v1/vm.pause` |
| Resume | PUT | `/api/v1/vm.resume` |
| Snapshot | PUT | `/api/v1/vm.snapshot` |
| Restore | PUT | `/api/v1/vm.restore` |
| Power button | PUT | `/api/v1/vm.power-button` |

### CLI Commands (ch-remote)

```bash
ch-remote --api-socket /tmp/vm.sock ping
ch-remote --api-socket /tmp/vm.sock info
ch-remote --api-socket /tmp/vm.sock resize --cpus 4 --memory 1073741824
ch-remote --api-socket /tmp/vm.sock add-disk path=/data/extra.raw
ch-remote --api-socket /tmp/vm.sock pause
ch-remote --api-socket /tmp/vm.sock resume
ch-remote --api-socket /tmp/vm.sock snapshot file:///path/to/snapshot
ch-remote --api-socket /tmp/vm.sock power-button     # Graceful ACPI shutdown
ch-remote --api-socket /tmp/vm.sock shutdown          # Shutdown VM
ch-remote --api-socket /tmp/vm.sock shutdown-vmm      # Kill VMM process
```

### Boot Command (direct)

```bash
cloud-hypervisor \
  --api-socket /tmp/vm.sock \
  --kernel /path/to/vmlinux \
  --disk path=/path/to/rootfs.raw \
  --cmdline "console=hvc0 root=/dev/vda rw init=/init ip=192.168.50.200::192.168.50.1:255.255.255.0:::off" \
  --cpus boot=2,max=4 \
  --memory size=512M,hotplug_size=2048M \
  --net tap=tap-name,mac=52:54:00:aa:bb:01 \
  --serial off --console off \
  -v &
```

### JSON Config (vm.create API)

```json
{
  "cpus": {"boot_vcpus": 2, "max_vcpus": 4},
  "memory": {"size": 536870912, "hotplug_size": 2147483648},
  "payload": {
    "kernel": "/path/to/vmlinux",
    "cmdline": "console=hvc0 root=/dev/vda rw init=/init ip=192.168.50.200::192.168.50.1:255.255.255.0:::off"
  },
  "disks": [{"path": "/path/to/rootfs.raw", "readonly": false}],
  "net": [{"tap": "tap-name", "mac": "52:54:00:aa:bb:01"}],
  "rng": {"src": "/dev/urandom"},
  "serial": {"mode": "Off"},
  "console": {"mode": "Off"}
}
```

### Kernel
- Pre-built: https://github.com/cloud-hypervisor/linux/releases
- Latest tested: `ch-release-v6.2-20240908` (vmlinux, 61MB)
- Must be uncompressed ELF (vmlinux), NOT bzImage

### Networking
- Uses TAP devices attached to a Linux bridge
- Kernel `ip=` parameter for static IP: `ip=IP::GATEWAY:NETMASK:::off`
- No device name needed in 6th field — kernel auto-detects first virtio-net device
- CH names it `enp0s*` (PCI), but kernel ip= autoconfig still works

### Key Features Tested on Unraid
- Live CPU hotplug (boot=1 → resize to 4) ✅
- Live memory hotplug (256M → 1G) ✅ 
- Live disk hot-add ✅
- Snapshot (pause → snap → resume) ✅
- Restore from snapshot ✅
- ACPI power-button (graceful shutdown) ✅
- TAP on br0 (LAN-accessible VMs) ✅
