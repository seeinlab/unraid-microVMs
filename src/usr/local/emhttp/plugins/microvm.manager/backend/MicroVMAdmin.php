<?php
// backend/MicroVMAdmin.php - AJAX command handler
error_reporting(0); // Suppress warnings from mixing with JSON output

$plugin = "microvm.manager";
$docroot = $docroot ?? $_SERVER['DOCUMENT_ROOT'] ?: '/usr/local/emhttp';
require_once "$docroot/plugins/$plugin/include/common.php";

header('Content-Type: application/json');

// Log function
function microvm_log($msg) {
    $logfile = '/var/log/microvm-manager.log';
    $ts = date('Y-m-d H:i:s');
    file_put_contents($logfile, "[$ts] $msg\n", FILE_APPEND);
}

$cfg = microvm_load_config();
$vmdir = $cfg['VMDIR'] ?? '/mnt/user/appdata/microvm';
$bridge = $cfg['BRIDGE'] ?? 'br0';

$cmd = $_POST['cmd'] ?? '';
$name = $_POST['name'] ?? '';

switch ($cmd) {
    case 'list':
        echo json_encode(microvm_list_vms());
        break;

    case 'start':
        $result = microvm_start_vm($name);
        echo json_encode($result);
        break;

    case 'stop':
        $result = microvm_stop_vm($name);
        echo json_encode($result);
        break;

    case 'info':
        $info = microvm_get_vm_info($name);
        if ($info) {
            echo json_encode($info);
        } else {
            // Return the config file content
            $configFile = "$vmdir/$name/config.json";
            if (file_exists($configFile)) {
                echo file_get_contents($configFile);
            } else {
                echo json_encode(['error' => 'VM not found']);
            }
        }
        break;

    case 'resize':
        $cpus = $_POST['cpus'] ?? null;
        $memory = $_POST['memory'] ?? null;
        $result = microvm_resize_vm($name, $cpus, $memory);
        echo json_encode($result);
        break;

    case 'snapshot':
        $result = microvm_snapshot_vm($name);
        echo json_encode($result);
        break;

    case 'delete':
        // Stop VM if running
        microvm_stop_vm($name);
        sleep(2);
        // Remove config (keep rootfs)
        $configFile = "$vmdir/$name/config.json";
        if (file_exists($configFile)) {
            unlink($configFile);
            echo json_encode(['success' => true, 'message' => "Config deleted. Rootfs preserved at $vmdir/$name/"]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Config not found']);
        }
        break;

    case 'create':
        microvm_log("CREATE: " . json_encode($_POST));
        $name = preg_replace('/[^a-z0-9\-]/', '', strtolower($_POST['name'] ?? ''));
        if (empty($name)) {
            echo json_encode(['success' => false, 'error' => 'Invalid name']);
            break;
        }

        $cpus = intval($_POST['cpus'] ?? $cfg['DEFAULT_CPUS'] ?? 1);
        $memory = intval($_POST['memory'] ?? $cfg['DEFAULT_MEMORY'] ?? 256);
        $ip = $_POST['ip'] ?? '';
        $gateway = $_POST['gateway'] ?? '192.168.50.1';
        $source = $_POST['source'] ?? 'oci';
        $ociImage = $_POST['oci_image'] ?? 'nginx:alpine';
        $diskSize = intval($_POST['disk_size'] ?? 500);
        $rootfsPath = $_POST['rootfs_path'] ?? '';

        $vmPath = "$vmdir/$name";
        $rootfs = "$vmPath/rootfs.raw";
        $kernel = "$vmdir/kernels/vmlinux";

        // Create VM directory
        @mkdir($vmPath, 0755, true);

        // Generate MAC
        $mac = sprintf("52:54:00:%02x:%02x:%02x", rand(0,255), rand(0,255), rand(0,255));

        // Create rootfs
        if ($source === 'oci' && !empty($ociImage)) {
            // Pull OCI image and create rootfs
            microvm_log("Pulling OCI: $ociImage");
            $tmpTar = "/tmp/microvm-$name.tar";
            exec("crane export " . escapeshellarg($ociImage) . " " . escapeshellarg($tmpTar) . " 2>&1", $pullOutput, $pullRet);
            microvm_log("crane exit: $pullRet, output: " . implode(" ", $pullOutput));
            if ($pullRet !== 0) {
                echo json_encode(['success' => false, 'error' => 'Failed to pull image: ' . implode("\n", $pullOutput)]);
                break;
            }

            // Create ext4 image
            exec("dd if=/dev/zero of=$rootfs bs=1M count=$diskSize 2>/dev/null");
            exec("mkfs.ext4 -F $rootfs 2>/dev/null");
            exec("mkdir -p /tmp/microvm-mount-$name");
            exec("mount $rootfs /tmp/microvm-mount-$name");
            exec("tar -xf $tmpTar -C /tmp/microvm-mount-$name 2>&1");

            // Inject init script
            $initScript = <<<'INIT'
#!/bin/sh
mount -t proc proc /proc
mount -t sysfs sysfs /sys
mount -t devtmpfs devtmpfs /dev 2>/dev/null
mkdir -p /dev/pts && mount -t devpts devpts /dev/pts
for d in /sys/class/net/*; do n=$(basename $d); [ "$n" != "lo" ] && IFACE=$n && break; done
ip link set $IFACE up 2>/dev/null
ip link set lo up
mkdir -p /run/nginx /var/log/nginx /var/lib/nginx/tmp
echo "nameserver 8.8.8.8" > /etc/resolv.conf
if [ -f /usr/sbin/nginx ]; then
  nginx -g 'daemon off;' &
elif [ -f /usr/bin/httpd ] || command -v httpd > /dev/null; then
  mkdir -p /var/www/html
  echo "<h1>MicroVM: HOSTNAME</h1>" > /var/www/html/index.html
  httpd -p 80 -h /var/www/html
else
  mkdir -p /var/www/html
  echo "<h1>MicroVM: HOSTNAME</h1>" > /var/www/html/index.html
  while true; do echo -e "HTTP/1.1 200 OK\r\nContent-Type: text/html\r\n\r\n$(cat /var/www/html/index.html)" | nc -l -p 80 -q1; done &
fi
trap 'kill $(jobs -p) 2>/dev/null; poweroff -f' TERM INT
echo "=== MicroVM Ready ==="
while true; do sleep 3600 & wait; done
INIT;
            $initScript = str_replace('HOSTNAME', $name, $initScript);
            file_put_contents("/tmp/microvm-mount-$name/init", $initScript);
            chmod("/tmp/microvm-mount-$name/init", 0755);

            exec("umount /tmp/microvm-mount-$name");
            exec("rmdir /tmp/microvm-mount-$name");
            @unlink($tmpTar);

        } elseif ($source === 'existing' && !empty($rootfsPath)) {
            $rootfs = $rootfsPath;
        }

        // Create config.json
        $config = [
            'name' => $name,
            'kernel' => $kernel,
            'disk' => $rootfs,
            'cmdline' => "console=hvc0 root=/dev/vda rw init=/init ip=$ip::$gateway:255.255.255.0:::off",
            'boot_vcpus' => $cpus,
            'max_vcpus' => $cpus * 2,
            'memory_mb' => $memory,
            'mac' => $mac,
            'ip' => $ip,
            'bridge' => $bridge,
            'autostart' => false,
        ];
        file_put_contents("$vmPath/config.json", json_encode($config, JSON_PRETTY_PRINT));

        microvm_log("VM created: $name at $vmPath");
        echo json_encode(['success' => true, 'message' => "VM '$name' created. Click Start to boot."]);
        break;

    case 'create_json':
        $json = $_POST['config'] ?? '';
        $config = json_decode($json, true);
        if (!$config || empty($config['name'])) {
            echo json_encode(['success' => false, 'error' => 'Invalid JSON or missing name']);
            break;
        }
        $name = preg_replace('/[^a-z0-9\-]/', '', strtolower($config['name']));
        $vmPath = "$vmdir/$name";
        @mkdir($vmPath, 0755, true);
        file_put_contents("$vmPath/config.json", json_encode($config, JSON_PRETTY_PRINT));
        echo json_encode(['success' => true, 'message' => "VM '$name' created from JSON."]);
        break;

    case 'service':
        $action = $_POST['action'] ?? '';
        if (in_array($action, ['start', 'stop', 'restart'])) {
            exec("/etc/rc.d/rc.microvm $action 2>&1", $output, $ret);
            echo json_encode(['success' => ($ret === 0), 'message' => implode("\n", $output)]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
        }
        break;

    default:
        echo json_encode(['error' => "Unknown command: $cmd"]);
}
