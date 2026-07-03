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

## Next (Priority - UI Parity with VMs/Docker tabs)
- [ ] **Context menu on VM name click** (not hamburger) — like VMs/Docker pattern:
  - Running: Serial Console, Stop, Pause, Restart, Force Stop, Snapshot, Logs, Edit
  - Stopped: Start, Logs, Edit, Remove
- [ ] **Rounded logo** for Cloud Hypervisor (CSS border-radius:50% or find transparent round logo)
- [ ] **Additional columns**: TAP interface (tap-name), Snapshots count, Autostart toggle
- [ ] **Snapshot management**: list snapshots, restore, delete
- [ ] **Convert engine**: migrate config between CH and FC formats
- [ ] **Logo opacity**: stopped VMs = 0.3 opacity (like VMs page)
- [ ] **Bottom buttons row**: ADD MICROVM | START ALL | STOP ALL (like Docker's button bar)
- [ ] **Autostart toggle** per VM (ON/OFF switch in row)
- [ ] Firecracker engine backend fully working (start via --config-file ✅, stop via kill)
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
