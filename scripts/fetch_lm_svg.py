import subprocess, os, tempfile, json, time

chrome = r'C:\Program Files\Google\Chrome\Application\chrome.exe'
tmp_dir = tempfile.gettempdir()

# Use Chrome to dump the SVG content from the page
# We'll use --dump-dom to get the full HTML, then parse the SVG out
html_file = os.path.join(tmp_dir, 'lm_page.html')

result = subprocess.run([
    chrome, '--headless', '--disable-gpu', '--no-sandbox',
    '--dump-dom', 'https://liquidmetal.dev/'
], capture_output=True, text=True, timeout=30)

if result.returncode == 0 and result.stdout:
    html = result.stdout
    # Find the SVG in div.col--6:first-child > div > svg
    # Look for <svg that contains the LiquidMetal droplet paths
    import re
    # Find all SVG elements
    svgs = re.findall(r'<svg[^>]*>.*?</svg>', html, re.DOTALL)
    print(f'Found {len(svgs)} SVGs in page')
    
    # The logo SVG should contain the droplet gradient colors
    for i, svg in enumerate(svgs):
        if '#00d2ff' in svg or '#e8502a' in svg or 'DROPLET' in svg:
            print(f'SVG #{i} matches LiquidMetal droplet (len={len(svg)})')
            svg_path = r'C:\Users\seein\Workspaces\github\unraid-microVMs\src\usr\local\emhttp\plugins\microvm.manager\microvm.manager.svg'
            with open(svg_path, 'w', encoding='utf-8') as f:
                f.write(svg)
            print(f'Saved to {svg_path}')
            break
    else:
        # Maybe it's loaded as an img src
        imgs = re.findall(r'<img[^>]*DROPLET[^>]*>', html)
        print(f'No inline SVG found. IMG refs with DROPLET: {len(imgs)}')
        if imgs:
            print(imgs[0][:200])
else:
    print(f'Chrome failed: {result.returncode}')
    print(result.stderr[:500] if result.stderr else 'no stderr')
