# Plan: Thin Pool — Full Containerd OCI Flow (VERIFIED)

## Verified Commands on Unraid

```bash
SOCK=/var/run/microvms/containerd.sock
NS=cloud-hypervisor  # or firecracker

# 1. Pull image (stores in containerd content store)
ctr -a $SOCK -n $NS images pull docker.io/library/alpine:3.20

# 2. Mount image as writable thin device (ONE command does it all!)
ctr -a $SOCK -n $NS images mount --snapshotter devmapper --rw \
  docker.io/library/alpine:3.20 /tmp/microvm-mount-{name}
# → creates /dev/mapper/microvms-thinpool-snap-{id}
# → mounts it at /tmp/microvm-mount-{name}
# → snapshot key = "/tmp/microvm-mount-{name}" (or use custom key?)

# 3. Inject /init script (while mounted)
# ... write /init to mount point ...

# 4. Unmount (snapshot PERSISTS, device stays in pool)
ctr -a $SOCK -n $NS images unmount /tmp/microvm-mount-{name}

# 5. Get device path for VM start
ctr -a $SOCK -n $NS snapshots --snapshotter devmapper mounts /tmp "/tmp/microvm-mount-{name}"
# → "mount -t ext4 /dev/mapper/microvms-thinpool-snap-9 /tmp"
# → parse: /dev/mapper/microvms-thinpool-snap-9

# 6. Delete (when VM removed)
ctr -a $SOCK -n $NS snapshots --snapshotter devmapper remove "/tmp/microvm-mount-{name}"
```

## Better: Use VM name as snapshot key

Instead of `/tmp/microvm-mount-{name}` as key, we should use `vm-{name}`:

```bash
# Create: use --label or direct snapshot prepare from image digest
# After images pull, the image layers create committed snapshots.
# We prepare an active snapshot from the image's top layer.

# Get image's top layer chain ID:
PARENT=$(ctr -a $SOCK -n $NS snapshots --snapshotter devmapper list | grep "Committed" | awk '{print $1}')

# Prepare named snapshot from parent:
ctr -a $SOCK -n $NS snapshots --snapshotter devmapper prepare "vm-{name}" "$PARENT"

# Get device and mount for init injection:
DEVICE=$(ctr -a $SOCK -n $NS snapshots --snapshotter devmapper mounts /tmp "vm-{name}" | grep -oP '/dev/mapper/\S+')
mount $DEVICE /tmp/microvm-mount-{name}
# inject /init
umount /tmp/microvm-mount-{name}

# VM start: get device path
DEVICE=$(ctr -a $SOCK -n $NS snapshots --snapshotter devmapper mounts /tmp "vm-{name}" | grep -oP '/dev/mapper/\S+')
# → use $DEVICE as disk for CH/FC

# VM delete:
ctr -a $SOCK -n $NS snapshots --snapshotter devmapper remove "vm-{name}"
# Optionally: ctr -a $SOCK -n $NS images rm docker.io/... (if no other VMs use it)
```

## UI Changes (AddMicroVMs.page)

### Storage Type dropdown:
- **"Thin Pool"** — uses containerd OCI pull + devmapper snapshot
- **"Raw rootFS"** — uses crane export + raw file (current flow)

### When "Thin Pool" selected:
- HIDE: "rootFS Source" dropdown, "Disk Size" field
- SHOW: "OCI/Docker Image" field only
- Note: size is auto-managed by thin provisioning

### When "Raw rootFS" selected:
- SHOW: "rootFS Source", "OCI/Docker Image" OR "rootFS path", "Disk Size"
- Same as current behavior

### Memory:
- "Initial Memory" → stays (boot memory)
- Add "Max Memory" (CH only, for hotplug: default = initial × 2)
- Hide "Max Memory" if Firecracker selected

## Backend Changes (MicroVMAdmin.php)

### Thin Pool create path:
```php
$sock = '/var/run/microvms/containerd.sock';
$snapshotKey = "vm-$name";

// 1. Pull image
exec("ctr -a $sock -n $vmm images pull " . escapeshellarg($ociImage) . " 2>&1", $out, $ret);

// 2. Get parent (image's committed layer)
$parent = trim(shell_exec("ctr -a $sock -n $vmm snapshots --snapshotter devmapper list 2>/dev/null | grep Committed | tail -1 | awk '{print \$1}'"));

// 3. Prepare snapshot from parent
exec("ctr -a $sock -n $vmm snapshots --snapshotter devmapper prepare " . escapeshellarg($snapshotKey) . " " . escapeshellarg($parent) . " 2>&1", $out, $ret);

// 4. Get device path and mount
$device = trim(shell_exec("ctr -a $sock -n $vmm snapshots --snapshotter devmapper mounts /tmp " . escapeshellarg($snapshotKey) . " 2>/dev/null | grep -oP '/dev/mapper/\\S+'"));
exec("mount $device /tmp/microvm-mount-$name");

// 5. Inject /init
// ... same init script injection ...

// 6. Unmount
exec("umount /tmp/microvm-mount-$name");
```

### Config JSON (thin pool):
```json
{
  "storage": {
    "type": "thin",
    "image_ref": "docker.io/nginx:stable-trixie"
  }
}
```
- No `size_mb` (auto-managed)
- `image_ref` for reference only

## rc.microvms Changes

### activate_thin_rootfs (already works):
```bash
# Gets device path from snapshot
ctr snapshots --snapshotter devmapper mounts /tmp "vm-{name}" | grep -oP '/dev/mapper/\S+'
```

### CH launch — add max memory:
```bash
--memory "size=${memory}M,hotplug_size=${max_memory}M"
```
Currently: `hotplug_size=$((memory * 2))M` — make configurable.

## Files to Modify

| File | Change |
|------|--------|
| `AddMicroVMs.page` | Hide fields based on storage type, add max memory, rename labels |
| `MicroVMAdmin.php` | New thin pool path (ctr pull → prepare → mount → inject → unmount) |
| `MicroVMsSettingsGeneral.page` | Add DEFAULT_MAX_MEMORY |
| `rc.microvms` | Read max_memory from config, use in CH launch |
| `common.php` | Update microvm_get_network if needed |

## Test Plan

1. ✅ `ctr images pull` — verified working
2. ✅ `ctr images mount --snapshotter devmapper` — verified working  
3. ✅ Snapshot persists after unmount — verified
4. ✅ `snapshots mounts` returns device path — verified
5. [ ] Full create flow from UI → VM boots with thin device
6. [ ] VM accessible on LAN
7. [ ] Delete removes snapshot


## Cleanup: Unused Image Layers

### On VM Delete:
```php
// After removing snapshot vm-{name}:
// Check if any other VM uses the same image_ref
$imageRef = $config['storage']['image_ref'] ?? '';
$stillUsed = false;
foreach (glob("$vmdir/*/cloud-hypervisor.json") + glob("$vmdir/*/firecracker.json") as $f) {
    $other = json_decode(file_get_contents($f), true);
    if (($other['storage']['image_ref'] ?? '') === $imageRef) { $stillUsed = true; break; }
}
if (!$stillUsed && $imageRef) {
    exec("ctr -a $sock -n $vmm images rm " . escapeshellarg($imageRef) . " 2>/dev/null");
}
```

### Storage Tab (rename from "rootFS"):
- Add "Prune Unused Images" button
- Shows: used images, space usage, number of snapshots
- Prune command: `ctr -a $SOCK -n {ns} images prune`

## Tab Rename

- **"rootFS" tab** → **"Storage" tab**
- File: `MicroVMsRootFS.page` stays (filename doesn't matter for tab title)
- Change `Title="rootFS"` to `Title="Storage"` in the .page header

## Additional File to Modify

| File | Change |
|------|--------|
| `MicroVMsRootFS.page` | Rename title to "Storage", add "Prune Unused Images" button |
