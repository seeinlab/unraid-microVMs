# Flintlock + Device-Mapper Research for Unraid

## Date: 2026-07-03
## Status: Research Complete, Ready for Testing

---

## 1. Flintlock Status

### Latest Release
- **v0.9.1** (Nov 2024, pre-release) — bug fix for event service logging
- **v0.9.0** (Nov 2024, latest stable) — ssh import ID for cloud-init
- Still maintained by richardcase (liquidmetal-dev org)
- 1.4k GitHub stars
- Supports both Cloud Hypervisor and Firecracker

### What Flintlock Provides (over our direct management)
- gRPC API for VM lifecycle (create/start/stop/delete)
- OCI image → rootfs conversion via containerd
- Device-mapper thin provisioning (instant CoW cloning)
- Cloud-init metadata injection
- Network interface management
- Multi-host fleet management (via CAPMVM for Kubernetes)

### Dependencies
- **containerd** 1.7.x+ (separate from Docker's containerd)
- **device-mapper** thin-pool kernel module (for advanced mode)
- **Cloud Hypervisor** or **Firecracker** (already installed)

### Binary Downloads
- flintlockd: `https://github.com/liquidmetal-dev/flintlock/releases/download/v0.9.0/flintlockd`
- fl (CLI): `https://github.com/liquidmetal-dev/fl/releases`
- containerd: `https://github.com/containerd/containerd/releases` (static tarball)

---

## 2. Device-Mapper Thin Provisioning on Unraid

### Kernel Support: ✅ CONFIRMED
```
# Module exists at:
/lib/modules/6.12.90-Unraid/kernel/drivers/md/dm-thin-pool.ko.xz

# Dependencies:
dm-bio-prison, dm-persistent-data, dm-mod
```

### How It Works
```
┌─────────────────────────────────────────┐
│           Thin Pool (dm-thin-pool)      │
│  ┌─────────────────────────────────┐    │
│  │ Metadata Device (2GB loopback)  │    │
│  └─────────────────────────────────┘    │
│  ┌─────────────────────────────────┐    │
│  │ Data Device (100GB loopback)    │    │
│  └─────────────────────────────────┘    │
│                                         │
│  Thin Volumes (CoW snapshots):          │
│  ├── base-nginx (500MB logical)         │
│  ├── vm-nginx-1 (snap of base) → 0B    │
│  ├── vm-nginx-2 (snap of base) → 0B    │
│  └── vm-nginx-3 (snap of base) → 0B    │
└─────────────────────────────────────────┘
```

### Setup Script for Unraid
```bash
#!/bin/bash
# /usr/local/bin/microvm-thinpool-setup

POOL_DIR="/mnt/cache/appdata/microvm"
POOL_NAME="microvm-pool"
DATA_SIZE="50G"  # Sparse file, actual usage = data written
META_SIZE="500M"

# Create pool files (sparse, only once)
mkdir -p "$POOL_DIR"
[ -f "$POOL_DIR/dm-data" ] || truncate -s $DATA_SIZE "$POOL_DIR/dm-data"
[ -f "$POOL_DIR/dm-meta" ] || truncate -s $META_SIZE "$POOL_DIR/dm-meta"

# Load kernel module
modprobe dm_thin_pool

# Attach loopback devices
DATA_DEV=$(losetup --find --show "$POOL_DIR/dm-data")
META_DEV=$(losetup --find --show "$POOL_DIR/dm-meta")
DATA_SECTORS=$(blockdev --getsize "$DATA_DEV")

# Create thin pool
dmsetup create "$POOL_NAME" \
  --table "0 $DATA_SECTORS thin-pool $META_DEV $DATA_DEV 128 32768 1 skip_block_zeroing"

echo "Thin pool ready: /dev/mapper/$POOL_NAME"
echo "Data: $DATA_DEV ($DATA_SIZE sparse)"
echo "Meta: $META_DEV ($META_SIZE sparse)"
```

### Creating Base Volume + Snapshots
```bash
# Create thin volume ID 0 (base)
dmsetup message microvm-pool 0 "create_thin 0"
# Activate it (1000000 sectors = ~500MB)
dmsetup create microvm-base --table "0 1000000 thin /dev/mapper/microvm-pool 0"
# Write base rootfs
dd if=rootfs.raw of=/dev/mapper/microvm-base bs=4M

# Create snapshot (thin volume ID 1) from base (ID 0)
dmsetup message microvm-pool 0 "create_snap 1 0"
dmsetup create microvm-vm1 --table "0 1000000 thin /dev/mapper/microvm-pool 1"
# /dev/mapper/microvm-vm1 is ready — instant, 0 bytes used until write

# Boot VM from thin snapshot
cloud-hypervisor --disk path=/dev/mapper/microvm-vm1 ...
```

### Reboot Persistence
- `dm-data` and `dm-meta` files persist on /mnt/cache (survives reboot)
- Loopback + dmsetup must be re-run on each boot (in rc.microvm start)
- All data survives — like unplugging/replugging a drive

---

## 3. Containerd for MicroVMs (Separate from Docker)

### Why Separate Containerd?
- Docker's containerd uses overlay2 snapshotter
- MicroVMs need devmapper snapshotter (block devices, not overlayfs)
- Different socket, different state directory
- No conflict with Docker

### Configuration
```toml
# /run/microvm-containerd/config.toml
version = 2
root = "/mnt/cache/appdata/microvm/containerd"
state = "/run/microvm-containerd"

[grpc]
  address = "/run/microvm-containerd/containerd.sock"

[plugins."io.containerd.snapshotter.v1.devmapper"]
  pool_name = "microvm-pool"
  root_path = "/mnt/cache/appdata/microvm/containerd/snapshotter/devmapper"
  base_image_size = "10GB"
  discard_blocks = true
```

### Start Command
```bash
containerd --config /run/microvm-containerd/config.toml &
```

---

## 4. Flintlock Configuration for Unraid

```yaml
# /etc/flintlock/config.yaml (or CLI flags)
containerd-socket: /run/microvm-containerd/containerd.sock
grpc-endpoint: 0.0.0.0:9090
parent-iface: br0
bridge-name: br0
default-provider: cloudhypervisor  # or firecracker
insecure: true  # no TLS for local use
```

### Start Command
```bash
flintlockd run \
  --containerd-socket=/run/microvm-containerd/containerd.sock \
  --grpc-endpoint=0.0.0.0:9090 \
  --parent-iface=br0 \
  --default-provider=cloudhypervisor \
  --insecure &
```

---

## 5. Integration Plan

### Phase 1: Device-Mapper Only (No flintlockd)
- Add thin pool setup to rc.microvm
- Create base rootfs as thin volume
- Each new VM = instant snapshot
- Plugin manages via dmsetup commands
- **Benefit**: Instant cloning, saves disk space
- **Effort**: Medium (modify rc.microvm + backend)

### Phase 2: Add Containerd
- Install separate containerd for microVM use
- Configure devmapper snapshotter
- Use `ctr` to pull OCI images directly to thin volumes
- **Benefit**: OCI images stored efficiently, shared layers
- **Effort**: Medium (new binary + config)

### Phase 3: Add Flintlockd
- Full gRPC API for VM management
- Cloud-init support
- Potential Kubernetes integration (CAPMVM)
- **Benefit**: Production-grade orchestration
- **Effort**: High (new service + API migration)

---

## 6. Recommendation

**Start with Phase 1** (Device-Mapper only):
- Low risk, high value
- Kernel module confirmed available
- No new daemons needed
- Can be tested immediately
- Falls back gracefully to raw files if pool fails

**Phase 2+3 later** when:
- Multiple users need fleet management
- Kubernetes node provisioning needed
- OCI layer sharing becomes important

---

## 7. Risks

| Risk | Mitigation |
|------|-----------|
| Thin pool fills up | Monitor with `dmsetup status`; set alerts at 80% |
| Loopback performance | Use NVMe cache (/mnt/cache) — fast enough |
| Module not loaded on boot | Add `modprobe dm_thin_pool` to rc.microvm |
| Conflict with Docker's dm | Docker uses overlay2 on Unraid — no conflict |
| Data loss on crash | dm-thin has journal; sparse files on cache drive |
| Complexity for users | Keep as "Advanced" option, default = raw files |
