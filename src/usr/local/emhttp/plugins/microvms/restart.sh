#!/bin/bash
# restart.sh - Restart microVM service (called from WebGUI via update.php)
/etc/rc.d/rc.microvms stop 2>/dev/null
sleep 2
/etc/rc.d/rc.microvms start
