# Thin Pool — Containerd OCI Flow (IMPLEMENTED)

## Final Implementation

### Create VM (Thin Pool)
```bash
SOCK=/var/run/microvms/containerd.sock
NS=cloud-hypervisor  # or firecracker (separate namespaces)

# 1. Pull image
ctr -a $SOCK -n $NS images pull --platform linux/amd64 docker.io/library/nginx:alpine

# 2. Mount as writable devmapper snapshot (ONE command: pull+unpack+snapshot+mount)
ctr -a $SOCK -n $NS images mount --snapshotter devmapper --rw --platform linux/amd64 \
  docker.io/library/nginx:alpine /tmp/microvm-mount-{name}

# 3. Inject /init (network config + OCI ENTRYPOINT/CMD)
# 4. Unmount (snapshot persists)
ctr -a $SOCK -n $NS images unmount /tmp/microvm-mount-{name}
```

### Start VM (Thin Pool)
```bash
# Get device path from snapshot (key = mount path)
ctr -a $SOCK -n $NS snapshots --snapshotter devmapper mounts /tmp "/tmp/microvm-mount-{name}"
# → mount -t ext4 /dev/mapper/microvms-thinpool-snap-{id} /tmp
# Parse: /dev/mapper/microvms-thinpool-snap-{id}

# Pass to CH/FC as disk
cloud-hypervisor --disk "path=/dev/mapper/microvms-thinpool-snap-14,readonly=false" ...
```

### Delete VM (Thin Pool)
```bash
# Remove snapshot
ctr -a $SOCK -n $NS snapshots --snapshotter devmapper remove "/tmp/microvm-mount-{name}"

# If no other VMs use same image, optionally clean image layers
ctr -a $SOCK -n $NS images rm docker.io/library/nginx:alpine
```

## Namespace Design

| Namespace | Used by | Rationale |
|-----------|---------|-----------|
| `cloud-hypervisor` | CH VMs | Matches flintlock pattern, clean isolation |
| `firecracker` | FC VMs | Independent VMM lifecycle tracking |
| `flintlock` | flintlockd (if enabled) | gRPC orchestration, multi-host |

Separate namespaces because:
- Flintlock compatibility (if Liquidmetal enabled later)
- Clean `ctr snapshots list -n {vmm}` per VMM
- Storage saving negligible (users typically use one VMM)

## Image Reference Normalization

containerd requires fully qualified references:
- `nginx:alpine` → `docker.io/library/nginx:alpine`
- `docker.io/nginx:alpine` → `docker.io/library/nginx:alpine`
- `ghcr.io/user/image:tag` → unchanged

## Init Script Injection

Every VM (both thin and raw) gets `/init` that:
1. Mounts /proc, /sys, /dev, /dev/pts
2. Parses kernel `ip=A::G:M:::off` cmdline → configures eth0
3. Sets DNS (8.8.8.8, 1.1.1.1)
4. Runs OCI ENTRYPOINT + CMD (from `crane config`)

CMD quoting: each arg shell-escaped via `escapeshellarg()`:
- `["nginx", "-g", "daemon off;"]` → `'nginx' '-g' 'daemon off;'`

## CH Disk Config

```
--disk "path=$device,readonly=false"
```
Required because CH v52 auto-detects raw type and disables sector 0 writes without explicit flag.

## Config JSON

### Thin Pool:
```json
{
  "storage": {
    "type": "thin",
    "image_ref": "docker.io/library/nginx:alpine"
  }
}
```

### Raw rootFS:
```json
{
  "storage": {
    "type": "raw",
    "size_mb": 200,
    "image_ref": "docker.io/library/nginx:alpine"
  }
}
```

## UI (AddMicroVMs.page)

- Storage Type: "Thin Pool" / "Raw rootFS"
- When Thin Pool: hides Disk Size, rootFS Source
- When Raw rootFS: shows all fields
- Progress: shows `ctr images pull` for thin, `crane export` for raw

## Cleanup

- On VM delete: remove snapshot, optionally remove image if unused
- Storage tab: "Prune Unused Images" button (planned)
- `ctr -a $SOCK -n $NS images prune` for bulk cleanup
