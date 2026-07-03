#!/bin/bash
# Final fix for CH console - keep hostname PS1, suppress cursor report
/etc/rc.d/rc.microvm stop_vm nginx-ch
sleep 4
mkdir -p /tmp/fixch
mount /mnt/mtier/microvms/nginx-ch/rootfs.raw /tmp/fixch

# Remove the profile.d script that changed PS1
rm -f /tmp/fixch/etc/profile.d/serial.sh

# Fix init: use 'sh' without -l to avoid readline DSR query
# And redirect only the initial DSR response away
sed -i 's|while true; do TERM=linux sh -l < /dev/ttyS0 > /dev/ttyS0 2>&1; sleep 1; done|while true; do TERM=linux HOME=/root sh < /dev/ttyS0 > /dev/ttyS0 2>\&1; sleep 1; done|' /tmp/fixch/init

grep ttyS0 /tmp/fixch/init

# Create a simple .profile in /root that just exports PS1 without triggering DSR
mkdir -p /tmp/fixch/root
cat > /tmp/fixch/root/.profile << 'EOF'
export TERM=linux
export HOME=/root
export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
cd ~
EOF

umount /tmp/fixch
rmdir /tmp/fixch
/etc/rc.d/rc.microvm start_vm nginx-ch
sleep 3
curl -s --connect-timeout 3 http://192.168.50.220/ | head -1
echo "done"
