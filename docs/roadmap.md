# Roadmap

## Current (Working)
- [x] Settings page with health checks (Ready/Failed)
- [x] Create microVM from OCI image (crane pull → rootfs)
- [x] Start/Stop VMs from WebGUI
- [x] VM list with state display
- [x] Live resize (CPU/RAM)
- [x] Snapshot
- [x] RootFS tab (list images, pull OCI)
- [x] Usage Statistics tab
- [x] Path resolution (/mnt/user → actual tier)
- [x] JSON config persistence
- [x] TAP networking on br0

## Next
- [ ] Serial console access (WebSocket → serial TTY)
- [ ] Edit rootfs (mount image, modify files, unmount)
- [ ] Custom init script injection at create time
- [ ] Nchan real-time VM status updates (no page refresh needed)
- [ ] Firecracker engine support (alternative backend)
- [ ] VM log viewer in WebGUI
- [ ] Proper PLG installer for Community Applications
- [ ] cloud-init / metadata support for VM provisioning
- [ ] Multiple network interfaces / custom bridge per VM
- [ ] Disk resize (grow rootfs.raw)
- [ ] Import/export VM (tar rootfs + config)
- [ ] flintlockd + containerd integration (advanced mode)
- [ ] Device-mapper thin pool for instant cloning

## Serial Console Plan
Cloud Hypervisor supports `--serial pty` which creates a PTY device.
Or `--console pty` for virtio console.

Approach:
1. Start VM with `--serial pty` → CH logs the PTY path
2. Backend PHP opens PTY via `proc_open` or websocket relay
3. WebGUI connects via xterm.js (same lib noVNC uses)
4. User gets interactive shell inside the microVM

Alternative: vsock (virtio socket) for structured communication without network.
