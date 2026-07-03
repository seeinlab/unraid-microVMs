# Test Results

## Environment
- Unraid 6.12.90, Intel i5-13500, 32GB RAM, Docker 29.5.1
- AlmaLinux 9.7 VM (192.168.50.10)
- All tests: July 1-3, 2026

## Test 1: Cloud Hypervisor in Docker (cloud-hypervisor-lab)
**Status: ✅ ALL PASSED** — Ran 21+ hours stable

| Test | Result |
|------|--------|
| CH v52.0 binary | ✅ Works in Debian container |
| Boot Alpine VM | ✅ ~113ms boot |
| Nginx in microVM | ✅ Real nginx serving pages |
| Multi-VM (2 concurrent) | ✅ Both serving |
| Live CPU resize (2→4) | ✅ Hot-add confirmed |
| Live RAM resize (512M→1G) | ✅ |
| Hot-add disk | ✅ PCI device assigned |
| Snapshot (pause→snap→resume) | ✅ 1.1GB snapshot files |
| ACPI graceful shutdown | ✅ VM exits cleanly |
| Restore from snapshot | ✅ VM restored with config |

## Test 2: Firecracker in Docker (firecracker-lab)
**Status: ✅ ALL PASSED**

| Test | Result |
|------|--------|
| FC v1.16.0 binary | ✅ |
| Boot Alpine VM | ✅ ~280ms boot |
| Multi-VM (2 concurrent) | ✅ Both on different TAPs |
| Networking (TAP + bridge) | ✅ Web server accessible |
| Snapshot (pause→snap→resume) | ✅ 513MB snapshot |
| Resume after snapshot | ✅ Web still responds |

## Test 3: OCI → Firecracker Rootfs (AlmaLinux)
**Status: ✅ PASSED**

Pipeline: `podman pull nginx:alpine → podman export → extract to ext4 → inject /init → boot in FC`
Result: Real nginx (v1.31.2) serving HTTP from Firecracker microVM

## Test 4: Cloud Hypervisor Native on Unraid Host
**Status: ✅ ALL PASSED**

| Test | Result |
|------|--------|
| CH binary runs on Unraid | ✅ v52.0 |
| TAP on br0 | ✅ Created, attached |
| VM boots (192.168.50.200) | ✅ |
| Ping from host | ✅ 0.32-0.38ms RTT |
| Nginx web server | ✅ Full HTML page |
| LAN access from Windows | ✅ curl from 192.168.50.x works! |
| Live resize (1→2 CPU, 256→512M) | ✅ |
| Shutdown → TAP persists | ✅ |
| Restart → same TAP + rootfs | ✅ |
| Snapshot + resume | ✅ 257MB files |
| Destroy → TAP + rootfs persist | ✅ |

## Test 5: Both VMMs on AlmaLinux 9.7
**Status: ✅ PASSED**

- CH v52.0: Running, API responsive, resize works
- FC v1.16.0: Running, network pingable (0.2ms)
- Both running simultaneously on same host

## Key Findings

1. **Cloud Hypervisor is more Docker-stable** than Firecracker (ACPI, no cgroup conflicts)
2. **Native Unraid works perfectly** — just needs TAP + br0 + static binary
3. **Shutdown ≠ destroy** — TAP persists, rootfs persists, only process exits
4. **Reboot = recreate TAPs** (ephemeral) but data safe on /mnt/cache/
5. **OCI→rootfs** works via podman export or crane
6. **LAN-accessible microVMs** work by attaching TAP to br0 (same as libvirt VMs)
