# Test Results v69+ (2026-07-04)

## Environment
- Unraid 6.12.90, kernel 6.12.90-Unraid
- Cloud Hypervisor v52.0
- Firecracker v1.16.0
- Plugin version: 69+ (commit 825a969+)

## VMs Under Test
- **nginx-ch**: Cloud Hypervisor, IP 192.168.50.220, 2 vCPUs, 512MB
- **nginx-fc**: Firecracker, IP 192.168.50.221, 1 vCPU, 256MB

## Results

| # | Test | CH | FC | Notes |
|---|------|:--:|:--:|-------|
| 1 | VM Running | ✅ | ✅ | Both serving nginx |
| 2 | HTTP Response | ✅ | ✅ | curl returns HTML |
| 3 | Live Resize | ✅ | N/A | 1→2 CPUs, 256→512MB confirmed |
| 4 | Snapshot Create | ✅ | ✅ | CH: ch-remote, FC: REST API (HTTP 204) |
| 5 | Snapshot Files | ✅ | ✅ | CH: config+state+memory-ranges, FC: snapshot+mem |
| 6 | Resume after Snap | ✅ | ✅ | Both still serving after snapshot |
| 7 | Console PTY | ✅ | N/A | /dev/pts/2 exists, ttyd works |
| 8 | Log Files | ✅ | ✅ | VMDIR/name/vm.log (73KB CH, 3KB FC) |
| 9 | API Access | ✅ | ✅ | ch-remote ping / curl GET / |
| 10 | Config Files | ✅ | ✅ | engine, autostart, IP correct |
| 11 | Stop (ACPI) | ✅ | N/A | power-button works |
| 12 | Stop (kill) | N/A | ✅ | kill PID works |
| 13 | Context Menu | ✅ | ✅ | Correct items per engine/state |
| 14 | Autostart Switch | ✅ | ✅ | switchButton renders |

## FC Snapshot API Verified
```
Pause:    PATCH /vm {"state":"Paused"}  → HTTP 204
Snapshot: PUT /snapshot/create {...}     → HTTP 204
Resume:   PATCH /vm {"state":"Resumed"} → HTTP 204
Files:    snapshot (13KB) + mem (256MB)
```

## Known Issues (cosmetic)
- Console shows `^[[32;5R` once at shell start (busybox DSR query)
- CH log is verbose (73KB for ~18 hours uptime)
- FC log doesn't show guest output (only hypervisor messages)
