# Networking

## Native Unraid (TAP on br0)

```bash
# Create TAP and attach to Unraid's bridge
ip tuntap add tap-vmname mode tap
ip link set tap-vmname master br0
ip link set tap-vmname up
```

- VM gets a LAN IP (e.g., 192.168.50.200)
- Reachable from any device on the subnet
- Same mechanism as libvirt/QEMU VMs on Unraid
- TAP is ephemeral (lost on reboot, recreated by rc script)
- TAP persists across VM shutdown/restart (only lost on host reboot)
- No iptables/NAT needed — direct bridge access

## Kernel cmdline for networking

```
ip=CLIENT_IP::GATEWAY:NETMASK:::off
```
Example: `ip=192.168.50.200::192.168.50.1:255.255.255.0:::off`

- Leave device field (6th) empty — kernel auto-detects
- `off` = no DHCP, static config only

## Init script network setup (inside VM)

```sh
# Auto-detect interface (works for both FC's eth0 and CH's enp0s2)
IFACE=""
for d in /sys/class/net/*; do
  name=$(basename $d)
  [ "$name" != "lo" ] && IFACE=$name && break
done

# Configure from kernel ip= param (already done by kernel, but for safety)
ip link set $IFACE up
ip link set lo up
```

## Docker Container Networking (without --network=host)

### Option A: Bridge + NAT (portable)
Container gets Docker IP, internal bridge for VMs, NAT outbound.

### Option B: macvlan/ipvlan (LAN-visible)  
Container gets real LAN IP, VMs NAT through it.

### Option C: tc-redirect-tap (Kata pattern, most elegant)
VM takes over container's network identity. Docker port publishing works transparently.

See `docs/plugin-development.md` for Docker-based deployment details.
