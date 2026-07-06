# Codebase Information

## Project
**microVMs Plugin for Unraid** — WebGUI plugin that enables running Cloud Hypervisor and Firecracker microVMs directly on Unraid with OCI image support, devmapper thin provisioning, and optional Liquidmetal gRPC orchestration.

## Technology Stack
- **Backend**: PHP 8.x (Unraid WebGUI framework), Bash (service management)
- **Frontend**: Unraid markdown form pages, jQuery, SweetAlert
- **VMMs**: Cloud Hypervisor v52.0 (Rust), Firecracker v1.16.1 (Rust)
- **Container Runtime**: containerd v1.7.27 (devmapper snapshotter)
- **OCI Tools**: crane (image pull/export), grpcurl (gRPC client)
- **Storage**: devmapper thin provisioning, ext4 raw files
- **Networking**: TAP interfaces bridged to br0

## Languages
| Language | Purpose | Files |
|----------|---------|-------|
| PHP | WebGUI backend, AJAX handlers | MicroVMAdmin.php, common.php |
| Bash | Service management, VM lifecycle | rc.microvms, event hooks |
| Unraid .page | WebGUI pages (PHP + markdown) | *.page files |
| JSON | VM configs, API schema | vm-config-schema.json |
| XML | Plugin installer | microvms.plg |

## Repository Structure
```
unraid-microVMs/
├── src/                          # Source files (installed to Unraid)
│   ├── usr/local/bin/            # Console helper
│   ├── usr/local/emhttp/plugins/microvms/  # WebGUI plugin
│   │   ├── *.page               # UI pages
│   │   ├── backend/             # PHP AJAX handler
│   │   ├── include/             # Shared PHP functions
│   │   ├── event/               # Unraid event hooks
│   │   └── images/              # VMM icons
│   └── usr/local/etc/rc.d/      # Service script
├── plugin/                       # PLG installer + tgz package
├── docs/                         # Documentation
└── scripts/                      # Build/utility scripts
```

## Key Binaries (installed to /usr/local/bin/)
| Binary | Source | Purpose |
|--------|--------|---------|
| cloud-hypervisor | GitHub release | VMM |
| ch-remote | GitHub release | CH management CLI |
| firecracker | GitHub release | VMM |
| microvms-containerd | containerd release | Container runtime |
| flintlockd | GitHub release | gRPC orchestrator |
| crane | go-containerregistry | OCI tool |
| grpcurl | GitHub release | gRPC client |
| ttyd | GitHub release | Web terminal |
