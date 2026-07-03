import subprocess, os, sys, tempfile, time
from PIL import Image

svg_path = r'C:\Users\seein\Workspaces\github\unraid-microVMs\src\usr\local\emhttp\plugins\microvm.manager\microvm.manager.svg'
png_path = r'C:\Users\seein\Workspaces\github\unraid-microVMs\src\usr\local\emhttp\plugins\microvm.manager\microvm.manager.png'

# Create HTML that renders SVG on transparent background  
html_content = """<!DOCTYPE html>
<html>
<head><style>
html, body { margin:0; padding:0; background:transparent; width:128px; height:154px; overflow:hidden; }
img { display:block; width:128px; height:auto; }
</style></head>
<body>
<img src="SVGPATH">
</body>
</html>""".replace("SVGPATH", "file:///" + svg_path.replace("\\", "/"))

html_path = os.path.join(tempfile.gettempdir(), "svg_render.html")
with open(html_path, "w") as f:
    f.write(html_content)

# Find Chrome or Edge
chrome_paths = [
    r"C:\Program Files\Google\Chrome\Application\chrome.exe",
    r"C:\Program Files (x86)\Google\Chrome\Application\chrome.exe",
    r"C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe",
    r"C:\Program Files\Microsoft\Edge\Application\msedge.exe",
]
chrome = None
for p in chrome_paths:
    if os.path.exists(p):
        chrome = p
        break

if not chrome:
    print("ERROR: No Chrome/Edge found, cannot convert SVG to PNG")
    sys.exit(1)

print(f"Using: {chrome}")

# Use headless screenshot
tmp_png = os.path.join(tempfile.gettempdir(), "svg_screenshot.png")
cmd = [
    chrome,
    "--headless",
    "--disable-gpu",
    "--no-sandbox",
    f"--screenshot={tmp_png}",
    "--window-size=128,154",
    "--default-background-color=00000000",
    f"file:///{html_path.replace(chr(92), '/')}"
]

result = subprocess.run(cmd, capture_output=True, text=True, timeout=30)
print(f"Chrome exit: {result.returncode}")
if result.stderr:
    print(f"stderr: {result.stderr[:200]}")

time.sleep(1)

if os.path.exists(tmp_png):
    # Crop to content and resize to 128x128 with padding
    img = Image.open(tmp_png).convert("RGBA")
    # Find bounding box of non-transparent pixels
    bbox = img.getbbox()
    if bbox:
        img = img.crop(bbox)
    # Resize to fit in 128x128 with aspect ratio preserved
    img.thumbnail((128, 128), Image.LANCZOS)
    # Center on 128x128 transparent canvas
    canvas = Image.new("RGBA", (128, 128), (0, 0, 0, 0))
    x = (128 - img.width) // 2
    y = (128 - img.height) // 2
    canvas.paste(img, (x, y))
    canvas.save(png_path)
    print(f"Saved: {png_path} ({canvas.size})")
    os.remove(tmp_png)
else:
    print("ERROR: Screenshot not created")
    sys.exit(1)
