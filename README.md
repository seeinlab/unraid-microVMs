# MicroVM Manager for Unraid

An Unraid plugin that enables running Cloud Hypervisor microVMs directly on Unraid with a WebGUI interface.

## Features

- **Cloud Hypervisor v52.0** — Modern, Rust-based VMM with live resize, snapshots, ACPI shutdown
- **WebGUI Integration** — Settings page, VM list, create/start/stop/resize from browser
- **LAN-accessible VMs** — MicroVMs get IPs on your network via br0 bridge
- **OCI Image Support** — Pull Docker images and convert to VM rootfs via `crane`
- **Persistent Config** — VM definitions survive reboots, auto-start on array start
- **Live Operations** — Hot-add CPU/RAM, snapshot/restore without downtime

## Architecture

```
Unraid Host
├── /usr/local/bin/cloud-hypervisor  (5.5MB static binary)
├── /usr/local/bin/ch-remote         (management CLI)
├── /usr/local/bin/crane             (OCI image tool)
├── /etc/rc.d/rc.microvm             (service management)
└── /mnt/user/appdata/microvm/       (persistent VM data)
    ├── kernels/vmlinux
    └── vm-name/
        ├── config.json
        ├── rootfs.raw
        └── snapshots/
```

## Installation

### From Community Applications (planned)
Search "MicroVM Manager" in CA and install.

### Manual
```
https://raw.githubusercontent.com/YOUR/unraid-microVMs/main/plugin/microvm.manager.plg
```

## Quick Start

1. Install plugin → Settings → MicroVM Manager → Enable → Apply
2. Create a VM via WebGUI or CLI:
```bash
# Create rootfs from OCI image
crane export nginx:alpine /mnt/user/appdata/microvm/web/rootfs-export.tar
# ... (see docs for full rootfs creation)

# Start VM
/etc/rc.d/rc.microvm start_vm web
```

## Status

🚧 **In Development** — Core functionality proven, WebGUI in progress.

## Tested On
- Unraid 6.12.90 (kernel 6.12.90-Unraid)
- Intel i5-13500 (VT-x)
- 32GB RAM

## References
- [Cloud Hypervisor](https://github.com/cloud-hypervisor/cloud-hypervisor)
- [awesome-microvm](https://github.com/myugan/awesome-microvm)
- [Flintlock](https://github.com/liquidmetal-dev/flintlock)

## License
GPL-2.0 (consistent with Unraid plugin ecosystem)
