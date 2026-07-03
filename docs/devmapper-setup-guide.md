# Device-Mapper Thin Provisioning for MicroVM Cloning on Unraid

## Executive Summary

Device-mapper thin provisioning enables **instant Copy-on-Write (CoW) cloning** of VM disk images.
Instead of copying a 500MB rootfs for each new VM (taking ~2-5 seconds on NVMe), a thin snapshot
takes **<50ms** and uses **zero additional space** until the VM writes data.

**Recommendation:** Start with **Option A (Direct dm-thin)** for simplicity. It gives you instant
cloning without needing containerd. Graduate to Option B (containerd devmapper snapshotter) only
if you want automated OCI image → VM conversion.

---

## Table of Contents

1. [How It Works](#how-it-works)
2. [Unraid Kernel Compatibility](#unraid-kernel-compatibility)
3. [Option A: Direct dm-thin (Recommended)](#option-a-direct-dm-thin)
4. [Option B: Containerd Devmapper Snapshotter](#option-b-containerd-devmapper-snapshotter)
5. [Option C: Raw Files (Current)](#option-c-raw-files-current)
6. [Comparison Matrix](#comparison-matrix)
7. [Risks and Mitigations](#risks-and-mitigations)
8. [Boot Script for Unraid](#boot-script-for-unraid)
9. [Testing Procedure](#testing-procedure)
10. [References](#references)

---

## How It Works

### Thin Provisioning Concepts

```
┌─────────────────────────────────────────────────┐
│              Thin Pool (microvm-pool)            │
│  ┌──────────┐  ┌──────────┐  ┌──────────┐      │
│  │ Base Vol  │  │ VM-web   │  │ VM-api   │      │
│  │ (id=0)   │  │ (id=1)   │  │ (id=2)   │      │
│  │ 500MB    │  │ snap of 0│  │ snap of 0│      │
│  │ alpine+  │  │ ~0 bytes │  │ ~0 bytes │      │
│  │ nginx    │  │ until    │  │ until    │      │
│  │          │  │ written  │  │ written  │      │
│  └──────────┘  └──────────┘  └──────────┘      │
│                                                  │
│  Data: /mnt/cache/appdata/microvm/dm-data (100G) │
│  Meta: /mnt/cache/appdata/microvm/dm-meta (2G)   │
└─────────────────────────────────────────────────┘
```

### Key Operations (from Linux kernel docs)

| Operation | Command | Time |
|-----------|---------|------|
| Create base volume | `dmsetup message pool 0 "create_thin 0"` | <10ms |
| Create snapshot | `dmsetup message pool 0 "create_snap 1 0"` | <10ms |
| Activate as block device | `dmsetup create vm-NAME --table "0 $SIZE thin /dev/mapper/pool $ID"` | <10ms |
| Delete volume | `dmsetup message pool 0 "delete $ID"` | <10ms |

### How Cloud Hypervisor Uses It

Cloud Hypervisor's `--disk path=` accepts **any block device**, including `/dev/mapper/` devices:

```bash
cloud-hypervisor \
  --disk path=/dev/mapper/vm-web \
  --kernel /mnt/user/appdata/microvm/kernels/vmlinux \
  --cmdline "console=hvc0 root=/dev/vda rw" \
  ...
```

This is confirmed by:
- CH documentation shows `--disk path=` with raw files (which are just files opened as block)
- Ignite project (Weaveworks) uses dm snapshots → Firecracker boot successfully
- Julia Evans' blog confirms device-mapper → Firecracker works
- `/dev/mapper/NAME` is a standard Linux block device, no special handling needed



---

## Unraid Kernel Compatibility

### Does Unraid have dm-thin-pool?

**Very likely YES.** Here's why:

1. **Unraid uses kernel 6.12.x** — dm-thin-pool has been in the kernel since 3.2 (2012)
2. **Unraid uses device-mapper already** — its own array management uses md/dm infrastructure
3. **The module is either built-in or loadable** — check with:

```bash
# Check if module is loaded
lsmod | grep dm_thin_pool

# Try to load it (if not already loaded)
modprobe dm_thin_pool

# Verify it loaded
lsmod | grep dm_thin_pool
# Expected output:
# dm_thin_pool    xxxxx  0
# dm_persistent_data  xxxxx  1 dm_thin_pool
# dm_bio_prison   xxxxx  1 dm_thin_pool

# Check available modules
find /lib/modules/$(uname -r) -name "*thin*"
# Expected: .../dm-thin-pool.ko or similar
```

4. **Unraid's Docker used to use devicemapper** before switching to overlay2 — so the kernel
   historically had this support. Even with overlay2, the module is still available.

### Required Userspace Tools

```bash
# Check if dmsetup is available (should be on Unraid)
which dmsetup
dmsetup version

# Check if thin_check is available (for metadata repair)
which thin_check
# If missing, need thin-provisioning-tools package
# On Unraid: available via Nerd Tools plugin or manual install
```

### Unraid's Own Device-Mapper Usage

Unraid uses **md** (multiple devices/RAID) for its array, NOT device-mapper thin provisioning.
The disk array (`/dev/md1`, `/dev/md2`, etc.) uses Linux software RAID, not dm-thin.

**No conflict:** Creating our own thin pool on `/mnt/cache` (NVMe) is entirely separate from
Unraid's array management. They use different subsystems of the kernel.



---

## Option A: Direct dm-thin (Recommended)

Use device-mapper thin provisioning directly (without containerd) for instant VM cloning.
This is the **simplest path** to CoW disk images.

### One-Time Setup (creates persistent data files)

```bash
#!/bin/bash
# /mnt/cache/appdata/microvm/setup-thinpool.sh
# Run once to create the thin pool backing files
set -e

POOL_DIR="/mnt/cache/appdata/microvm"
DATA_FILE="${POOL_DIR}/dm-data"
META_FILE="${POOL_DIR}/dm-meta"
POOL_NAME="microvm-pool"

mkdir -p "$POOL_DIR"

# Create sparse files (don't actually allocate 100GB)
# Data: 100GB virtual, only uses space as VMs write
# Meta: 2GB (enough for thousands of thin volumes)
if [ ! -f "$DATA_FILE" ]; then
    truncate -s 100G "$DATA_FILE"
    echo "Created $DATA_FILE (100G sparse)"
fi

if [ ! -f "$META_FILE" ]; then
    truncate -s 2G "$META_FILE"
    echo "Created $META_FILE (2G sparse)"
fi

echo "Thin pool files ready. Run attach-thinpool.sh to activate."
```

### Boot-Time Attach (run every boot)

```bash
#!/bin/bash
# /mnt/cache/appdata/microvm/attach-thinpool.sh
# Run on every boot to activate the thin pool
set -e

POOL_DIR="/mnt/cache/appdata/microvm"
DATA_FILE="${POOL_DIR}/dm-data"
META_FILE="${POOL_DIR}/dm-meta"
POOL_NAME="microvm-pool"

# Check if pool already exists
if dmsetup info "$POOL_NAME" &>/dev/null; then
    echo "Pool $POOL_NAME already active"
    exit 0
fi

# Load kernel module if needed
modprobe dm_thin_pool 2>/dev/null || true

# Attach loop devices
DATA_DEV=$(losetup --find --show "$DATA_FILE")
META_DEV=$(losetup --find --show "$META_FILE")
echo "Loop devices: data=$DATA_DEV meta=$META_DEV"

# Calculate pool size in sectors (512-byte sectors)
DATA_SIZE=$(blockdev --getsize64 "$DATA_DEV")
SECTORS=$((DATA_SIZE / 512))

# Create thin-pool device
# Parameters:
#   128 = data block size (128 sectors = 64KB, good for snapshots)
#   32768 = low water mark (triggers event when free blocks < this)
#   1 skip_block_zeroing = don't zero new blocks (faster)
dmsetup create "$POOL_NAME" \
    --table "0 $SECTORS thin-pool $META_DEV $DATA_DEV 128 32768 1 skip_block_zeroing"

echo "Thin pool '$POOL_NAME' activated: $SECTORS sectors"
dmsetup status "$POOL_NAME"
```

### Create a Base Volume (from existing rootfs.raw)

```bash
#!/bin/bash
# create-base-volume.sh <name> <rootfs.raw path>
# Creates a thin volume and writes a rootfs image into it
set -e

NAME="${1:?Usage: $0 <name> <rootfs-file>}"
ROOTFS="${2:?Usage: $0 <name> <rootfs-file>}"
POOL_NAME="microvm-pool"
POOL_DEV="/dev/mapper/$POOL_NAME"

# Volume IDs: use a simple counter file
ID_FILE="/mnt/cache/appdata/microvm/next-vol-id"
VOL_ID=$(cat "$ID_FILE" 2>/dev/null || echo "0")
echo $((VOL_ID + 1)) > "$ID_FILE"

# Get rootfs size in sectors
ROOTFS_SIZE=$(stat --format=%s "$ROOTFS")
SECTORS=$((ROOTFS_SIZE / 512))
# Round up to at least cover the file
if [ $((ROOTFS_SIZE % 512)) -ne 0 ]; then
    SECTORS=$((SECTORS + 1))
fi

# Create thin volume in pool
dmsetup message "$POOL_DEV" 0 "create_thin $VOL_ID"

# Activate thin volume as block device
dmsetup create "vol-${NAME}" \
    --table "0 $SECTORS thin $POOL_DEV $VOL_ID"

# Write rootfs data into the thin volume
echo "Writing $ROOTFS ($ROOTFS_SIZE bytes) to /dev/mapper/vol-${NAME}..."
dd if="$ROOTFS" of="/dev/mapper/vol-${NAME}" bs=4M status=progress

# Record mapping
echo "${VOL_ID} ${NAME} ${SECTORS} base" >> /mnt/cache/appdata/microvm/vol-registry.txt
echo "Base volume 'vol-${NAME}' created (id=$VOL_ID, ${SECTORS} sectors)"
```

### Create a Snapshot (instant clone for a new VM)

```bash
#!/bin/bash
# clone-vm.sh <base-name> <new-vm-name>
# Creates an instant CoW snapshot of a base volume
set -e

BASE_NAME="${1:?Usage: $0 <base-name> <new-vm-name>}"
VM_NAME="${2:?Usage: $0 <base-name> <new-vm-name>}"
POOL_NAME="microvm-pool"
POOL_DEV="/dev/mapper/$POOL_NAME"

# Look up base volume ID and size
BASE_LINE=$(grep " ${BASE_NAME} " /mnt/cache/appdata/microvm/vol-registry.txt)
BASE_ID=$(echo "$BASE_LINE" | awk '{print $1}')
BASE_SECTORS=$(echo "$BASE_LINE" | awk '{print $3}')

if [ -z "$BASE_ID" ]; then
    echo "ERROR: Base volume '${BASE_NAME}' not found in registry"
    exit 1
fi

# Allocate new volume ID
ID_FILE="/mnt/cache/appdata/microvm/next-vol-id"
VOL_ID=$(cat "$ID_FILE")
echo $((VOL_ID + 1)) > "$ID_FILE"

# IMPORTANT: Suspend base device before snapshotting (if active)
if dmsetup info "vol-${BASE_NAME}" &>/dev/null; then
    dmsetup suspend "vol-${BASE_NAME}"
fi

# Create snapshot (instant, <50ms)
dmsetup message "$POOL_DEV" 0 "create_snap $VOL_ID $BASE_ID"

# Resume base device
if dmsetup info "vol-${BASE_NAME}" &>/dev/null; then
    dmsetup resume "vol-${BASE_NAME}"
fi

# Activate snapshot as block device
dmsetup create "vm-${VM_NAME}" \
    --table "0 $BASE_SECTORS thin $POOL_DEV $VOL_ID"

# Record mapping
echo "${VOL_ID} ${VM_NAME} ${BASE_SECTORS} snap:${BASE_NAME}" >> /mnt/cache/appdata/microvm/vol-registry.txt
echo "VM 'vm-${VM_NAME}' created (snapshot of ${BASE_NAME}, id=$VOL_ID)"
echo "Block device: /dev/mapper/vm-${VM_NAME}"
```

### Start a VM from Snapshot

```bash
#!/bin/bash
# start-vm.sh <vm-name>
set -e

VM_NAME="${1:?Usage: $0 <vm-name>}"
DISK="/dev/mapper/vm-${VM_NAME}"

if [ ! -b "$DISK" ]; then
    echo "ERROR: Block device $DISK not found. Did you run clone-vm.sh?"
    exit 1
fi

# Create TAP device
ip tuntap add "tap-${VM_NAME}" mode tap 2>/dev/null || true
ip link set "tap-${VM_NAME}" master br0
ip link set "tap-${VM_NAME}" up

# Start Cloud Hypervisor with the thin snapshot as disk
cloud-hypervisor \
    --api-socket "/run/microvm/${VM_NAME}.sock" \
    --kernel /mnt/user/appdata/microvm/kernels/vmlinux \
    --disk "path=${DISK}" \
    --cmdline "console=hvc0 root=/dev/vda rw init=/init" \
    --cpus boot=1,max=4 \
    --memory size=256M,hotplug_size=768M \
    --net "tap=tap-${VM_NAME},mac=52:54:00:aa:bb:$(printf '%02x' $((RANDOM % 256)))" \
    --serial off --console off -v &

echo "VM ${VM_NAME} started (PID=$!, disk=${DISK})"
```

### Delete a VM Snapshot

```bash
#!/bin/bash
# delete-vm.sh <vm-name>
set -e

VM_NAME="${1:?Usage: $0 <vm-name>}"
POOL_DEV="/dev/mapper/microvm-pool"

# Stop VM if running
if [ -S "/run/microvm/${VM_NAME}.sock" ]; then
    ch-remote --api-socket "/run/microvm/${VM_NAME}.sock" shutdown-vmm 2>/dev/null || true
    sleep 1
fi

# Look up volume ID
VOL_LINE=$(grep " ${VM_NAME} " /mnt/cache/appdata/microvm/vol-registry.txt)
VOL_ID=$(echo "$VOL_LINE" | awk '{print $1}')

# Deactivate block device
dmsetup remove "vm-${VM_NAME}" 2>/dev/null || true

# Delete thin volume from pool (frees space)
if [ -n "$VOL_ID" ]; then
    dmsetup message "$POOL_DEV" 0 "delete $VOL_ID"
fi

# Remove TAP
ip link del "tap-${VM_NAME}" 2>/dev/null || true

# Remove from registry
sed -i "/ ${VM_NAME} /d" /mnt/cache/appdata/microvm/vol-registry.txt

echo "VM ${VM_NAME} deleted (volume id=$VOL_ID freed)"
```



---

## Option B: Containerd Devmapper Snapshotter

Use containerd's built-in devmapper snapshotter plugin to automatically manage thin volumes
from OCI images. This is what Flintlock uses internally.

### How It Works (internals)

```
1. ctr images pull docker.io/library/nginx:alpine
   └── containerd downloads OCI layers → content store

2. containerd devmapper snapshotter:
   └── For each layer: create_thin → write layer content → create_snap (parent chain)
   └── Final "active" snapshot = merged layers ready to use

3. Flintlock mounts the snapshot → Firecracker/CH boots from /dev/mapper/thin-XXX
```

Each container/VM gets its own thin snapshot. All VMs sharing the same base image
share the same base layer thin volumes (deduplication at the block level).

### Configuration

```toml
# /run/microvm-containerd/config.toml
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
  fs_type = "ext4"
  async_remove = false
```

### Configuration Parameters Explained

| Parameter | Value | Meaning |
|-----------|-------|---------|
| `pool_name` | `microvm-pool` | Must match the `dmsetup create` name |
| `root_path` | (path) | Where containerd stores snapshotter metadata |
| `base_image_size` | `10GB` | Max size per thin device (virtual, not allocated) |
| `discard_blocks` | `true` | Important for loopback — returns space to sparse files |
| `fs_type` | `ext4` | Filesystem created inside thin volumes |
| `async_remove` | `false` | Sync removal is safer for homelab |

### Usage with Containerd

```bash
# Pull image using devmapper snapshotter
ctr --address /run/microvm-containerd/containerd.sock \
    images pull --snapshotter devmapper docker.io/library/alpine:latest

# Create a container (this creates a thin snapshot)
ctr --address /run/microvm-containerd/containerd.sock \
    containers create --snapshotter devmapper \
    docker.io/library/alpine:latest test-container

# List snapshots (these are thin volumes)
ctr --address /run/microvm-containerd/containerd.sock \
    snapshots --snapshotter devmapper ls
```

### Pros Over Direct dm-thin

- Automatic OCI image layer management
- Proper garbage collection of unused layers
- Content-addressable storage (no duplicates)
- Flintlock integration out of the box
- Industry-standard approach (used by Kata Containers, Firecracker at AWS)

### Cons

- Requires containerd binary (~45MB) + configuration
- More complex to debug
- Overkill if you only have 2-5 VM templates
- Containerd manages volume IDs internally (less visibility)



---

## Option C: Raw Files (Current)

The current approach: each VM has its own `rootfs.raw` file.

```bash
# Current workflow:
cp /mnt/user/appdata/microvm/templates/alpine-nginx.raw \
   /mnt/user/appdata/microvm/vm-web/rootfs.raw

cloud-hypervisor --disk path=/mnt/user/appdata/microvm/vm-web/rootfs.raw ...
```

### Pros

- **Simplest possible approach** — just files on disk
- No kernel modules, no dmsetup, no losetup
- Easy to backup (just copy the file)
- Easy to inspect (mount with `mount -o loop`)
- Works on any Linux system
- No risk of thin pool corruption
- Each VM is fully independent (no shared state)

### Cons

- **Clone time: 2-5 seconds** per VM (500MB copy on NVMe)
- **Disk usage: 500MB per VM** even if only 1KB differs from base
- No deduplication between VMs sharing same base image
- 10 VMs from same template = 5GB (vs ~500MB with thin provisioning)

---

## Comparison Matrix

| Feature | Option A: Direct dm-thin | Option B: Containerd dm | Option C: Raw Files |
|---------|--------------------------|-------------------------|---------------------|
| **Clone time** | <50ms | <50ms | 2-5 seconds |
| **Disk per clone** | ~0 (until writes) | ~0 (until writes) | Full copy (500MB) |
| **Complexity** | Medium | High | Low |
| **Dependencies** | dm_thin_pool module, dmsetup | + containerd, thin pool | None |
| **Survives reboot** | Yes (re-attach script) | Yes (re-attach script) | Yes (just files) |
| **Max VMs from 1 base** | Thousands | Thousands | Limited by disk |
| **Debugging** | dmsetup status/ls | ctr snapshots ls + dmsetup | ls -la |
| **Backup** | Complex (pool export) | Complex (ctr export) | Simple (cp) |
| **Risk if pool corrupts** | All VMs affected | All VMs affected | Only that VM |
| **Unraid integration** | Moderate effort | High effort | Already works |
| **Good for** | 5+ VMs from same base | OCI workflow, Flintlock | 1-5 VMs, simplicity |

### Decision Guide

- **1-3 VMs, different bases:** Stay with Option C (raw files)
- **5+ VMs from same base:** Use Option A (direct dm-thin)
- **Want OCI images → VMs automatically:** Use Option B (containerd)
- **Building a Flintlock-based platform:** Use Option B (required)

---

## Risks and Mitigations

### 1. Thin Pool Fills Up

**What happens:**
- When data space is exhausted, the pool switches to "out-of-data-space" mode
- Default behavior: IO is **queued** (not errored) for 60 seconds
- After timeout (or if `error_if_no_space` flag): IO returns errors to VMs
- VMs will see I/O errors, filesystems may go read-only
- The pool itself and metadata remain intact

**Mitigation:**
```bash
# Monitor pool usage (add to cron every 5 minutes)
dmsetup status microvm-pool
# Output: "0 209715200 thin-pool 0 35/524288 0/1638400 - rw discard_passdown ..."
#                                    ^^meta^^   ^^data^^

# Parse data usage percentage
STATUS=$(dmsetup status microvm-pool)
DATA_USED=$(echo "$STATUS" | awk '{split($7,a,"/"); print a[1]}')
DATA_TOTAL=$(echo "$STATUS" | awk '{split($7,a,"/"); print a[2]}')
PERCENT=$((DATA_USED * 100 / DATA_TOTAL))

if [ $PERCENT -gt 80 ]; then
    # Alert! Consider:
    # 1. Delete unused VM snapshots
    # 2. Grow the data file (truncate -s 200G) and reload pool
    logger -t microvm "WARNING: Thin pool ${PERCENT}% full!"
fi
```

**Recovery if pool fills:**
```bash
# 1. Stop all VMs
# 2. Grow the sparse data file
truncate -s 200G /mnt/cache/appdata/microvm/dm-data
# 3. Detach and re-attach with new size
dmsetup remove microvm-pool  # (after stopping all VMs)
# 4. Re-run attach-thinpool.sh (picks up new size)
```

### 2. Data Safety on Unraid Reboot

**What happens on clean shutdown:**
- VMs get ACPI shutdown signal → flush writes → exit
- `dmsetup remove` tears down pool cleanly
- Loop devices detach
- Data files on /mnt/cache remain intact

**What happens on hard power loss:**
- Thin pool metadata has a transaction journal
- On next attach, kernel replays journal (like ext4 journal replay)
- Some recent writes (last ~1 second) may be lost
- Pool should come up cleanly; if not: `thin_check` and `thin_repair`

**Mitigation:**
```bash
# In shutdown script, before unmounting cache:
# 1. Stop all VMs
for sock in /run/microvm/*.sock; do
    ch-remote --api-socket "$sock" shutdown-vmm 2>/dev/null
done
sleep 2
pkill -f cloud-hypervisor

# 2. Deactivate all thin volumes
dmsetup ls --target thin | awk '{print $1}' | while read dev; do
    dmsetup remove "$dev"
done

# 3. Deactivate pool
dmsetup remove microvm-pool

# 4. Detach loop devices
losetup -D  # or specifically detach our loops
```

### 3. Interaction with Unraid's Own Device-Mapper

**Risk: LOW**

Unraid's disk array uses **md** (Linux software RAID), not device-mapper thin provisioning.
The two subsystems are completely independent:

- Unraid array: `/dev/md1`, `/dev/md2`, etc. (md driver)
- Our thin pool: `/dev/mapper/microvm-pool`, `/dev/mapper/vm-*` (dm-thin driver)
- Docker on Unraid: uses overlay2 on `/mnt/cache/docker/` (no dm involvement)

**Potential issue:** If Unraid's libvirt/QEMU also uses device-mapper for its VMs (unlikely
in default config), namespace collision could occur. Mitigate by using a unique prefix (`microvm-`).

### 4. Loop Device Exhaustion

**Risk: LOW**

Default Linux limit is 256 loop devices. We only use 2 (data + meta).
If somehow you hit the limit: `echo 512 > /sys/module/loop/parameters/max_loop`

### 5. NVMe Wear from Thin Pool

**Risk: NEGLIGIBLE**

- Sparse files mean we only write actual data (same as raw files)
- 64KB block size means small writes don't amplify significantly
- `discard_blocks` returns freed space to the sparse file
- NVMe endurance (typically 600+ TBW) far exceeds homelab usage



---

## Boot Script for Unraid

### rc.d Integration

Create `/etc/rc.d/rc.microvm-pool` to manage the thin pool lifecycle:

```bash
#!/bin/bash
# /etc/rc.d/rc.microvm-pool
# Manages device-mapper thin pool for microVM cloning
# Called by rc.microvm during array start/stop

POOL_DIR="/mnt/cache/appdata/microvm"
DATA_FILE="${POOL_DIR}/dm-data"
META_FILE="${POOL_DIR}/dm-meta"
POOL_NAME="microvm-pool"

case "$1" in
    start)
        # Idempotent: skip if already active
        if dmsetup info "$POOL_NAME" &>/dev/null; then
            echo "Thin pool already active"
            exit 0
        fi

        # Verify files exist
        if [ ! -f "$DATA_FILE" ] || [ ! -f "$META_FILE" ]; then
            echo "Thin pool files not found. Run setup first."
            exit 1
        fi

        # Load module
        modprobe dm_thin_pool 2>/dev/null || true

        # Attach loop devices
        DATA_DEV=$(losetup --find --show "$DATA_FILE")
        META_DEV=$(losetup --find --show "$META_FILE")

        # Record loop device paths for clean shutdown
        echo "$DATA_DEV" > "${POOL_DIR}/loop-data.dev"
        echo "$META_DEV" > "${POOL_DIR}/loop-meta.dev"

        # Calculate size
        DATA_SIZE=$(blockdev --getsize64 "$DATA_DEV")
        SECTORS=$((DATA_SIZE / 512))

        # Create pool
        dmsetup create "$POOL_NAME" \
            --table "0 $SECTORS thin-pool $META_DEV $DATA_DEV 128 32768 1 skip_block_zeroing"

        echo "Thin pool '$POOL_NAME' started ($((DATA_SIZE / 1073741824))GB)"
        ;;

    stop)
        # Deactivate all thin volumes first
        dmsetup ls --target thin 2>/dev/null | awk '{print $1}' | while read dev; do
            dmsetup remove "$dev" 2>/dev/null
        done

        # Deactivate pool
        dmsetup remove "$POOL_NAME" 2>/dev/null

        # Detach loop devices
        if [ -f "${POOL_DIR}/loop-data.dev" ]; then
            losetup -d "$(cat ${POOL_DIR}/loop-data.dev)" 2>/dev/null
            losetup -d "$(cat ${POOL_DIR}/loop-meta.dev)" 2>/dev/null
            rm -f "${POOL_DIR}/loop-data.dev" "${POOL_DIR}/loop-meta.dev"
        fi

        echo "Thin pool '$POOL_NAME' stopped"
        ;;

    status)
        if dmsetup info "$POOL_NAME" &>/dev/null; then
            echo "ACTIVE"
            dmsetup status "$POOL_NAME"
            echo ""
            echo "Thin volumes:"
            dmsetup ls --target thin 2>/dev/null
        else
            echo "INACTIVE"
        fi
        ;;

    reactivate-vms)
        # After pool start, re-activate existing VM thin volumes
        if [ -f "${POOL_DIR}/vol-registry.txt" ]; then
            while read VOL_ID NAME SECTORS TYPE; do
                case "$TYPE" in
                    snap:*)
                        DM_NAME="vm-${NAME}"
                        ;;
                    base)
                        DM_NAME="vol-${NAME}"
                        ;;
                esac
                if ! dmsetup info "$DM_NAME" &>/dev/null; then
                    dmsetup create "$DM_NAME" \
                        --table "0 $SECTORS thin /dev/mapper/$POOL_NAME $VOL_ID"
                fi
            done < "${POOL_DIR}/vol-registry.txt"
            echo "Re-activated thin volumes"
        fi
        ;;

    *)
        echo "Usage: $0 {start|stop|status|reactivate-vms}"
        exit 1
        ;;
esac
```

### Integration with Existing rc.microvm

Add to the existing `/etc/rc.d/rc.microvm`:

```bash
# In start_all():
/etc/rc.d/rc.microvm-pool start
/etc/rc.d/rc.microvm-pool reactivate-vms

# In stop_all():
# (stop VMs first, then pool)
/etc/rc.d/rc.microvm-pool stop
```

---

## Testing Procedure

### Step 1: Verify Kernel Support

```bash
# On Unraid console:
modprobe dm_thin_pool
lsmod | grep dm_thin
# Must see dm_thin_pool in output
```

### Step 2: Create and Attach Pool

```bash
# Create files
truncate -s 100G /mnt/cache/appdata/microvm/dm-data
truncate -s 2G /mnt/cache/appdata/microvm/dm-meta

# Attach
DATA=$(losetup --find --show /mnt/cache/appdata/microvm/dm-data)
META=$(losetup --find --show /mnt/cache/appdata/microvm/dm-meta)
SECTORS=$(blockdev --getsize64 "$DATA")
SECTORS=$((SECTORS / 512))

dmsetup create microvm-pool \
    --table "0 $SECTORS thin-pool $META $DATA 128 32768 1 skip_block_zeroing"

# Verify
dmsetup status microvm-pool
# Should show: 0 209715200 thin-pool 0 0/16384 0/1638400 - rw ...
```

### Step 3: Create Base Volume from Existing rootfs

```bash
# Use an existing working rootfs.raw
ROOTFS="/mnt/user/appdata/microvm/vm0/rootfs.raw"
SIZE=$(stat --format=%s "$ROOTFS")
SECTORS=$((SIZE / 512))

# Create thin volume
dmsetup message /dev/mapper/microvm-pool 0 "create_thin 0"
dmsetup create vol-alpine-nginx --table "0 $SECTORS thin /dev/mapper/microvm-pool 0"

# Write rootfs into it
dd if="$ROOTFS" of=/dev/mapper/vol-alpine-nginx bs=4M status=progress

# Verify
file /dev/mapper/vol-alpine-nginx  # Should show: block special
```

### Step 4: Create Snapshot and Boot VM

```bash
# Suspend base, create snapshot
dmsetup suspend vol-alpine-nginx
dmsetup message /dev/mapper/microvm-pool 0 "create_snap 1 0"
dmsetup resume vol-alpine-nginx

# Activate snapshot
dmsetup create vm-test1 --table "0 $SECTORS thin /dev/mapper/microvm-pool 1"

# Boot from snapshot!
ip tuntap add tap-test1 mode tap
ip link set tap-test1 master br0
ip link set tap-test1 up

cloud-hypervisor \
    --api-socket /tmp/test1.sock \
    --kernel /mnt/user/appdata/microvm/kernels/vmlinux \
    --disk path=/dev/mapper/vm-test1 \
    --cmdline "console=hvc0 root=/dev/vda rw init=/init ip=192.168.50.201::192.168.50.1:255.255.255.0:::off" \
    --cpus boot=1 \
    --memory size=256M \
    --net tap=tap-test1,mac=52:54:00:cc:dd:01 \
    --serial off --console off -v &

sleep 3
curl http://192.168.50.201/
# Should show nginx page!
```

### Step 5: Create Second Clone (proves CoW works)

```bash
# Another snapshot of the same base
dmsetup suspend vol-alpine-nginx
dmsetup message /dev/mapper/microvm-pool 0 "create_snap 2 0"
dmsetup resume vol-alpine-nginx

dmsetup create vm-test2 --table "0 $SECTORS thin /dev/mapper/microvm-pool 2"

# Boot second VM (different IP, different MAC)
ip tuntap add tap-test2 mode tap
ip link set tap-test2 master br0
ip link set tap-test2 up

cloud-hypervisor \
    --api-socket /tmp/test2.sock \
    --kernel /mnt/user/appdata/microvm/kernels/vmlinux \
    --disk path=/dev/mapper/vm-test2 \
    --cmdline "console=hvc0 root=/dev/vda rw init=/init ip=192.168.50.202::192.168.50.1:255.255.255.0:::off" \
    --cpus boot=1 \
    --memory size=256M \
    --net tap=tap-test2,mac=52:54:00:cc:dd:02 \
    --serial off --console off -v &

sleep 3
curl http://192.168.50.202/
# Both VMs running from same 500MB base, total extra disk: ~0!
```

### Step 6: Check Disk Usage

```bash
# Check actual disk usage of sparse files
du -h /mnt/cache/appdata/microvm/dm-data
# Should be ~500MB (just the base image), not 100GB

# Check pool status
dmsetup status microvm-pool
# Shows used data blocks vs total
```

### Step 7: Cleanup

```bash
# Stop VMs
ch-remote --api-socket /tmp/test1.sock shutdown-vmm
ch-remote --api-socket /tmp/test2.sock shutdown-vmm
sleep 2

# Remove thin volumes
dmsetup remove vm-test1
dmsetup remove vm-test2
dmsetup remove vol-alpine-nginx

# Delete from pool
dmsetup message /dev/mapper/microvm-pool 0 "delete 2"
dmsetup message /dev/mapper/microvm-pool 0 "delete 1"
dmsetup message /dev/mapper/microvm-pool 0 "delete 0"

# Remove pool
dmsetup remove microvm-pool

# Detach loops
losetup -d $DATA $META

# Remove TAPs
ip link del tap-test1
ip link del tap-test2
```

---

## References

| Source | URL | Key Info |
|--------|-----|----------|
| Kernel dm-thin docs | https://docs.kernel.org/admin-guide/device-mapper/thin-provisioning.html | Authoritative API reference |
| containerd devmapper | https://containerd.io/docs/2.1/snapshotters/devmapper/ | Loopback setup script |
| Julia Evans blog | https://jvns.ca/blog/2021/01/27/day-47--using-device-mapper-to-manage-firecracker-images/ | Practical dm + Firecracker |
| Ignite (Weaveworks) | https://github.com/weaveworks/ignite | Production dm-thin for Firecracker VMs |
| Flintlock | https://github.com/weaveworks-liquidmetal/flintlock | MicroVM lifecycle with containerd dm |
| Cloud Hypervisor | https://www.cloudhypervisor.org/docs/prologue/quick-start/ | --disk path= accepts block devices |
| Thin pool full (Red Hat) | https://access.redhat.com/solutions/2136901 | What happens when pool fills |

---

## Appendix: External Snapshots (Alternative Approach)

The kernel also supports **external snapshots** — where a read-only device outside the pool
serves as the origin. This could allow keeping raw files as-is and overlaying changes:

```bash
# Create a thin device that uses an external origin
dmsetup message /dev/mapper/microvm-pool 0 "create_thin 10"

# Activate with external origin (the existing rootfs.raw via losetup)
ORIGIN=$(losetup --find --show --read-only /mnt/user/appdata/microvm/templates/nginx.raw)
dmsetup create vm-nginx-1 \
    --table "0 $SECTORS thin /dev/mapper/microvm-pool 10 $ORIGIN"
```

This avoids copying the base image into the pool at all. Reads go to the origin file,
writes go to the thin pool. **Caveat:** The origin must remain read-only and available.

---

*Last updated: 2026-07-04*
*Status: Research complete, ready for testing on Unraid*
