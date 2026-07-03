#!/bin/bash
# Delayed restart to avoid blocking the WebGUI form submission
logger -t "microvm.manager" "Restarting MicroVM Manager in 3 seconds"
echo "sleep 3 ; /etc/rc.d/rc.microvm restart" | at now 2>/dev/null
