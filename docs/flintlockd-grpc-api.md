# Flintlockd gRPC API Reference

## Service

```
microvm.services.api.v1alpha1.MicroVM
```

**Endpoint:** `0.0.0.0:9090` (plaintext, no TLS)

## Methods

### CreateMicroVM

Creates and starts a microVM. Flintlockd pulls the kernel and rootfs OCI images via containerd, creates a devmapper thin pool snapshot for the rootfs, and boots the VM using the specified provider (VMM).

**Request payload:**

| Field | Type | Description |
|-------|------|-------------|
| `microvm.id` | string | VM identifier (unique within namespace) |
| `microvm.namespace` | string | Namespace for grouping VMs |
| `microvm.vcpu` | int32 | Number of vCPUs |
| `microvm.memory_in_mb` | int32 | RAM in MB (min: 1024, max: 32768) |
| `microvm.kernel.image` | string | OCI image ref for kernel |
| `microvm.kernel.filename` | string | Kernel filename within image (e.g. `boot/vmlinux`) |
| `microvm.kernel.cmdline` | map | Kernel boot parameters |
| `microvm.kernel.add_network_config` | bool | Inject network config into kernel cmdline |
| `microvm.root_volume.id` | string | Volume identifier (e.g. `root`) |
| `microvm.root_volume.is_read_only` | bool | Mount read-only |
| `microvm.root_volume.source.container_source` | string | OCI image for rootfs |
| `microvm.root_volume.size_in_mb` | int32 | Disk size in MB |
| `microvm.interfaces[]` | array | Network interfaces |
| `microvm.interfaces[].device_id` | string | Interface name (e.g. `eth0`) |
| `microvm.interfaces[].type` | int32 | Interface type (1 = macvtap, 2 = tap) |
| `microvm.provider` | string | VMM provider: `cloudhypervisor` or `firecracker` |

**Example — Create with Cloud Hypervisor:**

```bash
grpcurl -plaintext -d '{
  "microvm": {
    "id": "test-vm",
    "namespace": "default",
    "vcpu": 2,
    "memory_in_mb": 2048,
    "kernel": {
      "image": "localhost:5050/kernel/ch:latest",
      "filename": "boot/vmlinux",
      "cmdline": {
        "console": "ttyS0",
        "root": "/dev/vda",
        "rw": ""
      },
      "add_network_config": true
    },
    "root_volume": {
      "id": "root",
      "is_read_only": false,
      "source": {
        "container_source": "docker.io/library/alpine:3.18"
      },
      "size_in_mb": 5120
    },
    "interfaces": [
      {
        "device_id": "eth0",
        "type": 2
      }
    ],
    "provider": "cloudhypervisor"
  }
}' 0.0.0.0:9090 microvm.services.api.v1alpha1.MicroVM/CreateMicroVM
```

**Example — Create with Firecracker:**

```bash
grpcurl -plaintext -d '{
  "microvm": {
    "id": "fc-vm",
    "namespace": "default",
    "vcpu": 1,
    "memory_in_mb": 1024,
    "kernel": {
      "image": "localhost:5050/kernel/fc:latest",
      "filename": "boot/vmlinux",
      "cmdline": {
        "console": "ttyS0",
        "root": "/dev/vda",
        "rw": ""
      },
      "add_network_config": true
    },
    "root_volume": {
      "id": "root",
      "is_read_only": false,
      "source": {
        "container_source": "docker.io/library/alpine:3.18"
      },
      "size_in_mb": 2048
    },
    "interfaces": [
      {
        "device_id": "eth0",
        "type": 2
      }
    ],
    "provider": "firecracker"
  }
}' 0.0.0.0:9090 microvm.services.api.v1alpha1.MicroVM/CreateMicroVM
```

---

### GetMicroVM

Retrieves a microVM by its UID (assigned by flintlockd on creation).

**Request payload:**

| Field | Type | Description |
|-------|------|-------------|
| `uid` | string | UUID assigned by flintlockd |

**Example:**

```bash
grpcurl -plaintext -d '{
  "uid": "a1b2c3d4-e5f6-7890-abcd-ef1234567890"
}' 0.0.0.0:9090 microvm.services.api.v1alpha1.MicroVM/GetMicroVM
```

**Response** includes full `microvm` spec and `status` (state, retry count, etc.).

---

### ListMicroVMs

Lists microVMs within a namespace. Optionally filter by name.

**Request payload:**

| Field | Type | Description |
|-------|------|-------------|
| `namespace` | string | Namespace to list from |
| `name` | string | (Optional) Filter by VM id/name |

**Example — List all in namespace:**

```bash
grpcurl -plaintext -d '{
  "namespace": "default"
}' 0.0.0.0:9090 microvm.services.api.v1alpha1.MicroVM/ListMicroVMs
```

**Example — Filter by name:**

```bash
grpcurl -plaintext -d '{
  "namespace": "default",
  "name": "test-vm"
}' 0.0.0.0:9090 microvm.services.api.v1alpha1.MicroVM/ListMicroVMs
```

---

### DeleteMicroVM

Deletes a microVM by UID. Performs graceful shutdown:

1. Sends `vm.shutdown` to the VMM
2. Waits up to 30 seconds for clean exit
3. Sends `SIGHUP` if VM hasn't stopped
4. Cleans up containerd snapshot and thin pool device

**Request payload:**

| Field | Type | Description |
|-------|------|-------------|
| `uid` | string | UUID of the VM to delete |

**Example:**

```bash
grpcurl -plaintext -d '{
  "uid": "a1b2c3d4-e5f6-7890-abcd-ef1234567890"
}' 0.0.0.0:9090 microvm.services.api.v1alpha1.MicroVM/DeleteMicroVM
```

---

## Providers

| Value | VMM | Kernel Image |
|-------|-----|--------------|
| `cloudhypervisor` | Cloud Hypervisor v52.0 | `localhost:5050/kernel/ch:latest` (Linux 6.2.0, PVH) |
| `firecracker` | Firecracker v1.16.0 | `localhost:5050/kernel/fc:latest` (Linux 5.10.225) |

## Validation Rules

| Field | Rule |
|-------|------|
| `memory_in_mb` | Minimum: 1024 MB, Maximum: 32768 MB |
| `id` | Must be unique within namespace |
| `namespace` | Required, non-empty |
| `provider` | Must be `cloudhypervisor` or `firecracker` |

## Storage Architecture

Flintlockd delegates rootfs management to containerd with the devmapper snapshotter:

```
OCI Image (e.g. alpine:3.18)
    │
    ▼ containerd pull
Containerd Content Store
    │
    ▼ containerd unpack (devmapper snapshotter)
Thin Pool Snapshot (/dev/mapper/microvms-thinpool)
    │
    ▼ mount as block device
VM Root Volume (/dev/vda inside guest)
```

- **Thin pool**: `microvms-thinpool` (50GB sparse data + 500MB metadata, loop-backed)
- **Snapshotter**: `devmapper` (configured in containerd config)
- **Benefit**: Multiple VMs using same base image share layers (copy-on-write)
- **Containerd socket**: `/run/containerd-dev/containerd.sock`

## Kernel OCI Images

Kernel images are served from a local OCI registry (crane serve on port 5050):

```bash
# Registry location
/mnt/user/system/liquidmetal/crane/registry/

# Images available
localhost:5050/kernel/ch:latest    # Cloud Hypervisor kernel (PVH, Linux 6.2.0)
localhost:5050/kernel/fc:latest    # Firecracker kernel (Linux 5.10.225)
```

## Service Discovery

```bash
# List all available methods
grpcurl -plaintext 0.0.0.0:9090 list

# Describe the MicroVM service
grpcurl -plaintext 0.0.0.0:9090 describe microvm.services.api.v1alpha1.MicroVM
```

## Notes

- flintlockd does NOT support TLS in our deployment (plaintext gRPC)
- VM state is reconciled — flintlockd watches for drift and corrects
- The `uid` returned from `CreateMicroVM` is needed for Get/Delete operations
- Network interfaces use TAP devices attached to `br0` bridge on host
- Cloud Hypervisor kernel must have PVH boot header (stock liquidmetal images work)
- Firecracker kernel uses standard Linux boot protocol
