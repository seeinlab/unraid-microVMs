# Console Architecture — Final Implementation

## Status: ✅ Complete (v0.1)

## How It Works

### Cloud Hypervisor
```
Input:  console_input PHP → printf > /dev/pts/N (direct PTY write)
Output: cat /dev/pts/N >> {name}.serial.log (background process)
UI:     polls console_output → tail -100 {name}.serial.log (every 2s)
```
- CH started with `--serial pty --console off`
- PTY path: from CH log on normal start, `ch-remote info` after restore
- No FIFO needed for input (PTY is bidirectional)

### Firecracker
```
Input:  console_input PHP → printf > /tmp/microvms-{name}.fifo
Output: FC stdout piped to /var/log/microvms/firecracker/{name}.log
UI:     polls console_output → tail -100 {name}.log (every 2s)
```
- FC started with `(tail -f $fifo | firecracker --api-sock ...) >> .log`
- FIFO created before FC start (persistent input pipe)

### UI (swal popup)
- Output box: monospace, polls every 2s
- Input field: textarea + Send button
- Scroll: only auto-scrolls on open and after Send (free scroll otherwise)

## Init Architecture
```
Kernel → /fly/init (shell script)
  1. Mount /proc /sys /dev /dev/pts
  2. Read /fly/run.json (entrypoint, cmd, hostname, dns, console)
  3. Set hostname, DNS, env
  4. Generate /fly/run.sh
  5. If console=true:
       /sbin/catatonit -- /fly/run.sh &  (app in background)
       shell on /dev/ttyS0               (interactive console)
     Else:
       exec /sbin/catatonit -- /fly/run.sh  (PID 1)
```

## Key Decisions
- **Direct PTY write** (not FIFO→PTY): `tail -f FIFO > PTY` doesn't work reliably on named pipes
- **catatonit** as PID 1 reaper: zombie reaping + signal forwarding, 30KB static binary
- **No ttyd**: replaced with swal popup + AJAX polling (simpler, no extra process)
- **No `--serial socket`**: would be ideal but no `nc -U`/`socat` on Unraid
- **Kernel `ip=`**: network configured by kernel, no iproute2 needed in guest
