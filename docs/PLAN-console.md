# Console Architecture - Research & Recommendation

## Problem Statement

Both CH and FC need interactive serial console via ttyd (web terminal). Current approaches have issues:
- CH: PTY bridge (`exec 3<>PTY; cat <&3 & cat >&3`) produces garbled output
- FC: FIFO approach works but is line-by-line (not character-by-character)

## Root Cause Analysis

### CH Garbled Output

The `cat <&3 & cat >&3` approach fails because:
1. **ttyd allocates its OWN PTY** for its child process
2. We have PTY-in-PTY: `ttyd-PTY → bash → cat → VM-PTY`
3. Both PTY line disciplines process the data (echo, canonical mode, etc.)
4. Terminal escape responses from the VM shell get echoed back incorrectly
5. Column/row negotiation between two independent PTYs causes misalignment

### FC Working Approach

FIFO works because:
1. Clean separation: FIFO (write only) + log file tail (read only)
2. No PTY discipline interference — just raw bytes through pipe
3. But it's line-buffered (`read -r line`) so no character-by-character I/O

## CH Serial Options (from documentation)

```
--serial off|null|pty|tty|file=</path/to/a/file>|socket=</path/to/a/file>
```

**`--serial socket=/path`** — Unix domain socket for serial I/O! This is bidirectional:
- CH listens on the socket
- A client connects and gets raw serial I/O
- No PTY discipline — just raw bytes over a socket
- Perfect for ttyd integration

## Available Tools on Unraid

- ✅ `ttyd` (installed)
- ✅ `cat`, `bash`, `script`
- ✅ `stty`
- ❌ `screen`, `picocom`, `minicom`, `socat` (NOT available)

## Recommended Approach

### CH: Use `--serial socket=` (BEST)

```bash
# Launch CH with socket serial instead of PTY
cloud-hypervisor \
  --serial socket=/tmp/microvms-${name}.serial.sock \
  --console off \
  ...

# ttyd connects via a simple bridge script:
# microvms-console-socket /tmp/microvms-${name}.serial.sock
```

The bridge script for socket:
```bash
#!/bin/bash
# Connect to CH serial socket bidirectionally
SOCK=$1
# Use bash's built-in /dev/tcp equivalent for unix sockets...
# Actually need to use: exec 3<>/dev/tcp/... NO - that's TCP only

# Alternative: use `nc -U` (netcat with unix socket)
exec nc -U "$SOCK"
```

Wait — need to check if `nc` on Unraid supports Unix sockets (`-U`).

If not, alternative: **use the same FIFO+log pattern** but write to the socket for input:
```bash
# For output: capture from serial socket to log file
nc -U /tmp/microvms-${name}.serial.sock >> serial.log &
# For input: write to socket
echo "command" | nc -U /tmp/microvms-${name}.serial.sock
```

### FC: Keep FIFO approach (works)

The current FIFO approach is correct. Minor improvements:
1. Already fixed with TERM=dumb (no escape sequences)
2. The "echo" of command text is normal shell behavior (shell echoes what you type)
3. Line-by-line is acceptable for a serial console

### If `nc -U` is NOT available:

Fall back to FIFO approach for BOTH:

**CH with FIFO:**
- Keep `--serial pty` 
- Capture PTY output to serial.log (existing `cat $pty >> serial.log &`)
- For INPUT: write directly to the PTY file descriptor
- For OUTPUT: tail the serial.log
- Same pattern as FC — proven to work

## Decision Matrix

| Approach | CH | FC | Needs |
|----------|----|----|-------|
| `--serial socket` + `nc -U` | ✅ Best | N/A | nc with -U flag |
| FIFO (write) + log tail (read) | ✅ Works | ✅ Works | Nothing extra |
| PTY bridge (cat <&3 & cat >&3) | ❌ Garbled | N/A | — |
| screen/picocom | N/A | N/A | Not installed |

## Init Process Architecture (Research)

### How production systems handle PID 1:

**The problem:** Most OCI/Docker images do NOT have tini/dumb-init inside. Only a few (Node official, Jenkins, Redmine) ship with it pre-installed. The app (nginx, python, node) is NOT designed to be PID 1:
- PID 1 must reap zombie child processes (call `wait()`)
- PID 1 must forward signals (SIGTERM → graceful shutdown)
- Shell scripts (`/bin/sh`) don't do either of these
- If PID 1 exits → kernel panic in a VM

**How Docker solves it:** `docker run --init` injects tini FROM THE HOST at runtime. The binary lives at `/usr/libexec/docker/docker-init` on the host. Docker mounts it into the container transparently. The image doesn't need to contain it.

**How Fly.io solves it:** Injects a compiled init binary (closed source) into every Machine. It:
- Runs ENTRYPOINT+CMD as a child process
- Reaps zombies
- Forwards signals
- Captures ALL stdout and redirects to logging socket
- When app exits → Machine is killed

**How Kubernetes solves it:** Doesn't (by default). Users must bake tini into their Dockerfile or use shareProcessNamespace + pause container.

### What tini does (150 lines of C, ~30KB static binary):
1. Spawns the app as PID 2 (child)
2. Forwards SIGTERM/SIGINT/etc to child
3. Reaps any zombie processes
4. Exits with child's exit code when child dies

### Our approach (recommended):

**Inject a static `tini` binary into the rootfs at create time**, then our `/init` script uses it:

```sh
#!/bin/sh
# Mount filesystems, configure network...
# Then exec through tini for proper PID 1 behavior:
exec /sbin/tini -- /docker-entrypoint.sh nginx -g 'daemon off;'
```

This gives us:
- Signal forwarding (SIGTERM → nginx graceful stop)
- Zombie reaping
- Clean exit codes
- Works with ANY image (tini is host-provided, not image-provided)

### Implementation plan:
1. Download static `tini` binary (~30KB, x86_64) in PLG install
2. During rootfs create: copy `/usr/local/bin/tini` → `/sbin/tini` inside rootfs
3. `/init` does setup then `exec /sbin/tini -- $ENTRYPOINT $CMD`
4. Console mode: `/init` does setup, then `exec /sbin/tini -- /bin/sh` (shell as the managed process)
5. If console + app: `/init` starts app via tini, spawns shell separately on ttyS0

### What this fixes:
- Graceful shutdown (SIGTERM → app gets it → clean exit)
- No zombie accumulation
- No kernel panic on app exit (tini exits cleanly → init exits → VM stops)
- Works with ALL images regardless of whether they include tini

### Architecture (same for both VMMs):
```
ttyd → microvms-console-fc <name>
         │
         ├── OUTPUT: tail -n 0 -f /var/log/microvms/{vmm}/{name}.serial.log
         │                     (CH: captured from PTY by background cat)
         │                     (FC: FC stdout goes directly to log)
         │
         └── INPUT:  printf '%s\n' "$line" > /tmp/microvms-{name}.fifo
                     (CH: FIFO → cat → PTY write = goes to VM serial stdin)
                     (FC: FIFO → tail -f → FC stdin = goes to VM serial stdin)
```

### CH Changes:
1. Keep `--serial pty` (PTY capture for serial.log works)
2. Add a FIFO for console input: `mkfifo /tmp/microvms-{name}.fifo`
3. Bridge FIFO to PTY: `tail -f /tmp/microvms-{name}.fifo > $pty_path &`
4. Console script: same as FC (tail log + write to FIFO)

### FC Changes:
None — already working.

### Why NOT `--serial socket=`:
- Would work perfectly BUT no `nc`/`socat`/`ncat` on Unraid to connect
- No compiler to build a bridge binary
- `curl --unix-socket` can't do bidirectional raw streaming
- Could download a static `socat` binary but adds complexity

### Why FIFO+log works:
- No PTY-in-PTY line discipline conflict
- Clean separation of input (FIFO) and output (log file)
- Proven working on FC already
- Same console script for both VMMs (code reuse)


### Fly.io init-snapshot (reference implementation)
- Repo: https://github.com/superfly/init-snapshot (303 stars, public)
- **Rust-based**, compiled with musl (static binary)
- Reads `/fly/run.json` with: ImageConfig (entrypoint/cmd/env/user/workdir), IPConfigs, mounts, hostname, DNS, tty flag
- Has dedicated `pty` module for console access
- Has `api` module (lifecycle/health)
- Powers every Firecracker microVM at Fly.io

### Comparison

| | Our `/init` (shell) | Fly.io (Rust) | catatonit (C) |
|--|---|---|---|
| PID 1 signal/zombie | ❌ | ✅ | ✅ |
| Network/mount setup | ✅ | ✅ | ❌ |
| OCI entrypoint/cmd | ✅ | ✅ | ❌ |
| Console/PTY | Hacky | Proper module | ❌ |
| Size | ~2KB script | ~5MB binary | ~30KB binary |

### Recommended path (phased):
1. **Now**: inject `catatonit` static binary (~30KB) + shell init does setup then `exec catatonit -- $CMD`
2. **Future**: custom Rust init (inspired by Fly's init-snapshot) with integrated network, PTY console, API

