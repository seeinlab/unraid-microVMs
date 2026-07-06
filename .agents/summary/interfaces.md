# Interfaces & APIs

## WebGUI AJAX API

**Endpoint:** `POST /plugins/microvms/backend/MicroVMAdmin.php`

All parameters via `$_REQUEST` (form-encoded POST).

### VM Lifecycle
```
cmd=start&name={vm_name}
cmd=stop&name={vm_name}
cmd=force_stop&name={vm_name}
cmd=create&name={name}&engine={vmm}&cpus={n}&memory={mb}&ip={addr}&gateway={gw}&oci_image={ref}&storage_type={thin|raw}&disk_size={mb}
cmd=delete&name={vm_name}
```

### VM Operations
```
cmd=resize&name={vm_name}&cpus={n}&memory={mb}
cmd=snapshot&name={vm_name}&tag={tag}
cmd=restore_snapshot&name={vm_name}&tag={tag}
cmd=delete_snapshot&name={vm_name}&tag={tag}
cmd=info&name={vm_name}
```

### Service Control
```
cmd=service_action&service={containerd|flintlockd|registry}&action={start|stop|restart}
cmd=view_log&service={containerd|flintlockd|registry}
cmd=toggle_setting&key={CH_ENABLED|FC_ENABLED|DEVMAPPER|FLINTLOCKD}&value={enable|disable|yes|no}
cmd=download_kernel&engine={cloud-hypervisor|firecracker}
```

## Cloud Hypervisor API

**Socket:** `/tmp/microvms-{name}.sock`

Used via `ch-remote`:
- `ch-remote --api-socket $sock ping` — health check
- `ch-remote --api-socket $sock resize --cpus $n --memory ${mb}M` — hot resize
- `ch-remote --api-socket $sock snapshot $path` — create snapshot
- `ch-remote --api-socket $sock restore --source_url $path` — restore
- `ch-remote --api-socket $sock power-button` — ACPI shutdown

## Firecracker API

**Socket:** `/tmp/microvms-{name}.sock`

REST API over Unix socket (not used directly — config-file mode):
```
firecracker --api-sock $sock --config-file /tmp/microvms-{name}-fc.json
```

## Containerd API (via ctr CLI)

**Socket:** `/var/run/microvms/containerd.sock`

```bash
CTR="ctr -a /var/run/microvms/containerd.sock -n {namespace}"

# Image operations
$CTR images pull --platform linux/amd64 {ref}
$CTR images mount --snapshotter devmapper --rw --platform linux/amd64 {ref} {mountpoint}
$CTR images unmount {mountpoint}
$CTR images rm {ref}
$CTR images prune

# Snapshot operations
$CTR snapshots --snapshotter devmapper list
$CTR snapshots --snapshotter devmapper mounts /tmp {key}
$CTR snapshots --snapshotter devmapper remove {key}
$CTR snapshots --snapshotter devmapper prepare {key} {parent}
```

## Flintlockd gRPC API

**Endpoint:** `0.0.0.0:9090` (when enabled)

```bash
grpcurl -plaintext localhost:9090 microvm.services.v1alpha1.MicroVM/CreateMicroVM
grpcurl -plaintext localhost:9090 microvm.services.v1alpha1.MicroVM/GetMicroVM
grpcurl -plaintext localhost:9090 microvm.services.v1alpha1.MicroVM/ListMicroVMs
grpcurl -plaintext localhost:9090 microvm.services.v1alpha1.MicroVM/DeleteMicroVM
```

## Configuration File

**Path:** `/boot/config/plugins/microvms/microvms.controlplane.cfg`

INI format, sourced by bash:
```ini
SERVICE="enable"
VMDIR="/mnt/user/microvms"
BRIDGE="br0"
DEFAULT_CPUS="1"
DEFAULT_MEMORY="256"
DEFAULT_VMM="cloud-hypervisor"
AUTOSTART="no"
THINPOOL_DATA_SIZE_GB="50"
DEVMAPPER="enable"
CH_ENABLED="yes"
FC_ENABLED="no"
FLINTLOCKD="disable"
FLINTLOCKD_GRPC_PORT="9090"
FLINTLOCKD_EXTRA_FLAGS="--insecure --default-provider cloudhypervisor"
CRANE_REGISTRY_DIR="/mnt/user/system/microvms/crane/registry"
```

## VM Config JSON

**Path:** `/mnt/user/microvms/{name}/{vmm}.json`

VMM determined by filename (not by field inside JSON).

```json
{
  "name": "my-vm",
  "vcpus": 2,
  "memory_mb": 512,
  "storage": {
    "type": "thin",
    "image_ref": "docker.io/library/nginx:alpine"
  },
  "network": {
    "ip": "192.168.50.220",
    "gateway": "192.168.50.1",
    "mac": "52:54:00:xx:xx:xx",
    "bridge": "br0",
    "tap_id": 0
  },
  "image": {
    "source": "oci",
    "ref": "docker.io/library/nginx:alpine"
  },
  "kernel": {
    "cmdline": "console=ttyS0 root=/dev/vda rw init=/init"
  },
  "autostart": true
}
```
