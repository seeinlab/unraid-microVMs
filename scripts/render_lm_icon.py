from PIL import Image
import subprocess, os, tempfile, time

svg_path = r'C:\Users\seein\Workspaces\github\unraid-microVMs\src\usr\local\emhttp\plugins\microvm.manager\microvm.manager.svg'
png_path = r'C:\Users\seein\Workspaces\github\unraid-microVMs\src\usr\local\emhttp\plugins\microvm.manager\microvm.manager.png'

svg_url = 'file:///' + svg_path.replace('\\', '/')

html = (
    '<html><head><style>html,body{margin:0;padding:0;background:transparent;}</style></head>'
    '<body><img src="' + svg_url + '" width="400" height="480"></body></html>'
)

html_path = os.path.join(tempfile.gettempdir(), 'lm_render.html')
with open(html_path, 'w') as f:
    f.write(html)

tmp_png = os.path.join(tempfile.gettempdir(), 'lm_shot.png')
if os.path.exists(tmp_png):
    os.remove(tmp_png)

chrome = r'C:\Program Files\Google\Chrome\Application\chrome.exe'
html_url = 'file:///' + html_path.replace('\\', '/')

subprocess.run([
    chrome, '--headless', '--disable-gpu', '--no-sandbox',
    f'--screenshot={tmp_png}',
    '--window-size=400,480',
    '--default-background-color=00000000',
    html_url
], capture_output=True, timeout=15)

time.sleep(1)

if os.path.exists(tmp_png):
    img = Image.open(tmp_png).convert('RGBA')
    bbox = img.getbbox()
    print(f'Raw screenshot: {img.size}, content bbox: {bbox}')
    if bbox:
        cropped = img.crop(bbox)
    else:
        cropped = img
    # Fit into 128x128 with 6px padding
    pad = 6
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
    print(f'Margins: top={final_bbox[1]}, bottom={128-final_bbox[3]}, left={final_bbox[0]}, right={128-final_bbox[2]}')
    os.remove(tmp_png)
else:
    print('ERROR: Chrome did not create screenshot')
