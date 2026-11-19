#!/usr/bin/env python3
"""Build the installable module zip: dist/satelitmoldova.zip (root folder satelitmoldova/).

    python tools/build.py

Upload it in Modules > Module Manager > Upload a module, or unzip it into modules/.
"""
import subprocess
import sys
import time
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
MOD = ROOT / "satelitmoldova"


def main():
    # Translations must cover every string before a release.
    if subprocess.call([sys.executable, str(ROOT / "tools" / "translations.py"), "--check"]):
        return 1
    out = ROOT / "dist" / "satelitmoldova.zip"
    out.parent.mkdir(exist_ok=True)
    files = sorted(p for p in MOD.rglob("*") if p.is_file())
    with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED) as z:
        for f in files:
            # Real modification times, so PHP's opcache reloads upgraded files.
            stamp = time.localtime(max(f.stat().st_mtime, 315532800))[:6]
            info = zipfile.ZipInfo("satelitmoldova/" + f.relative_to(MOD).as_posix(), stamp)
            info.compress_type = zipfile.ZIP_DEFLATED
            info.external_attr = 0o644 << 16
            z.writestr(info, f.read_bytes())
    print(f"{out.relative_to(ROOT)}  {len(files)} files, {out.stat().st_size} bytes")
    return 0


if __name__ == "__main__":
    sys.exit(main())
