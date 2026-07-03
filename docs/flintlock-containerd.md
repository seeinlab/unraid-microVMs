# Flintlock & Containerd

## Overview

Flintlock is a gRPC service that manages microVM lifecycle using containerd for OCI image storage.
It's optional — the basic plugin works without it (direct CH + rootfs.raw). Add it for:
- Automatic OCI image → VM rootfs conversion
- Device-mapper thin provisioning (instant VM cloning)
- gRPC API for programmatic VM management
- Multi-host fleet management (via CAPMVM)

## Components

```
flintlockd (Go binary, ~30MB static)
  ├── Connects to containerd (for image pulling/storage)
  ├── Manages Firecracker or Cloud Hypervisor processes
  ├── Exposes gRPC API on port 9090
  └── Creates TAP interfaces per VM

containerd (Go binary, ~45MB static)
  ├── Pulls OCI images from registries
  ├── Manages snapshotter (overlayfs or devmapper)
  └── Provides content store for kernel/rootfs images
```

## Starting Without Thin Pool (Simple Mode)

```bash
# containerd config (overlayfs snapshotter — no thin pool needed)
cat > /run/microvm-containerd/config.toml << 'EOF'
version = 2
root = "/mnt/user/appdata/microvm/containerd"
state = "/run/microvm-containerd"
[grpc]
  address = "/run/microvm-containerd/containerd.sock"
EOF

# Start containerd
mkdir -p /run/microvm-containerd
containerd --config /run/microvm-containerd/config.toml &
sleep 2

# Start flintlockd
flintlockd run \
  --containerd-socket=/run/microvm-containerd/containerd.sock \
  --grpc-endpoint=0.0.0.0:9090 \
  --parent-iface=br0 \
  --default-provider=cloudhypervisor \
  --insecure &
```

## Starting With Thin Pool (Advanced Mode)

```bash
# Create thin pool (once, data persists in files)
truncate -s 100G /mnt/cache/appdata/microvm/dm-data
truncate -s 2G /mnt/cache/appdata/microvm/dm-meta

# Attach (every boot)
DATA=$(losetup --find --show /mnt/cache/appdata/microvm/dm-data)
META=$(losetup --find --show /mnt/cache/appdata/microvm/dm-meta)
SECTORS=$(blockdev --getsize "$DATA")
dmsetup create microvm-pool --table "0 $SECTORS thin-pool $META $DATA 128 32768 1 skip_block_zeroing"

# containerd config with devmapper
cat > /run/microvm-containerd/config.toml << 'EOF'
version = 2
root = "/mnt/user/appdata/microvm/containerd"
state = "/run/microvm-containerd"
[grpc]
  address = "/run/microvm-containerd/containerd.sock"
[plugins."io.containerd.snapshotter.v1.devmapper"]
  pool_name = "microvm-pool"
  root_path = "/mnt/user/appdata/microvm/containerd/snapshotter/devmapper"
  base_image_size = "10GB"
  discard_blocks = true
EOF

# Start services (same as above)
containerd --config /run/microvm-containerd/config.toml &
flintlockd run --containerd-socket=/run/microvm-containerd/containerd.sock ...
```

## Does Re-creating Thin Pool Lose Data?

**NO.** The dm-data and dm-meta files contain all the data. `losetup` + `dmsetup` just re-attach them:
- Like unplugging a USB drive and plugging it back in
- The thin pool mapping is just metadata telling the kernel how to read the files
- All OCI layers, VM snapshots, and data survive

## Flintlock Versions (tested compatible)

| Component | Version | Notes |
|-----------|---------|-------|
| Flintlock | v0.9.0 | Latest release (Jul 2026) |
| containerd | 1.7.x or 2.x | Static binary |
| Cloud Hypervisor | v52.0 | Default provider |
| Firecracker | v1.16.0 | Alternative provider |

## CAPMVM (Future — Kubernetes Nodes as MicroVMs)

```
Management K8s (kind/k3d) → CAPMVM controller → Flintlock (gRPC) → MicroVMs
```

- Each microVM = full K8s node (kubelet + kubeadm)
- Boot time: ~200ms VM + ~10-30s kubelet join
- Status: v0.10.1, alpha, community-maintained
- Practical for homelab experimentation, not production
- Better alternative: just boot CH VMs with k3s pre-installed
