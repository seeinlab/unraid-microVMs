# Research: Production MicroVM State Management

## Date: 2026-07-08

## Fly.io Architecture (confirmed from blog posts)

**Source of truth:** `flyd` daemon per host + `Corrosion` (distributed SQLite via gossip)

```
Per host:
  flyd (daemon) → manages all Machines on that host
       → internal database = source of truth for "what is this machine doing?"
       → talks to Corrosion to replicate state across cluster

Cluster-wide:
  Corrosion (Rust, gossip protocol) → propagates SQLite DB across all hosts
       → tracks VM statuses, health checks, routing info
       → each host has fast local SQLite copy of global state
```

**Key quote:** "flyd maintains an internal database of all machines it manages, and is considered the source of truth when it comes to questions like 'what is this machine doing right now?'"

**Architecture:**
- `flyd` = host-level daemon (like our rc.microvms but persistent)
- Corrosion = cluster-level state sync (gossip, not consensus)
- SQLite = the actual database (local per host, replicated)
- No containerd for state tracking — containerd only for images/snapshots

## Kata Containers / containerd shim-v2

**Source of truth:** containerd's runtime state directory

```
containerd → shim-v2 (containerd-shim-kata-v2) → VM
```

- Shim is a **long-running process** per VM (stays alive while VM runs)
- containerd **re-discovers shims** on restart by scanning state directory
- State stored in: `/run/containerd/io.containerd.runtime.v2.task/{namespace}/{id}/`
- If shim process dies, containerd detects it via the socket disappearing

**Key insight:** containerd tracks tasks (running containers/VMs) through **shim socket files** in a state directory. On restart, it walks the directory and reconnects to surviving shims.

## Podman (daemonless)

**Source of truth:** `conmon` monitor process + state files on disk

```
podman run → forks conmon → conmon forks container process
                          → conmon stays alive, monitors container
                          → writes state to /run/user/{uid}/containers/
```

- **conmon** = "container monitor" — tiny C process that outlives `podman` CLI
- State files: `/var/lib/containers/storage/` (persistent) + `/run/` (runtime)
- `podman ps` reads state files + checks if conmon/container PIDs are alive
- **BoltDB** (`/var/lib/containers/storage/libpod/bolt_state.db`) = persistent state
- Orphan detection: if PID in state file is dead → container is "exited"

## AWS Lambda / Firecracker

**Source of truth:** Placement service (centralized database, likely DynamoDB)

- Each worker host has a "worker agent" that reports to the placement service
- Firecracker VMs are managed by the worker agent (not containerd directly)
- `firecracker-containerd` exists but is for image/snapshot management, not VM lifecycle
- State is tracked centrally — worker agent heartbeats to the control plane

## Summary: Patterns

| Platform | State Source | Daemon? | Multi-host? |
|----------|-------------|---------|-------------|
| Fly.io | `flyd` daemon + SQLite DB | Yes (per host) | Yes (Corrosion gossip) |
| Kata/containerd | Shim socket files + containerd DB | Yes (containerd) | No (single host) |
| Podman | conmon + BoltDB state files | No (conmon per container) | No (single host) |
| AWS Lambda | Central placement DB | Yes (worker agent) | Yes (centralized) |
| Docker | dockerd daemon + BoltDB | Yes | No (single host) |

## Patterns that scale from single-host to multi-host:

1. **Fly.io pattern:** Local daemon with SQLite → gossip sync for multi-host
   - Start: just the local daemon (single host works alone)
   - Scale: add Corrosion gossip for multi-host sync

2. **containerd shim pattern:** State directory with socket files
   - Survives daemon restart (re-discovers shims)
   - Single host only, but integrates with K8s for multi-host

## Recommendation for microVMs plugin

**Phase 1 (current — single host):**
- Use **containerd** for images + snapshots (already done)
- Use **state files** (`/var/run/microvms/{name}.state`) for VM lifecycle
- Process scan for running detection (fast, accurate)
- JSON config on array for persistent definition

**Phase 2 (future — multi-host):**
- Add a lightweight state DB (SQLite, like Fly.io's `flyd`)
- State sync via gossip or simple HTTP API between hosts
- Each host runs its own microvms service (like `flyd`)

**Phase 3 (future — Kubernetes):**
- Expose state via gRPC (flintlockd or custom)
- K8s operator reads state, schedules VMs

**The key insight from Fly.io:** Start with a **local daemon + local DB** (even just SQLite or state files). It works for single host AND is the foundation for multi-host when you add gossip sync later.
