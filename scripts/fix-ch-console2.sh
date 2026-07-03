#!/bin/bash
# Fix CH console - add profile to disable cursor position reports
/etc/rc.d/rc.microvm stop_vm nginx-ch
sleep 4
mkdir -p /tmp/fixch
mount /mnt/mtier/microvms/nginx-ch/rootfs.raw /tmp/fixch

# Create a profile that sets TERM and disables cursor queries
cat > /tmp/fixch/etc/profile.d/serial.sh << 'EOF'
export TERM=linux
export PS1='/ # '
stty -echo 2>/dev/null
stty echo 2>/dev/null
EOF
chmod +x /tmp/fixch/etc/profile.d/serial.sh

# Also update init to not use setsid (which causes tty issues)
sed -i 's|TERM=linux setsid sh -c "stty sane; exec sh -l"|TERM=linux sh -l|' /tmp/fixch/init
grep ttyS0 /tmp/fixch/init

umount /tmp/fixch
rmdir /tmp/fixch
/etc/rc.d/rc.microvm start_vm nginx-ch
echo "done"
