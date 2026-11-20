"""Publisher build: reuse the deployed application configuration, never prompt users."""

import argparse
import hashlib
import json
from pathlib import Path
import shutil
import subprocess
import sys
import zipfile


ROOT = Path(__file__).resolve().parent.parent


def main():
    parser = argparse.ArgumentParser(description="Build the configured Windows portable package")
    parser.add_argument("--config", type=Path, default=ROOT / "_build_config.json")
    parser.add_argument("--output", type=Path, default=ROOT / "dist" / "python")
    args = parser.parse_args()
    try:
        config = json.loads(args.config.read_text(encoding="utf-8-sig"))
        if not config.get("app_secret"):
            raise ValueError
    except (OSError, ValueError):
        parser.error("Publisher application configuration is missing. Import the existing deployed configuration before building.")
    if sys.platform != "win32":
        parser.error("Build the Windows package on Windows")
    output = args.output.resolve()
    staging = ROOT / "build" / "publisher"
    staging.mkdir(parents=True, exist_ok=True)
    private = staging / "_build_config.json"
    private.write_text(json.dumps(config), encoding="utf-8")
    command = [sys.executable, "-m", "PyInstaller", "--noconfirm", "--clean", "--onedir", "--console", "--name", "lepao",
               "--distpath", str(output), "--workpath", str(ROOT / "build" / "pyinstaller"),
               "--specpath", str(ROOT / "build"), "--log-level", "WARN",
               "--add-data", str(ROOT / "resources") + ":resources",
               "--add-data", str(private) + ":.", "--collect-data", "tkintermapview",
               str(ROOT / "lepao.py")]
    subprocess.run(command, cwd=ROOT, check=True)
    app = output / "lepao"
    shutil.copy2(ROOT / "LICENSE", app / "LICENSE.txt")
    (app / "使用说明.txt").write_text(
        "双击 lepao.exe，使用微信扫码并在手机上确认。\n"
        "选择“地图画线”，选择跑区，在地图上点击画路线并保存。\n"
        "选择“开始 / 恢复跑步”，或“每日托管”。运行期间请保持窗口打开。\n"
        "QQ 用户可在菜单选择 QQ 登录。\n"
        "登录状态和路线保存在 data 目录，不要把这个目录发给别人。\n"
        "不需要安装 Python、PHP、Docker，也不需要填写应用 Secret。\n",
        encoding="utf-8")
    # Record the licences for every bundled Python distribution.
    from importlib.metadata import distributions
    licenses = app / "licenses"
    licenses.mkdir(exist_ok=True)
    for distribution in distributions():
        for file in distribution.files or []:
            if any(word in file.name.lower() for word in ("license", "copying")) and ".dist-info" in str(file):
                source = distribution.locate_file(file)
                if source.is_file():
                    target = licenses / distribution.metadata["Name"] / file.name
                    target.parent.mkdir(parents=True, exist_ok=True)
                    shutil.copy2(source, target)
    python_license = Path(sys.base_prefix) / "LICENSE.txt"
    if python_license.is_file():
        shutil.copy2(python_license, licenses / "Python.txt")
    archive = output / "autolepao-python-windows-x64.zip"
    with zipfile.ZipFile(archive, "w", zipfile.ZIP_DEFLATED, compresslevel=6) as target:
        for path in app.rglob("*"):
            if path.is_file():
                target.write(path, Path("autolepao") / path.relative_to(app))
    with archive.open("rb") as stream:
        digest = hashlib.file_digest(stream, "sha256").hexdigest()
    (output / "SHA256SUMS").write_text(f"{digest}  {archive.name}\n", encoding="ascii")
    print(f"Built {archive.name}: {archive.stat().st_size:,} bytes")


if __name__ == "__main__":
    main()
