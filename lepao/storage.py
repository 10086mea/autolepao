"""Local state and the publisher's application configuration."""

import json
import os
from pathlib import Path
import sys
import tempfile


class Problem(Exception):
    """An error safe to display without including an HTTP URL or credentials."""


ROOT = Path(getattr(sys, "_MEIPASS", Path(__file__).resolve().parent.parent))
HOME = Path(sys.executable).parent if getattr(sys, "frozen", False) else ROOT


def atomic_json(path, value):
    path = Path(path)
    path.parent.mkdir(parents=True, exist_ok=True)
    fd, temporary = tempfile.mkstemp(dir=path.parent, prefix=".write-")
    try:
        with os.fdopen(fd, "w", encoding="utf-8", newline="\n") as stream:
            json.dump(value, stream, ensure_ascii=False, separators=(",", ":"), allow_nan=False)
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(temporary, path)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


class State:
    def __init__(self, directory=None):
        self.directory = Path(directory) if directory is not None else HOME / "data"
        self.directory.mkdir(parents=True, exist_ok=True)
        self.path = self.directory / "state.json"
        try:
            self.data = json.loads(self.path.read_text(encoding="utf-8")) if self.path.exists() else {}
        except (OSError, ValueError):
            raise Problem("本地数据无法读取，请先保留 data 目录再检查文件") from None
        if not isinstance(self.data, dict):
            raise Problem("本地数据格式不正确")
        self.lock = None

    def __enter__(self):
        self.lock = (self.directory / "app.lock").open("a+b")
        if os.fstat(self.lock.fileno()).st_size == 0:
            self.lock.write(b"0")
            self.lock.flush()
        self.lock.seek(0)
        try:
            if os.name == "nt":
                import msvcrt
                msvcrt.locking(self.lock.fileno(), msvcrt.LK_NBLCK, 1)
            else:
                import fcntl
                fcntl.flock(self.lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except OSError:
            self.lock.close()
            self.lock = None
            raise Problem("程序已经在运行，请回到原来的窗口") from None
        return self

    def __exit__(self, *_):
        if self.lock:
            self.lock.close()
            self.lock = None

    def get(self, key, default=None):
        return self.data.get(key, default)

    def update(self, **values):
        self.data.update(values)
        atomic_json(self.path, self.data)


def application_config():
    # Release configuration is copied by the publisher, never entered by a user.
    for path in (ROOT / "_build_config.json", HOME / "_build_config.json",
                 HOME / ".local" / "wechat.json", HOME / "data" / "wechat.json"):
        if path.is_file():
            try:
                config = json.loads(path.read_text(encoding="utf-8-sig"))
                if config.get("app_secret"):
                    return {"app_id": config.get("app_id") or "wx5a2e1ff396785475",
                            "app_secret": config["app_secret"]}
            except (OSError, ValueError, AttributeError):
                raise Problem("应用授权配置无法读取，请重新解压完整程序包") from None
    raise Problem("此源码构建缺少发布配置。便携版已配置微信授权，不需要用户填写 Secret")
