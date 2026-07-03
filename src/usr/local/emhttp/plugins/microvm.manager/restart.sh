#!/bin/bash
# restart.sh - Restart MicroVM service (called from WebGUI via update.php)
/etc/rc.d/rc.microvm stop 2>/dev/null
sleep 2
/etc/rc.d/rc.microvm start
