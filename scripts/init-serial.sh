#!/bin/sh
mount -t proc proc /proc
mount -t sysfs sysfs /sys
mount -t devtmpfs devtmpfs /dev 2>/dev/null
mkdir -p /dev/pts && mount -t devpts devpts /dev/pts
for d in /sys/class/net/*; do n=$(basename $d); [ "$n" != "lo" ] && IFACE=$n && break; done
ip link set $IFACE up 2>/dev/null
ip link set lo up
mkdir -p /run/nginx /var/log/nginx /var/lib/nginx/tmp /tmp
echo "nameserver 8.8.8.8" > /etc/resolv.conf
if [ -f /usr/sbin/nginx ]; then
  chown -R root:root /var/log/nginx /run/nginx 2>/dev/null
  nginx -g 'daemon off;' 2>/tmp/nginx-err.log &
  NGINX_PID=$!
  sleep 2
  if ! kill -0 $NGINX_PID 2>/dev/null; then
    echo "events{} http{server{listen 80;location / {root /usr/share/nginx/html;index index.html;}}}" > /tmp/nginx-min.conf
    nginx -c /tmp/nginx-min.conf -g 'daemon off;' &
  fi
elif command -v httpd > /dev/null; then
  mkdir -p /var/www/html
  echo "<h1>MicroVM: $(hostname)</h1>" > /var/www/html/index.html
  httpd -p 80 -h /var/www/html
else
  mkdir -p /var/www/html
  echo "<h1>MicroVM: $(hostname)</h1>" > /var/www/html/index.html
  while true; do echo -e "HTTP/1.1 200 OK\r\nContent-Type: text/html\r\n\r\n$(cat /var/www/html/index.html)" | nc -l -p 80 -q1; done &
fi

# Start serial console shell on ttyS0 (for Cloud Hypervisor --serial pty)
echo "=== MicroVM Ready ==="
echo "=== Serial console active on /dev/ttyS0 ==="
if [ -c /dev/ttyS0 ]; then
  # Launch shell on serial port for interactive access
  while true; do
    setsid sh -l < /dev/ttyS0 > /dev/ttyS0 2>&1
    sleep 1
  done &
fi

trap 'kill $(jobs -p) 2>/dev/null; poweroff -f' TERM INT
while true; do sleep 3600 & wait; done
