# Kata Containers Research — Potential Flintlockd Replacement

## Summary

Kata Containers is a containerd **runtime shim** (`containerd-shim-kata-v2`) that runs each container/pod inside a VM (CH, FC, or QEMU). It plugs directly into containerd as an alternative to `runc`.

## How It Works

```
containerd → shim (io.containerd.kata.v2) → cloud-hypervisor/firecracker → VM with guest kernel
```

Instead of `runc` creating a Linux namespace container, kata creates a full VM.

## Can It Replace Flintlockd?

| Feature | Flintlockd | Kata Containers |
|---------|-----------|-----------------|
| Manages VMs via containerd | ✅ gRPC API | ✅ containerd shim |
| Supports CH + FC | ✅ | ✅ |
| OCI image → rootfs | ✅ (containerd pull) | ✅ (built-in) |
| Thin pool / devmapper | ✅ (containerd snapshotter) | ✅ (same containerd) |
| gRPC API for remote access | ✅ (custom) | ❌ (uses CRI or ctr CLI) |
| No Kubernetes required | ✅ | ✅ (ctr / nerdctl) |
| Network (TAP/macvtap) | ✅ (built-in) | ✅ (CNI plugins) |
| Binary size | ~30MB | ~50MB (shim + kernel + hypervisor) |
| Active maintenance | ✅ (liquidmetal-dev) | ✅ (OpenStack foundation) |

## Usage Without Kubernetes

```bash
# Pull image
ctr image pull docker.io/library/nginx:alpine

# Run as VM (kata uses CH/FC under the hood)
ctr run --cni --runtime io.containerd.kata.v2 \
  --runtime-config-path /opt/kata/share/defaults/kata-containers/configuration-clh.toml \
  -t --rm docker.io/library/nginx:alpine my-vm sh
```

## Configuration (containerd config.toml)

```toml
version = 2

[plugins."io.containerd.grpc.v1.cri".containerd.runtimes.kata-clh]
  runtime_type = "io.containerd.kata.v2"
  [plugins."io.containerd.grpc.v1.cri".containerd.runtimes.kata-clh.options]
    ConfigPath = "/opt/kata/share/defaults/kata-containers/configuration-clh.toml"

[plugins."io.containerd.grpc.v1.cri".containerd.runtimes.kata-fc]
  runtime_type = "io.containerd.kata.v2"
  [plugins."io.containerd.grpc.v1.cri".containerd.runtimes.kata-fc.options]
    ConfigPath = "/opt/kata/share/defaults/kata-containers/configuration-fc.toml"
```

## Pros vs Flintlockd

- **Better maintained** — large community (OpenStack Foundation, 8.2k stars)
- **Standard containerd integration** — just a shim, no separate daemon
- **Supports multiple hypervisors** — CH, FC, QEMU, Dragonball
- **OCI native** — run any Docker image as a VM directly
- **No custom gRPC API to maintain** — uses standard containerd/CRI interface

## Cons vs Flintlockd

- **No dedicated gRPC API** — would need to use `ctr` CLI or CRI (crictl)
- **Heavier abstraction** — designed for container-like UX, not raw VM management
- **Requires kata kernel** — ships its own guest kernel (not our vmlinux)
- **CNI for networking** — different from our TAP-based approach
- **Pod model** — designed around pod semantics, not standalone VMs

## Verdict

Kata Containers is **viable but a different model**:
- Flintlockd = "VM-first" (create VM spec, boot it)
- Kata = "container-first" (run container image, it happens to be in a VM)

For our plugin where users explicitly create/manage VMs, **flintlockd is better fit**.
For a future where users just "run images" and don't care if it's VM or container, **kata is better**.

## Potential Hybrid Approach

Keep both:
- Direct mode (rc.microvm → CH/FC) for full VM control
- Kata shim registered in containerd for "container-as-VM" workflows
- flintlockd for gRPC remote automation

## Requirements to Install Kata on Unraid

1. `containerd-shim-kata-v2` binary (~30MB)
2. Kata guest kernel (separate from our vmlinux)
3. Kata configuration files (TOML per hypervisor)
4. CNI plugins for networking
5. Our containerd config updated with kata runtime entries
