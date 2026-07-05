#!/bin/bash
# start.sh - Called by Unraid on array start (or Settings Apply)
# Ensures array is online before starting services that need /mnt/user/

# Check if array is started (FUSE user shares mounted)
if [ ! -d /mnt/user ]; then
  echo "Array not started yet, skipping microVMs start"
  exit 0
fi

/etc/rc.d/rc.microvms start
