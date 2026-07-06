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

## Final Recommendation

**Use FIFO+log pattern for BOTH CH and FC** (unified approach):

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
