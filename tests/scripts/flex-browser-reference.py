#!/usr/bin/env python3
"""Regenerate the independent Chrome geometry oracle; requires google-chrome."""
import html
import json
from pathlib import Path
import re
import subprocess
import tempfile

root = Path(__file__).resolve().parents[2]
source = root / 'tests/_files/flex/browser.html'
script = '''<script>
const result = {};
const cases = {};
for (const element of document.querySelectorAll('[id]')) {
  const r = element.getBoundingClientRect();
  const container = element.closest('.case');
  const origin = container.getBoundingClientRect();
  cases[element.id] = container.id;
  result[element.id] = [r.x - origin.x, r.y - origin.y, r.width, r.height].map(n => n * 72 / 96);
}
const pre = document.createElement('pre');
pre.id = 'geometry-reference';
pre.textContent = JSON.stringify({boxes: result, cases});
document.body.append(pre);
</script>'''
with tempfile.TemporaryDirectory(prefix='dompdf-flex-browser-') as directory:
    page = Path(directory) / 'reference.html'
    page.write_text(source.read_text() + script)
    run = subprocess.run(['google-chrome', '--headless', '--no-sandbox', '--disable-gpu',
                          '--user-data-dir=' + directory + '/profile', '--dump-dom', page.as_uri()],
                         capture_output=True, text=True, check=True, timeout=30)
match = re.search(r'<pre id="geometry-reference">(.*?)</pre>', run.stdout, re.S)
if not match:
    raise RuntimeError('Chrome did not return reference geometry: ' + run.stderr)
geometry = json.loads(html.unescape(match.group(1)))
version = subprocess.check_output(['google-chrome', '--version'], text=True).strip()
target = source.with_suffix('.json')
target.write_text(json.dumps({'browser': version, **geometry}, indent=2) + '\n')
print(str(len(geometry["boxes"])) + ' reference boxes from ' + version)
