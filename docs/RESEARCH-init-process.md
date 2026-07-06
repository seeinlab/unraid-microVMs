# Init Process Research Notes

## Date: 2026-07-06

## Summary

Research into how production microVM platforms handle PID 1 init, signal handling, and container process management inside Firecracker/Cloud Hypervisor VMs.

---

## Fly.io (superfly)

### Source: https://github.com/superfly/init-snapshot (303 stars)

**Architecture:**
- Custom **Rust init binary**, compiled with musl (static)
- Injected at **runtime** into every Firecracker microVM (image untouched in registry)
- Always PID 1 — **cannot be disabled or overridden**
- Configured via `/fly/run.json` injected alongside the init

**Responsibilities:**
- Zombie process reaping
- Signal forwarding (SIGTERM → app graceful shutdown)
- Network setup (IP, gateway from run.json)
- Mount management
- Clean shutdown coordination (volumes, workloads)
- PTY module for console access
- API module for lifecycle/health

**run.json schema (from lib.rs):**
```rust
struct RunConfig {
    image_config: Option<ImageConfig>,   // entrypoint, cmd, env, working_dir, user
    exec_override: Option<Vec<String>>,
    extra_env: Option<HashMap<String, String>>,
    ip_configs: Option<Vec<IPConfig>>,   // gateway, ip, mask
    tty: bool,
    hostname: String,
    mounts: Option<Vec<Mount>>,
    root_device: Option<String>,
    etc_resolv: Option<EtcResolv>,       // nameservers
    etc_hosts: Option<Vec<EtcHost>>,
}
```

**Key design decisions (from community discussion):**
- Init is injected, NOT part of the image
- s6-overlay not supported (use `unshare` hack if needed)
- When app exits → Machine is killed
- Multi-container support (rate-limiter-demo): multiple containers per VM with dependencies and health checks

### Source: https://github.com/fly-apps/rate-limiter-demo

Shows Fly supports **multi-container per machine** — nginx + app with:
- Container dependencies (`depends_on`)
- Health checks (`wget` to verify readiness)
- File injection (`raw_value` base64-encoded configs)
- Shared networking within the VM

---

## AWS Lambda MicroVMs

### Source: https://docs.aws.amazon.com/lambda/latest/dg/lambda-microvms-guide.html

**Architecture:**
- Runs Dockerfile → starts app → captures **memory snapshot** of fully initialized state
- Subsequent starts resume from snapshot (sub-second)
- Powered by Firecracker

**Key pattern:**
- No traditional init — the snapshot IS the running state
- App must be fully ready before snapshot is taken
- Suspend/resume preserves memory + disk state

---

## Microsoft Hyperlight

### Source: https://opensource.microsoft.com/blog/2025/02/11/hyperlight-creating-a-0-0009-second-micro-vm-execution-time

**Architecture:**
- NOT a traditional microVM — executes untrusted code in micro-VMs without a full OS
- 0.9ms execution time
- No init process needed (no OS)
- Rust library, uses KVM/Hyper-V directly

**Relevance:** Different approach entirely — function-level isolation, not container-level.

---

## Container Init Systems Comparison

| Init | Last Release | Maintained | Used By | Approach |
|------|-------------|-----------|---------|----------|
| **tini** | 2020 (v0.19.0) | Done/stable | Docker `--init` (bundled) | `sigwait(2)` |
| **dumb-init** | 2022 (v1.2.5) | Done/stable | Yelp | Process group forwarding |
| **catatonit** | **Dec 2024 (v0.2.1)** | **Active (openSUSE)** | **Podman, CRI-O** | `signalfd(2)` (modern) |
| Docker `--init` | Always current | Yes (ships tini) | Docker Engine | Host-injected tini |
| Fly init | Active | Yes (closed source) | Fly.io | Custom Rust binary |

### Why catatonit over tini/dumb-init:
1. **Actively maintained** (Dec 2024 release)
2. **Modern Linux API** — uses `signalfd(2)` instead of `sigwait(2)`
3. **Used by Podman/CRI-O** — Red Hat's container ecosystem
4. **GPL-2.0** — compatible with our plugin license
5. **Static binary available** — direct download from GitHub releases
6. **Fixes signal handling bugs** that exist in tini/dumb-init

---

## PID 1 Responsibilities (why shell scripts are terrible PID 1)

1. **Signal forwarding** — PID 1 must forward SIGTERM/SIGINT to children. Shell (`sh`) ignores signals by default.
2. **Zombie reaping** — PID 1 must call `wait()` on orphaned children. Shell scripts don't do this.
3. **Exit handling** — If PID 1 exits → kernel panic in a VM. Shell scripts can easily exit on error.
4. **Default signal disposition** — Kernel gives PID 1 special treatment: signals are ignored unless explicitly handled.

**Impact without proper init:**
- `docker stop` / VM shutdown → SIGTERM never reaches app → forced SIGKILL after timeout
- Zombie processes accumulate (PID exhaustion over time)
- Kernel panic if init script crashes

---

## Our Design Decision

### Pattern: Fly.io-inspired, catatonit-based

```
Guest rootfs (injected at create time):
├── /fly/init          ← Generic shell script (setup: mount, network, dns)
├── /fly/run.json      ← Per-VM config (entrypoint, cmd, network, console)
├── /sbin/catatonit    ← PID 1 duties (signal forward, zombie reap)
└── /init → /fly/init  ← Symlink for kernel
```

**Flow:**
1. Kernel boots → runs `/fly/init`
2. `/fly/init` mounts proc/sys/dev, reads `/fly/run.json`
3. Configures network (IP, gateway, DNS from JSON)
4. Execs through catatonit: `exec /sbin/catatonit -- $ENTRYPOINT $CMD`
5. catatonit is now PID 1, app is PID 2
6. Signals flow: VMM → kernel → catatonit (PID 1) → app (PID 2)
7. App exits → catatonit exits → VM stops

**Console mode:**
1. Same setup as above
2. App runs via catatonit in background
3. Shell spawns on `/dev/ttyS0` for interactive access
4. Init script (PID 1) waits forever (prevents kernel panic)

### Why NOT:
- ❌ s6-overlay — complex, expects to be PID 1, conflicts with our init
- ❌ systemd — massive, inappropriate for microVMs
- ❌ Custom Rust init (now) — over-engineering for current stage
- ❌ No init (raw shell) — kernel panics, no signal handling, zombies

### Future:
- Multi-container support (Fly's rate-limiter-demo pattern)
- Custom Rust init (when shell script limits are hit)
- Health checks integration
- Suspend/resume (Lambda pattern)

---

## Key URLs

- Fly init source: https://github.com/superfly/init-snapshot
- Fly multi-container: https://github.com/fly-apps/rate-limiter-demo
- catatonit: https://github.com/openSUSE/catatonit
- Lambda MicroVMs: https://docs.aws.amazon.com/lambda/latest/dg/lambda-microvms-guide.html
- PID 1 problem: https://sumguy.com/tini-vs-dumb-init-vs-docker-init
- Fly init discussion: https://community.fly.io/t/fly-io-init-override-documentation/26020
