#!/bin/bash
set -e

# Build script for MicroVM Manager Unraid plugin
# Run from the repo root: bash build.sh

VERSION=$(date +'%Y.%m.%d').1
PLUGIN_NAME="microvm.manager"
REPO_DIR="$(cd "$(dirname "$0")" && pwd)"
BUILD_DIR="$REPO_DIR/build"
SRC_DIR="$REPO_DIR/src/usr/local/emhttp/plugins/$PLUGIN_NAME"

echo "=== Building MicroVM Manager Plugin ==="
echo "Version: $VERSION"
echo ""

# Clean build dir
rm -rf "$BUILD_DIR"
mkdir -p "$BUILD_DIR"

# Create the plugin directory structure for packaging
mkdir -p "$BUILD_DIR/$PLUGIN_NAME"

# Copy plugin files (emhttp pages, PHP, etc.)
cp -r "$SRC_DIR/"* "$BUILD_DIR/$PLUGIN_NAME/"

# Copy rc.d script into the package (will be installed by PLG)
mkdir -p "$BUILD_DIR/$PLUGIN_NAME/scripts"
cp "$REPO_DIR/src/usr/local/etc/rc.d/rc.microvm" "$BUILD_DIR/$PLUGIN_NAME/rc.microvm"

# Ensure scripts are executable
chmod +x "$BUILD_DIR/$PLUGIN_NAME/restart.sh"
chmod +x "$BUILD_DIR/$PLUGIN_NAME/rc.microvm"

# Create the .tgz package
cd "$BUILD_DIR"
tar -czf "$REPO_DIR/plugin/$PLUGIN_NAME-$VERSION.tgz" "$PLUGIN_NAME"

echo "Package created: plugin/$PLUGIN_NAME-$VERSION.tgz"
echo "Size: $(du -h "$REPO_DIR/plugin/$PLUGIN_NAME-$VERSION.tgz" | cut -f1)"
echo ""

# Generate PLG file
mkdir -p "$REPO_DIR/plugin"
cat > "$REPO_DIR/plugin/$PLUGIN_NAME.plg" << PLGEOF
<?xml version='1.0' standalone='yes'?>
<!DOCTYPE PLUGIN>
<PLUGIN name="$PLUGIN_NAME" author="seein" version="$VERSION"
  pluginURL="https://raw.githubusercontent.com/seein/unraid-microVMs/main/plugin/$PLUGIN_NAME.plg"
  launch="Settings/MicroVMManager"
  min="6.12.0">

<CHANGES><![CDATA[
### $VERSION
- Initial release
- Cloud Hypervisor v52.0 support
- Create, start, stop, resize, snapshot VMs
- TAP networking on br0 (LAN-accessible VMs)
- Settings page with service management
]]></CHANGES>

<!-- PRE-INSTALL: Stop existing service -->
<FILE Run="/bin/bash">
<INLINE>
<![CDATA[
/etc/rc.d/rc.microvm stop 2>/dev/null
]]>
</INLINE>
</FILE>

<!-- Download Cloud Hypervisor v52.0 static binary -->
<FILE Name="/boot/config/plugins/$PLUGIN_NAME/cloud-hypervisor" Mode="0755">
<URL>https://github.com/cloud-hypervisor/cloud-hypervisor/releases/download/v52.0/cloud-hypervisor-static</URL>
</FILE>

<!-- Download ch-remote v52.0 static binary -->
<FILE Name="/boot/config/plugins/$PLUGIN_NAME/ch-remote" Mode="0755">
<URL>https://github.com/cloud-hypervisor/cloud-hypervisor/releases/download/v52.0/ch-remote-static</URL>
</FILE>

<!-- Download plugin package (for local install, replace URL with file path) -->
<FILE Name="/boot/config/plugins/$PLUGIN_NAME/$PLUGIN_NAME-$VERSION.tgz">
<LOCAL>/boot/config/plugins/$PLUGIN_NAME/$PLUGIN_NAME-$VERSION.tgz</LOCAL>
</FILE>

<!-- INSTALL -->
<FILE Run="/bin/bash">
<INLINE>
<![CDATA[
PLUGIN="$PLUGIN_NAME"
VERSION="$VERSION"

# Remove old plugin files
rm -rf /usr/local/emhttp/plugins/\${PLUGIN}

# Extract plugin package
tar -xf /boot/config/plugins/\${PLUGIN}/\${PLUGIN}-\${VERSION}.tgz -C /usr/local/emhttp/plugins/ 2>/dev/null

# Install binaries to /usr/local/bin
cp /boot/config/plugins/\${PLUGIN}/cloud-hypervisor /usr/local/bin/cloud-hypervisor
cp /boot/config/plugins/\${PLUGIN}/ch-remote /usr/local/bin/ch-remote
chmod +x /usr/local/bin/cloud-hypervisor /usr/local/bin/ch-remote

# Install rc.d service script
cp /usr/local/emhttp/plugins/\${PLUGIN}/rc.microvm /etc/rc.d/rc.microvm
chmod +x /etc/rc.d/rc.microvm

# Make restart.sh executable
chmod +x /usr/local/emhttp/plugins/\${PLUGIN}/restart.sh

# Create default config if not exists
if [ ! -f /boot/config/plugins/\${PLUGIN}/\${PLUGIN}.cfg ]; then
cat > /boot/config/plugins/\${PLUGIN}/\${PLUGIN}.cfg << 'CFGEOF'
SERVICE="enable"
VMDIR="/mnt/user/appdata/microvm"
BRIDGE="br0"
DEFAULT_CPUS="1"
DEFAULT_MEMORY="256"
AUTOSTART="no"
CFGEOF
fi

# Create VM storage directory
mkdir -p /mnt/user/appdata/microvm/kernels 2>/dev/null

# Start service
/usr/local/emhttp/plugins/\${PLUGIN}/restart.sh

echo ""
echo "-----------------------------------------------------------"
echo " MicroVM Manager has been installed."
echo " Cloud Hypervisor: \$(cloud-hypervisor --version 2>/dev/null)"
echo " ch-remote: \$(ch-remote --version 2>/dev/null)"
echo " Version: \${VERSION}"
echo "-----------------------------------------------------------"
echo ""
echo " Go to Settings → MicroVM Manager to configure."
echo " Then go to Tasks → MicroVMs to manage your VMs."
echo "-----------------------------------------------------------"
]]>
</INLINE>
</FILE>

<!-- REMOVE -->
<FILE Run="/bin/bash" Method="remove">
<INLINE>
<![CDATA[
echo "Removing MicroVM Manager..."

# Stop service
/etc/rc.d/rc.microvm stop 2>/dev/null

# Remove binaries
rm -f /usr/local/bin/cloud-hypervisor
rm -f /usr/local/bin/ch-remote

# Remove rc script
rm -f /etc/rc.d/rc.microvm

# Remove plugin files (but keep config and VM data!)
rm -rf /usr/local/emhttp/plugins/$PLUGIN_NAME

# Remove cached binaries from flash (keep config!)
rm -f /boot/config/plugins/$PLUGIN_NAME/cloud-hypervisor
rm -f /boot/config/plugins/$PLUGIN_NAME/ch-remote
rm -f /boot/config/plugins/$PLUGIN_NAME/*.tgz

echo ""
echo "-----------------------------------------------------------"
echo " MicroVM Manager removed."
echo " VM data preserved at: /mnt/user/appdata/microvm/"
echo " Config preserved at: /boot/config/plugins/$PLUGIN_NAME/"
echo " To fully remove: rm -rf /boot/config/plugins/$PLUGIN_NAME"
echo "-----------------------------------------------------------"
]]>
</INLINE>
</FILE>

</PLUGIN>
PLGEOF

echo "PLG created: plugin/$PLUGIN_NAME.plg"
echo ""

# Clean build dir
rm -rf "$BUILD_DIR"

echo "=== Build complete ==="
echo ""
echo "To install on Unraid:"
echo "  1. Copy plugin/$PLUGIN_NAME-$VERSION.tgz to /boot/config/plugins/$PLUGIN_NAME/"
echo "  2. Copy plugin/$PLUGIN_NAME.plg to /boot/config/plugins/"
echo "  3. Or install via: plugin install /path/to/$PLUGIN_NAME.plg"
echo ""
echo "For manual testing (without downloading binaries from GitHub):"
echo "  scp plugin/$PLUGIN_NAME-$VERSION.tgz root@192.168.50.6:/boot/config/plugins/$PLUGIN_NAME/"
echo "  scp plugin/$PLUGIN_NAME.plg root@192.168.50.6:/boot/config/plugins/"
