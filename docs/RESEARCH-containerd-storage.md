# Containerd Storage Management for MicroVMs — Industry Research

> How AWS, Fly.io, and Kata Containers manage OCI images, devmapper snapshots, and garbage collection for microVM workloads.

## 1. AWS (firecracker-containerd)

### Production (Lambda) — Not Directly Applicable

AWS Lambda does NOT use open-source firecracker-containerd. Their proprietary system:
- Flattens OCI layers into a single ext4 filesystem deterministically
- Chunks the image into 512KiB blocks with convergent encryption
- Deduplicates across all customers (80% of uploads have zero unique chunks)
- 3-tier cache (worker → AZ → S3) achieves 99.9% hit rate at AZ level
- Uses generational GC: new root namespace periodically replaces old; live data migrates forward

**Relevance**: Architecture inspiration only — far too complex for single-host.

### Open-Source (firecracker-containerd) — Directly Relevant

**Image → RootFS flow:**
```
ctr images pull --snapshotter devmapper <image>
  → containerd unpacks layers into thin devices (one per layer)
  → container create → new active thin snapshot (COW from parent)
  → thin device path (/dev/mapper/...) passed as --disk to Firecracker
```

**Snapshot lifecycle:**
1. Image pull → layers become **committed** (read-only) snapshots in thin-pool
2. Container create → new **active** (read-write) thin snapshot from parent chain
3. Container exit → active snapshot marked for removal
4. GC runs → `dmsetup remove` thin device → optionally `BLKDISCARD`

**'Used by' tracking:**
- Containerd's graph-based GC uses root nodes: images, containers, and **leases**
- Any resource reachable from a root node is protected from collection
- Leases provide explicit protection for in-progress operations (pull, mount)

**Orphan cleanup:**
- Built-in GC triggers after deletion events or every 100 DB mutations
- Resources not reachable from any root → collected automatically
- **Known bug** (issue #3923): One failed snapshot removal stops entire GC traversal

**Key config:**
```toml
[plugins."io.containerd.snapshotter.v1.devmapper"]
  pool_name = "containerd-pool"
  base_image_size = "8192MB"
  async_remove = false
  discard_blocks = true    # Critical for space reclamation
  fs_type = "ext4"
```

---

## 2. Fly.io

### Image → RootFS Flow

```
OCI image push → Fly registry → local containerd (devmapper backend)
  → thin-pool COW snapshot from cached layers (O(1) creation)
  → block device passed to Firecracker
```

**Evolution (2024):** Added overlayfs on top of devmapper:
- Lower layer = read-only devmapper snapshot of image (shared, COW)
- Upper layer = ephemeral volume for writes (per-VM, disposable)
- On stop: destroy only the ephemeral upper (fast, predictable)
- On redeploy: keep base snapshot, create new upper (near-instant)

### Snapshot Lifecycle
1. First deploy: pull image → content store → devmapper committed snapshots
2. Boot VM: create thin snapshot → acquire lease → pass block device to FC
3. Stop VM: destroy ephemeral upper only (base snapshot survives)
4. Delete VM: release lease → containerd GC reclaims snapshot when unreferenced

### 'Used by' Tracking
- **Lease-based**: Each running VM holds a lease on its snapshot
- containerd refuses to GC any leased resource
- Lease released explicitly on VM termination

### Orphan Cleanup
- Lease release → snapshot becomes unreferenced → GC collects on next cycle
- No manual deletion of thin devices — entirely delegated to containerd GC
- containerd used purely as a **cache layer**, not a runtime

### Key Design Insight
> "containerd as a cache layer — not a runtime, just manages image storage + devmapper snapshots"

This is the closest model to what our plugin does.

---

## 3. Kata Containers

### Image → RootFS Flow

Kata adapts to whatever storage backend the host provides:

| Host Backend | Guest Access | Performance |
|---|---|---|
| Overlayfs | virtio-9p or virtio-fs (passthrough) | Slow / moderate |
| Devmapper | virtio-block (block device) | Fast, production-ready |

**Preferred (devmapper):**
```
containerd pulls image → devmapper creates thin device per layer
  → final snapshot = complete block device with rootfs
  → Kata attaches via virtio-block to guest
  → guest kernel mounts directly
```

### Snapshot Lifecycle
- New thin snapshot created per container start (no reuse between runs)
- Container removal triggers snapshot cleanup via containerd GC
- No special snapshot management — fully delegated to containerd

### 'Used by' Tracking
- Standard containerd container references protect snapshots
- No custom lease system — relies on container existence as the root reference

### Orphan Cleanup
- Container removal (`ctr containers rm`) → containerd GC cascades to snapshots
- Same GC mechanism as firecracker-containerd (graph traversal from roots)

### Key Finding
- **virtio-9p is NOT production-ready** (slow, unstable, not POSIX-compliant)
- Block devices via devmapper are the only viable path for VM-isolated storage
- Kata itself doesn't manage storage — it adapts to what containerd provides

---

## 4. Common Patterns

All three systems share these fundamental approaches:

### Universal: Devmapper Thin Provisioning
Every production microVM system uses dm-thinp for rootfs:
- COW snapshots enable instant VM creation from cached images
- Multiple VMs share base image blocks (deduplication at block level)
- Block devices are the only way to pass storage across the VM boundary

### Universal: Containerd as the Storage Brain
None of these systems manually manage thin devices. They all:
1. Pull images through containerd's content store
2. Let containerd's snapshotter create/manage thin devices
3. Delegate cleanup to containerd's built-in GC
4. Never call `dmsetup remove` directly

### Universal: Reference-Based GC (Graph Model)
```
Image (root) → Content blobs → Snapshots (committed) → Snapshots (active)
Container (root) ─────────────────────────────────────→ Snapshot (active)
Lease (root) ──────────────────────────────────────────→ Any resource
```
- Resources reachable from a root = protected
- Unreachable resources = collected
- Roots: images, containers, leases

### Universal: Lease Protection During Operations
- Leases prevent GC from collecting in-progress resources
- Acquired during: image pull, snapshot creation, VM boot
- Released on: operation completion, VM stop, VM delete
- Expirable: can set TTL to auto-release abandoned leases

### Universal: Known Devmapper Pain Points
| Issue | Impact | Mitigation |
|---|---|---|
| Space not returned on delete (#5691) | Disk fills up | `discard_blocks = true` |
| Unrecoverable pool states (#4790) | Pool unusable | Monitor usage, have reset path |
| GC stops on first failure (#3923) | Orphans accumulate | Retry logic, periodic forced cleanup |

---

## 5. Recommendations for Our Plugin

### Adopt Immediately

| Pattern | Implementation |
|---|---|
| **Lease-based lifecycle** | Acquire lease on `start_vm()`, release on `stop_vm()` / `delete_vm()` |
| **`discard_blocks = true`** | Add to containerd config — prevents disk space leak (critical for Unraid loop-backed pools) |
| **Let GC handle cleanup** | Stop manually deleting thin devices; use `ctr containers rm` / `ctr images rm` → GC cascades |
| **Container as root reference** | `ctr containers create --label microvm.*` already provides GC protection (we do this) |
| **Monitor thin pool** | `dmsetup status $POOL` in status checks; alert at 80% data usage |

### Adopt for V2 (Quality of Life)

| Pattern | Source | Benefit |
|---|---|---|
| **Overlayfs-on-devmapper** (Fly.io) | Ephemeral upper for writes, persistent lower for image | Fast VM reset without re-snapshotting |
| **Lease expiration TTL** | All systems | Auto-cleanup if plugin crashes without releasing lease |
| **`async_remove = true`** | firecracker-containerd | Faster VM delete (GC removes device in background) |
| **Periodic orphan sweep** | Cron/event hook | `ctr snapshots ls` → find unreferenced → trigger GC |

### Do NOT Adopt

| Pattern | Why Not |
|---|---|
| Lambda's chunking/dedup/tiered cache | Massive-scale only, requires distributed infra |
| Generational root GC | Needs multiple storage namespaces, overkill for single host |
| Custom containerd binary (fc-containerd) | We use stock containerd |
| Naive snapshotter (full copy) | No deduplication, wastes disk |
| Blockfile snapshotter | Simpler but loses COW sharing between instances |

### Recommended Cleanup Strategy

```bash
# On VM delete (ordered):
ctr -n $NS containers rm $VM_NAME          # Remove container reference
ctr -n $NS snapshots rm $SNAPSHOT_KEY      # Remove snapshot (or let GC)
# → containerd GC handles thin device removal + BLKDISCARD

# Periodic maintenance (cron or array_started event):
# List images with no running container referencing them
ctr -n $NS images ls | # cross-reference with running VMs
ctr -n $NS images rm <unused>              # GC cascades to snapshots

# Nuclear reset (user-confirmed only):
# reset_thinpool destroys ALL data — already implemented with 'yes' guard
```

### Containerd Config Template for Plugin

```toml
[plugins."io.containerd.snapshotter.v1.devmapper"]
  root_path = "/var/lib/containerd/devmapper"
  pool_name = "microvms-pool"
  base_image_size = "10GB"
  async_remove = true
  discard_blocks = true
  fs_type = "ext4"

[plugins."io.containerd.gc.v1.scheduler"]
  pause_threshold = 0.02
  deletion_threshold = 0
  mutation_threshold = 100
  schedule_delay = "0ms"
  startup_delay = "100ms"
```

### Current Plugin Gap Analysis

| Current Behavior | Problem | Fix |
|---|---|---|
| `ctr images mount` (one-shot) | Bypasses lease system | Switch to `snapshots prepare` + explicit lease |
| No `discard_blocks` in config | Disk space leaks on VM delete | Add to containerd config |
| Manual thin device cleanup | Races with GC, can corrupt state | Let GC handle it |
| No orphan detection | Leaked snapshots accumulate | Add periodic `snapshots ls` audit |
| No pool monitoring | Pool fills silently | Add `dmsetup status` to health checks |

---

## References

- [AWS Lambda Container Loading (USENIX ATC '23)](https://www.usenix.org/conference/atc23/presentation/brooker)
- [firecracker-containerd design docs](https://github.com/firecracker-microvm/firecracker-containerd/tree/main/docs)
- [Fly.io "Docker without Docker"](https://fly.io/blog/docker-without-docker/)
- [Kata Containers storage design](https://github.com/kata-containers/kata-containers/blob/main/docs/design/kata-design.md)
- [Flintlock architecture](https://github.com/liquidmetal-dev/flintlock)
- [containerd devmapper docs](https://github.com/containerd/containerd/blob/main/docs/snapshotters/devmapper.md)
- [containerd GC design](https://github.com/containerd/containerd/blob/main/docs/garbage-collection.md)
- containerd issues: #5691 (space leak), #4790 (unrecoverable states), #3923 (GC stops on failure)
