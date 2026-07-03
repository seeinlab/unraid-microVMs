# Architecture Overview

## Decision: Cloud Hypervisor as Primary VMM

### Why Cloud Hypervisor over Firecracker for Docker/Unraid:

| Feature | Cloud Hypervisor v52.0 | Firecracker v1.16.0 |
|---------|----------------------|---------------------|
| Docker stability | ✅ Excellent | ⚠️ cgroup conflicts, no ACPI |
| Graceful shutdown | ✅ ACPI power-button | ❌ SIGKILL only |
| Live CPU/RAM hotplug | ✅ | ❌ |
| Snapshot/restore | ✅ | ✅ |
| Windows guests | ✅ | ❌ |
| GPU passthrough | ✅ VFIO | ❌ |
| Boot time | ~200ms | ~125ms |
| Memory overhead | ~15MB/VM | ~5MB/VM |
| Binary size | 5.5MB static | 4.7MB static |

### Stack Options (tested & proven)

```
Option A: Simple (proven on Unraid)
  cloud-hypervisor + ch-remote + crane
  Direct rootfs.raw per VM, TAP on br0
  
Option B: Full orchestration  
  flintlockd + containerd + cloud-hypervisor
  OCI images, devmapper thin pool, gRPC API

Option C: Docker-wrapped
  Docker container (privileged, /dev/kvm)
  Internal bridge + NAT, volume-mounted state
```

### Recommended for Unraid Plugin: Option A + parts of B

- Use CH directly for VM lifecycle (simple, proven)
- Use crane for OCI image pull (no containerd needed for basic use)
- Add flintlockd + containerd as "advanced mode" later
- Plugin manages everything via rc.d script

## System Requirements (Proven on Unraid 6.12.90)

- Intel i5-13500 (VT-x, 20 threads)
- 32GB RAM (27GB available)
- KVM module loaded (`/dev/kvm` accessible)
- Nested virtualization enabled
- NVMe cache drive for VM storage
- br0 bridge (Unraid default)

## VM Lifecycle

```
Persistent (survives reboot):
  /mnt/user/appdata/microvm/
  ├── kernels/vmlinux
  └── vm0/
      ├── config.json     (VM definition)
      ├── rootfs.raw      (disk image)  
      └── snapshots/      (VM state backups)

Ephemeral (recreated each boot by rc script):
  • TAP interfaces (tap-vm0, tap-vm1...)
  • cloud-hypervisor processes
  • API sockets (/tmp/microvm-*.sock)
  • PID files

Boot sequence:
  1. PLG copies binaries to /usr/local/bin/
  2. rc.microvm start:
     a. Create TAP devices, attach to br0
     b. (Optional) Setup thin pool + start containerd + flintlockd
     c. Start VMs from saved configs (if autostart=yes)
```
