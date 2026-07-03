# Flintlock + Containerd Installation Guide for Unraid

> **Research Date:** 2026-07-04
> **Target:** Unraid 6.12.90 (kernel 6.12.90-Unraid)
> **Status:** Reference guide — flintlock is community-maintained, low activity

---

## Executive Summary

| Component | Version | Binary | Size |
|-----------|---------|--------|------|
| flintlockd | v0.9.1 (pre-release) / v0.9.0 (latest stable) | `flintlockd_amd64` | ~30MB |
| containerd | v1.7.33 (LTS) or v2.0.10+ | `containerd-static-*-linux-amd64.tar.gz` | ~45MB |
| hammertime | latest | CLI tool for testing | ~10MB |

### Key Findings

1. **Flintlock is community-maintained** — Originally by Weaveworks (RIP), now under `liquidmetal-dev` org. Last release v0.9.1 was Nov 2025. Dependabot keeps dependencies current. Low commit activity but NOT archived.

2. **Flintlock REQUIRES containerd** — It is fundamentally "backed by containerd" for:
   - Pulling OCI images (kernel, rootfs)
   - Snapshotting filesystem layers
   - Storing microVM metadata
   - There is **NO mode** to run flintlockd without containerd.

3. **Devmapper snapshotter is required for Firecracker** — FC needs block devices. For **Cloud Hypervisor with virtiofs** (v0.8.0+), overlayfs may work since CH can mount directories directly.

4. **firecracker-containerd is NOT needed** — That's a separate AWS project. Flintlock uses standard containerd with devmapper snapshotter directly.

5. **Binary naming**: `flintlockd_amd64` (no `v` prefix, bare binary)

---

## Architecture on Unraid

```
Unraid Host
├── /usr/local/bin/
│   ├── cloud-hypervisor          (already installed, v52.0)
│   ├── ch-remote                 (already installed)
│   ├── flintlockd                (new - gRPC microVM manager)
│   └── containerd                (new - separate from Docker's)
├── /etc/containerd/
│   └── config-microvm.toml       (microVM-dedicated config)
├── /var/lib/containerd-microvm/  (persistent state on cache)
│   └── snapshotter/devmapper/    (thinpool backing)
├── /run/containerd-microvm/      (runtime state)
│   └── containerd.sock           (separate socket)
├── /etc/opt/flintlockd/
│   └── config.yaml               (flintlock config)
└── /etc/rc.d/
    ├── rc.containerd-microvm     (containerd for microVMs)
    └── rc.flintlockd             (flintlock service)
```

### Separation from Docker's containerd
- Docker 29.5.1 on Unraid uses its own bundled containerd at `/run/containerd/containerd.sock`
- Our microVM containerd runs on a **separate socket**, **separate state dir**, **separate root dir**
- Zero interference between the two

---

## Step 1: Download Binaries

### 1.1 Flintlockd

```bash
# On Unraid (SSH as root)
FLINTLOCK_VERSION="v0.9.1"
wget -O /usr/local/bin/flintlockd \
  "https://github.com/liquidmetal-dev/flintlock/releases/download/${FLINTLOCK_VERSION}/flintlockd_amd64"
chmod +x /usr/local/bin/flintlockd

# Verify
flintlockd version
```

### 1.2 Containerd (Static Binary)

For Unraid (Slackware-based, may not have glibc 2.35), use the **static** binary:

```bash
# Use v1.7.33 (LTS, well-tested, compatible with flintlock)
CONTAINERD_VERSION="1.7.33"
cd /tmp
wget "https://github.com/containerd/containerd/releases/download/v${CONTAINERD_VERSION}/containerd-static-${CONTAINERD_VERSION}-linux-amd64.tar.gz"

# Extract to /usr/local/ (creates bin/containerd, bin/ctr, etc.)
# BUT we don't want to overwrite Docker's containerd!
# Extract to temp and copy just what we need
mkdir -p /tmp/containerd-extract
tar xzf "containerd-static-${CONTAINERD_VERSION}-linux-amd64.tar.gz" -C /tmp/containerd-extract

# Install as separate binary name to avoid conflict
cp /tmp/containerd-extract/bin/containerd /usr/local/bin/containerd-microvm
cp /tmp/containerd-extract/bin/ctr /usr/local/bin/ctr-microvm
chmod +x /usr/local/bin/containerd-microvm /usr/local/bin/ctr-microvm
rm -rf /tmp/containerd-extract /tmp/containerd-static-*.tar.gz

# Verify
containerd-microvm --version
```

> **Note:** We rename to `containerd-microvm` to avoid any conflict with Docker's containerd.
> Alternatively, you can keep the standard name since flintlockd connects via socket path,
> but renaming is safer on Unraid where Docker manages its own containerd.

### 1.3 Hammertime (Testing Tool)

```bash
# Optional: CLI tool for testing flintlock
wget -O /usr/local/bin/hammertime \
  "https://github.com/warehouse-13/hammertime/releases/latest/download/hammertime-linux-amd64"
chmod +x /usr/local/bin/hammertime
```

---

## Step 2: Setup Devmapper Thinpool (for Firecracker)

Flintlock's docs say devmapper is required for Firecracker (needs block devices).
For Cloud Hypervisor with virtiofs, you may skip this — see Step 2B.

### 2A: Loop-backed Devpool (Development/Testing)

This is simpler but slower. Uses sparse files on existing filesystem:

```bash
# Create directories
CONTAINERD_ROOT="/mnt/cache/containerd-microvm"
mkdir -p "${CONTAINERD_ROOT}/snapshotter/devmapper"

# Create sparse files (adjust sizes as needed)
POOL_DATA="${CONTAINERD_ROOT}/snapshotter/devmapper/data"
POOL_META="${CONTAINERD_ROOT}/snapshotter/devmapper/metadata"

truncate -s 100G "$POOL_DATA"
truncate -s 10G "$POOL_META"

# Associate loop devices
DATA_DEV=$(losetup --find --show "$POOL_DATA")
META_DEV=$(losetup --find --show "$POOL_META")

echo "Data device: $DATA_DEV"
echo "Meta device: $META_DEV"

# Create thinpool
POOL_NAME="flintlock-dev-thinpool"
SECTORSIZE=512
DATA_BLOCK_SIZE=128
LOW_WATER_MARK=32768
DATASIZE=$(blockdev --getsize64 -q "$DATA_DEV")
LENGTH_SECTORS=$(echo "$DATASIZE/$SECTORSIZE" | bc)

dmsetup create "$POOL_NAME" \
  --table "0 $LENGTH_SECTORS thin-pool $META_DEV $DATA_DEV $DATA_BLOCK_SIZE $LOW_WATER_MARK 1 skip_block_zeroing"

# Verify
dmsetup ls | grep flintlock
```

### 2B: Overlayfs-Only (Cloud Hypervisor + Virtiofs)

If you ONLY use Cloud Hypervisor and virtiofs for rootfs (no Firecracker), you can
configure containerd with the default overlayfs snapshotter and skip devmapper entirely.
This is simpler but is **experimental** with flintlock — the docs explicitly use devmapper.

```bash
# Just create state directories
mkdir -p /mnt/cache/containerd-microvm
mkdir -p /run/containerd-microvm
```

---

## Step 3: Configure Containerd for MicroVM Use

### 3.1 Write Configuration

```bash
mkdir -p /etc/containerd

cat > /etc/containerd/config-microvm.toml << 'EOF'
version = 2

root = "/mnt/cache/containerd-microvm"
state = "/run/containerd-microvm"

[grpc]
  address = "/run/containerd-microvm/containerd.sock"

[metrics]
  address = "127.0.0.1:1339"

[plugins]
  [plugins."io.containerd.snapshotter.v1.devmapper"]
    pool_name = "flintlock-dev-thinpool"
    root_path = "/mnt/cache/containerd-microvm/snapshotter/devmapper"
    base_image_size = "10GB"
    discard_blocks = true

[debug]
  level = "info"
EOF
```

> **For overlayfs-only (no devmapper):** Remove the `[plugins."io.containerd.snapshotter.v1.devmapper"]` section entirely. containerd will default to overlayfs.

### 3.2 Create State Directories

```bash
mkdir -p /mnt/cache/containerd-microvm/snapshotter/devmapper
mkdir -p /run/containerd-microvm
```

---

## Step 4: Configure Flintlockd

### 4.1 Write Configuration

```bash
mkdir -p /etc/opt/flintlockd

cat > /etc/opt/flintlockd/config.yaml << 'EOF'
---
containerd-socket: /run/containerd-microvm/containerd.sock
grpc-endpoint: 0.0.0.0:9090
parent-iface: br0
default-provider: cloudhypervisor
insecure: true
verbosity: 9
EOF
```

**Config options:**
- `containerd-socket`: Path to our dedicated microVM containerd
- `grpc-endpoint`: Listen address (0.0.0.0 for network access)
- `parent-iface`: `br0` on Unraid (the bridge interface for VMs). Use macvtap.
- `default-provider`: `cloudhypervisor` or `firecracker`
- `insecure`: Disable TLS (for testing)
- `--bridge-name`: Alternative to `parent-iface` if using a bridge

### 4.2 Additional Flintlockd Flags

```
--cloud-hypervisor-bin    Path to CH binary (default: looks in PATH)
--firecracker-bin         Path to FC binary
--virtiofs-bin            Path to virtiofsd (for CH virtiofs volumes, v0.8.0+)
```

---

## Step 5: RC Scripts (Unraid Compatible)

### 5.1 Containerd Service: `/etc/rc.d/rc.containerd-microvm`

```bash
cat > /etc/rc.d/rc.containerd-microvm << 'RCEOF'
#!/bin/bash
#
# rc.containerd-microvm - Containerd for MicroVM use (separate from Docker)
#

DAEMON="/usr/local/bin/containerd-microvm"
CONFIG="/etc/containerd/config-microvm.toml"
PIDFILE="/run/containerd-microvm/containerd.pid"
LOGFILE="/var/log/containerd-microvm.log"

DEVPOOL_DATA="/mnt/cache/containerd-microvm/snapshotter/devmapper/data"
DEVPOOL_META="/mnt/cache/containerd-microvm/snapshotter/devmapper/metadata"
POOL_NAME="flintlock-dev-thinpool"

setup_devpool() {
    # Check if thinpool already exists
    if dmsetup ls 2>/dev/null | grep -q "$POOL_NAME"; then
        echo "Thinpool $POOL_NAME already active"
        return 0
    fi

    # Check if sparse files exist
    if [[ ! -f "$DEVPOOL_DATA" ]] || [[ ! -f "$DEVPOOL_META" ]]; then
        echo "Devpool sparse files not found, skipping devmapper setup"
        return 0
    fi

    # Associate loop devices
    DATA_DEV=$(losetup --find --show "$DEVPOOL_DATA" 2>/dev/null)
    META_DEV=$(losetup --find --show "$DEVPOOL_META" 2>/dev/null)

    if [[ -z "$DATA_DEV" ]] || [[ -z "$META_DEV" ]]; then
        echo "Failed to setup loop devices"
        return 1
    fi

    # Create thinpool
    DATASIZE=$(blockdev --getsize64 -q "$DATA_DEV")
    LENGTH_SECTORS=$((DATASIZE / 512))
    dmsetup create "$POOL_NAME" \
        --table "0 $LENGTH_SECTORS thin-pool $META_DEV $DATA_DEV 128 32768 1 skip_block_zeroing"

    echo "Devpool $POOL_NAME created (data=$DATA_DEV, meta=$META_DEV)"
}

teardown_devpool() {
    if dmsetup ls 2>/dev/null | grep -q "$POOL_NAME"; then
        dmsetup remove "$POOL_NAME" 2>/dev/null
    fi
    # Detach loop devices for our sparse files
    for f in "$DEVPOOL_DATA" "$DEVPOOL_META"; do
        dev=$(losetup --output NAME --noheadings --associated "$f" 2>/dev/null)
        if [[ -n "$dev" ]]; then
            losetup -d "$dev" 2>/dev/null
        fi
    done
}

start() {
    if [[ ! -x "$DAEMON" ]]; then
        echo "containerd-microvm binary not found at $DAEMON"
        return 1
    fi

    if [[ -f "$PIDFILE" ]] && kill -0 "$(cat "$PIDFILE")" 2>/dev/null; then
        echo "containerd-microvm already running (PID $(cat "$PIDFILE"))"
        return 0
    fi

    echo "Starting containerd-microvm..."
    mkdir -p /run/containerd-microvm
    mkdir -p /mnt/cache/containerd-microvm

    # Setup devmapper thinpool (if configured)
    setup_devpool

    $DAEMON --config "$CONFIG" >> "$LOGFILE" 2>&1 &
    echo $! > "$PIDFILE"

    # Wait for socket
    for i in $(seq 1 10); do
        if [[ -S /run/containerd-microvm/containerd.sock ]]; then
            echo "containerd-microvm started (PID $(cat "$PIDFILE"))"
            return 0
        fi
        sleep 1
    done
    echo "Warning: containerd-microvm socket not ready after 10s"
}

stop() {
    if [[ -f "$PIDFILE" ]]; then
        PID=$(cat "$PIDFILE")
        if kill -0 "$PID" 2>/dev/null; then
            echo "Stopping containerd-microvm (PID $PID)..."
            kill "$PID"
            sleep 2
            kill -9 "$PID" 2>/dev/null
        fi
        rm -f "$PIDFILE"
    fi
    teardown_devpool
    echo "containerd-microvm stopped"
}

status() {
    if [[ -f "$PIDFILE" ]] && kill -0 "$(cat "$PIDFILE")" 2>/dev/null; then
        echo "containerd-microvm is running (PID $(cat "$PIDFILE"))"
        echo "Socket: /run/containerd-microvm/containerd.sock"
        ctr-microvm --address /run/containerd-microvm/containerd.sock version 2>/dev/null
    else
        echo "containerd-microvm is not running"
    fi
}

case "$1" in
    start)  start ;;
    stop)   stop ;;
    restart) stop; sleep 1; start ;;
    status) status ;;
    *)
        echo "Usage: $0 {start|stop|restart|status}"
        exit 1
        ;;
esac
RCEOF

chmod +x /etc/rc.d/rc.containerd-microvm
```

### 5.2 Flintlockd Service: `/etc/rc.d/rc.flintlockd`

```bash
cat > /etc/rc.d/rc.flintlockd << 'RCEOF'
#!/bin/bash
#
# rc.flintlockd - Flintlock MicroVM Manager
# Requires: rc.containerd-microvm
#

DAEMON="/usr/local/bin/flintlockd"
CONFIG="/etc/opt/flintlockd/config.yaml"
PIDFILE="/run/flintlockd.pid"
LOGFILE="/var/log/flintlockd.log"

start() {
    if [[ ! -x "$DAEMON" ]]; then
        echo "flintlockd binary not found at $DAEMON"
        return 1
    fi

    if [[ -f "$PIDFILE" ]] && kill -0 "$(cat "$PIDFILE")" 2>/dev/null; then
        echo "flintlockd already running (PID $(cat "$PIDFILE"))"
        return 0
    fi

    # Ensure containerd-microvm is running
    if [[ ! -S /run/containerd-microvm/containerd.sock ]]; then
        echo "containerd-microvm not running, starting it first..."
        /etc/rc.d/rc.containerd-microvm start
        sleep 2
    fi

    echo "Starting flintlockd..."
    $DAEMON run \
        --containerd-socket /run/containerd-microvm/containerd.sock \
        --parent-iface br0 \
        --default-provider cloudhypervisor \
        --grpc-endpoint 0.0.0.0:9090 \
        --insecure \
        >> "$LOGFILE" 2>&1 &

    echo $! > "$PIDFILE"
    sleep 2

    if kill -0 "$(cat "$PIDFILE")" 2>/dev/null; then
        echo "flintlockd started (PID $(cat "$PIDFILE")) on :9090"
    else
        echo "flintlockd failed to start. Check $LOGFILE"
        return 1
    fi
}

stop() {
    if [[ -f "$PIDFILE" ]]; then
        PID=$(cat "$PIDFILE")
        if kill -0 "$PID" 2>/dev/null; then
            echo "Stopping flintlockd (PID $PID)..."
            kill "$PID"
            sleep 2
            kill -9 "$PID" 2>/dev/null
        fi
        rm -f "$PIDFILE"
    fi
    echo "flintlockd stopped"
}

status() {
    if [[ -f "$PIDFILE" ]] && kill -0 "$(cat "$PIDFILE")" 2>/dev/null; then
        echo "flintlockd is running (PID $(cat "$PIDFILE"))"
        echo "gRPC endpoint: 0.0.0.0:9090"
    else
        echo "flintlockd is not running"
    fi
}

case "$1" in
    start)  start ;;
    stop)   stop ;;
    restart) stop; sleep 1; start ;;
    status) status ;;
    *)
        echo "Usage: $0 {start|stop|restart|status}"
        exit 1
        ;;
esac
RCEOF

chmod +x /etc/rc.d/rc.flintlockd
```

---

## Step 6: Start Services

```bash
# Start containerd first
/etc/rc.d/rc.containerd-microvm start

# Verify containerd
ctr-microvm --address /run/containerd-microvm/containerd.sock version

# Start flintlockd
/etc/rc.d/rc.flintlockd start

# Check logs
tail -20 /var/log/flintlockd.log
```

Expected flintlockd output:
```
INFO[0000] flintlockd, version=v0.9.1, built_on=..., commit=...
INFO[0000] flintlockd grpc api server starting
INFO[0000] starting microvm controller
INFO[0000] starting microvm controller with 1 workers    controller=microvm
INFO[0000] resyncing microvm specs                       controller=microvm
WARN[0000] basic authentication is DISABLED
WARN[0000] TLS is DISABLED
INFO[0000] starting event listener                       controller=microvm
```

---

## Step 7: Test with Hammertime or gRPC

### 7.1 Pull Test Images First

```bash
# Pull a kernel image into our containerd
ctr-microvm --address /run/containerd-microvm/containerd.sock \
    --namespace flintlock \
    images pull ghcr.io/liquidmetal-dev/cloudhypervisor-kernel:6.2

# Pull a rootfs image
ctr-microvm --address /run/containerd-microvm/containerd.sock \
    --namespace flintlock \
    images pull ghcr.io/liquidmetal-dev/ubuntu-cloudimage:22.04
```

### 7.2 Create MicroVM with Hammertime

```bash
hammertime create \
    -a 127.0.0.1:9090 \
    -n test-vm \
    -ns default
```

### 7.3 Create MicroVM with grpcurl

```bash
# Install grpcurl if not present
wget -O /tmp/grpcurl.tar.gz \
  "https://github.com/fullstorydev/grpcurl/releases/latest/download/grpcurl_1.9.3_linux_amd64.tar.gz"
tar xzf /tmp/grpcurl.tar.gz -C /usr/local/bin/ grpcurl

# Create a MicroVM
grpcurl -plaintext -d '{
  "microvm": {
    "id": "test-vm",
    "namespace": "default",
    "vcpu": 2,
    "memory_in_mb": 1024,
    "kernel": {
      "image": "ghcr.io/liquidmetal-dev/cloudhypervisor-kernel:6.2",
      "cmdline": {},
      "filename": "vmlinux",
      "add_network_config": true
    },
    "rootVolume": [{
      "id": "root",
      "is_read_only": false,
      "source": {
        "container_source": "ghcr.io/liquidmetal-dev/ubuntu-cloudimage:22.04"
      }
    }],
    "interfaces": [{
      "device_id": "eth0",
      "type": 1
    }]
  }
}' 127.0.0.1:9090 microvm.services.api.v1alpha1.MicroVM/CreateMicroVM

# List MicroVMs
grpcurl -plaintext 127.0.0.1:9090 microvm.services.api.v1alpha1.MicroVM/ListMicroVMs

# Delete
grpcurl -plaintext -d '{
  "uid": "<UID_FROM_CREATE>"
}' 127.0.0.1:9090 microvm.services.api.v1alpha1.MicroVM/DeleteMicroVM
```

---

## Compatibility Matrix

| Flintlock Version | Cloud Hypervisor | Firecracker | Containerd |
|-------------------|-----------------|-------------|------------|
| v0.9.x | ✅ Latest | ✅ v1.x+ (PCI required) | v1.6.x - v1.7.x |
| v0.8.x | ✅ + VirtioFS | ✅ v1.x+ | v1.6.x - v1.7.x |
| v0.7.x | ✅ | ✅ v1.x+ | v1.6.x - v1.7.x |

**Note:** Flintlock was developed against containerd 1.6.x/1.7.x. Containerd 2.x compatibility is untested/unknown. Recommend sticking with **v1.7.33** (LTS, still actively maintained).

---

## Unraid-Specific Considerations

### Networking
- Unraid uses `br0` as the bridge for VMs — use `--parent-iface br0`
- Flintlock supports macvtap (wired connection) — perfect for Unraid
- Check: `modprobe macvlan` (should succeed on Unraid)

### Storage
- Store containerd root on NVMe cache: `/mnt/cache/containerd-microvm/`
- Devpool sparse files should be on fast storage (NVMe)
- **Do NOT put on FUSE shfs** (`/mnt/user/`) — too slow for block operations

### Persistence
- RC scripts auto-recreate loop devices + thinpool on start
- VM definitions persist in containerd's bolt database
- Add to `/boot/config/go` for auto-start on boot:
  ```bash
  # Auto-start microVM services
  /etc/rc.d/rc.containerd-microvm start
  /etc/rc.d/rc.flintlockd start
  ```

### No systemd
- Flintlock's provision.sh assumes systemd — we bypass this entirely
- Our rc.d scripts handle process management directly
- No socket activation needed

### Kernel Modules
- Ensure KVM is loaded: `modprobe kvm_intel` (should be by default)
- Ensure macvlan: `modprobe macvlan`
- Ensure tun/tap: `modprobe tun`
- Device mapper: `modprobe dm_thin_pool`

---

## Troubleshooting

### Containerd fails to start
```bash
# Check if devmapper pool exists
dmsetup ls

# Check logs
cat /var/log/containerd-microvm.log

# Common: "devmapper pool not found" — run devpool setup first
```

### Flintlockd can't connect to containerd
```bash
# Check socket exists
ls -la /run/containerd-microvm/containerd.sock

# Test manually
ctr-microvm --address /run/containerd-microvm/containerd.sock version
```

### MicroVM fails to create
```bash
# Check cloud-hypervisor is in PATH
which cloud-hypervisor

# Check KVM access
ls -la /dev/kvm

# Check flintlock logs
tail -50 /var/log/flintlockd.log
```

---

## Comparison: Flintlock vs Direct CH Management

| Feature | Flintlock | Direct CH (current plugin) |
|---------|-----------|---------------------------|
| OCI image as rootfs | ✅ Native | ❌ Manual (crane + mkfs) |
| Cloud-init metadata | ✅ Built-in | ❌ Manual |
| gRPC API | ✅ Full CRUD | ❌ CH HTTP API only |
| Multiple providers | ✅ CH + FC | ✅ CH only |
| Complexity | High (containerd + devmapper) | Low (single binary) |
| Dependencies | containerd + devmapper | None |
| Maintenance risk | Medium (community project) | Low (self-maintained) |
| Kubernetes integration | ✅ (CAPI) | ❌ |

### Recommendation

For the Unraid plugin, **direct CH management is simpler and more appropriate** unless:
- You need to run VMs from arbitrary OCI images without manual conversion
- You want Kubernetes CAPI integration
- You need to support both Firecracker and Cloud Hypervisor

The flintlock approach adds significant complexity (containerd + devmapper thinpool)
that may not justify the benefits for a typical Unraid user.

---

## References

- [Flintlock Documentation](https://flintlock.liquidmetal.dev/docs/intro/)
- [Flintlock GitHub](https://github.com/liquidmetal-dev/flintlock)
- [Flintlock Releases](https://github.com/liquidmetal-dev/flintlock/releases)
- [Containerd Releases](https://github.com/containerd/containerd/releases)
- [Containerd Devmapper Docs](https://containerd.io/docs/1.7/snapshotters/devmapper/)
- [Hammertime CLI](https://github.com/warehouse-13/hammertime)
- [Liquid Metal Project](https://www.liquidmetal.dev)
- [gRPC Proto (buf.build)](https://buf.build/liquidmetal-dev/flintlock)
