# Dependencies

## External Binaries

| Dependency | Version | Source | Purpose |
|-----------|---------|--------|---------|
| cloud-hypervisor | v52.0 | [GitHub](https://github.com/cloud-hypervisor/cloud-hypervisor) | VMM (Rust, static) |
| ch-remote | v52.0 | Same release | CH management CLI |
| firecracker | v1.16.1 | [GitHub](https://github.com/firecracker-microvm/firecracker) | VMM (Rust, static) |
| containerd | v1.7.27 | [GitHub](https://github.com/containerd/containerd) | Container runtime (Go, static) |
| flintlockd | v0.9.0 | [GitHub](https://github.com/liquidmetal-dev/flintlock) | gRPC orchestrator (Go) |
| crane | latest | [GitHub](https://github.com/google/go-containerregistry) | OCI image tool + registry |
| grpcurl | v1.9.1 | [GitHub](https://github.com/fullstorydev/grpcurl) | gRPC CLI client |
| ttyd | v1.7.7 | [GitHub](https://github.com/tsl0922/ttyd) | Web terminal |

## Kernel Modules

| Module | Purpose |
|--------|---------|
| `kvm_intel` / `kvm_amd` | Hardware virtualization (/dev/kvm) |
| `dm_thin_pool` | Device-mapper thin provisioning |
| `tun` | TAP interface support |

## Unraid Platform Dependencies

| Component | Usage |
|-----------|-------|
| emhttpd | Plugin framework, event system |
| nginx + php-fpm | WebGUI hosting |
| br0 bridge | VM network connectivity |
| FUSE (shfs) | /mnt/user user share filesystem |
| Flash (VFAT) | Persistent plugin config + binaries |

## Compatibility

| Component | Min Version | Max Version | Notes |
|-----------|-------------|-------------|-------|
| Unraid | 6.12+ | 7.2.x | Kernel 6.x with KVM |
| containerd | 1.7.0 | 1.7.33 | LTS until Sept 2026, no 2.x |
| flintlockd | 0.9.0 | 0.9.x | Supports CH v41+ and FC v1.11+ |
| Cloud Hypervisor | v41+ | v52+ | API unchanged |
| Firecracker | v1.11+ | v1.16.x | Config format stable |
