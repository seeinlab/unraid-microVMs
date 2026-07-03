from PIL import Image
import subprocess, os, tempfile, time

svg_path = r'C:\Users\seein\Workspaces\github\unraid-microVMs\src\usr\local\emhttp\plugins\microvm.manager\microvm.manager.svg'
png_path = r'C:\Users\seein\Workspaces\github\unraid-microVMs\src\usr\local\emhttp\plugins\microvm.manager\microvm.manager.png'

# Read SVG content
with open(svg_path, 'r', encoding='utf-8') as f:
    svg_content = f.read()

# Embed SVG directly in HTML (not as img src) with explicit size
html = (
    '<!DOCTYPE html><html><head><style>'
    'html,body{margin:0;padding:0;background:transparent;width:400px;height:500px;}'
    'svg{display:block;width:400px;height:480px;}'
    '</style></head><body>'
    + svg_content +
    '</body></html>'
)

html_path = os.path.join(tempfile.gettempdir(), 'lm_inline.html')
with open(html_path, 'w', encoding='utf-8') as f:
    f.write(html)

tmp_png = os.path.join(tempfile.gettempdir(), 'lm_inline.png')
if os.path.exists(tmp_png):
    os.remove(tmp_png)

chrome = r'C:\Program Files\Google\Chrome\Application\chrome.exe'
html_url = 'file:///' + html_path.replace('\\', '/')

subprocess.run([
    chrome, '--headless', '--disable-gpu', '--no-sandbox',
    f'--screenshot={tmp_png}',
    '--window-size=400,500',
    '--default-background-color=00000000',
    html_url
], capture_output=True, timeout=15)

time.sleep(1)

if os.path.exists(tmp_png):
    img = Image.open(tmp_png).convert('RGBA')
    bbox = img.getbbox()
    print(f'Raw: {img.size}, bbox: {bbox}')
    if bbox:
        cropped = img.crop(bbox)
    else:
        cropped = img
    print(f'Cropped: {cropped.size}')
    # Fit into 128x128 with 4px padding
    pad = 4
    max_dim = 128 - pad * 2
    ratio = min(max_dim / cropped.width, max_dim / cropped.height)
    new_w = int(cropped.width * ratio)
    new_h = int(cropped.height * ratio)
    resized = cropped.resize((new_w, new_h), Image.LANCZOS)
    canvas = Image.new('RGBA', (128, 128), (0, 0, 0, 0))
    x = (128 - new_w) // 2
    y = (128 - new_h) // 2
    canvas.paste(resized, (x, y))
    canvas.save(png_path)
    final_bbox = canvas.getbbox()
    print(f'Final: {canvas.size}, bbox: {final_bbox}')
    print(f'Margins: t={final_bbox[1]} b={128-final_bbox[3]} l={final_bbox[0]} r={128-final_bbox[2]}')
    os.remove(tmp_png)
else:
    print('ERROR: no screenshot')
