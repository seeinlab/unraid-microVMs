#!/bin/bash
/etc/rc.d/rc.microvm stop_vm nginx-ch
sleep 4
mkdir -p /tmp/fix
mount /mnt/mtier/microvms/nginx-ch/rootfs.raw /tmp/fix
# Fix: add TERM=linux and stty sane before spawning shell
sed -i 's|setsid sh -l < /dev/ttyS0 > /dev/ttyS0 2>&1|TERM=linux setsid sh -c "stty sane; exec sh -l" < /dev/ttyS0 > /dev/ttyS0 2>\&1|' /tmp/fix/init
grep ttyS0 /tmp/fix/init
umount /tmp/fix
rmdir /tmp/fix
/etc/rc.d/rc.microvm start_vm nginx-ch
