"""The request envelope used by the Android 4.3.3 (233) client."""

import base64
import hashlib
import json
import logging
import re
import secrets
import time
import uuid

from cryptography.hazmat.primitives.ciphers import Cipher, algorithms, modes
from cryptography.hazmat.primitives.padding import PKCS7
import requests

from .storage import Problem, ROOT

ENVELOPES = {
    "NEW_GAME": ("Wet2C8d34f62ndi3", "K6iv85jBD8jgf32D"),
    "PARAMS_NEW_GAME": ("goMEzJ1pDpncNHb9", "ZUrAS2lUidjyY2gK"),
}
LOGIN_KEYS = [
    ("R7gT3pL9sF2kD5mQ", "B8jN4wH6xJ2vK7rE"), ("M5dS8fG3hJ7kL2pR", "C9tY6rU3eW4qX8sZ"),
    ("P2bN7vF4cD9mK6jA", "S3nH5gJ8dL2fT9wQ"), ("X6rV9tB3yG5hN7kP", "E4sW2qA8dZ6fC3jM"),
    ("K8jD3mF7nH2pL5rT", "Q6wS4eX9cV2bN7gJ"), ("F3dG7hJ2kL5pM8rN", "Z9xC4vB6nA2sD7fH"),
    ("B5vN8cM3xZ6dF2gJ", "H7kL4jP9mQ2rT5sW"), ("N6mK3lJ7pO2iU5yT", "G8hF4dS2fA6gH9jK"),
    ("V2bC7nM3vX6cZ9dF", "J5kL8jH3gD7fS2dA"), ("H9jG4fD7sA2dF5gH", "K3lJ6pO9iU2yT5rE"),
]
ENCRYPTED = {"Login/quickLoginV339", "Login/RefreshToken", "Run2/beforeRunV260",
             "Run/stopRunV278", "report/getOssSts"}


class RemoteTimeout(Problem):
    """An established connection had no response within its read timeout."""


def compact(value):
    return json.dumps(value, ensure_ascii=False, separators=(",", ":"), allow_nan=False)


def aes(text, pair, decrypt=False):
    key, iv = pair
    cipher = Cipher(algorithms.AES(key.encode()[:16].ljust(16, b"\0")), modes.CBC(iv.encode()))
    if decrypt:
        worker = cipher.decryptor()
        plain = worker.update(base64.b64decode(text, validate=True)) + worker.finalize()
        padding = PKCS7(128).unpadder()
        return (padding.update(plain) + padding.finalize()).decode("utf-8")
    padding = PKCS7(128).padder()
    plain = padding.update(text.encode()) + padding.finalize()
    worker = cipher.encryptor()
    return base64.b64encode(worker.update(plain) + worker.finalize()).decode("ascii")


def sign(params, new=False, login=False):
    text = "eFoqdHOdDy6ViJ0i" if new else ""
    for key in sorted(params):
        if key.lower() == "file" or (new and key == "mobileDeviceId"):
            continue
        if new and login and key in ("nick_name", "avatar_url"):
            continue
        text += key + str(params[key])
    return hashlib.md5((text + ("" if new else "rDJiNB9j7vD2")).encode()).hexdigest()


class Http:
    def __init__(self):
        self.session = requests.Session()
        self.session.headers.update({"User-Agent": "okhttp/3.12.13"})
        self.local_ip = None

    def request(self, method, url, **options):
        options.setdefault("timeout", (10, 25))
        options.setdefault("allow_redirects", False)
        options.setdefault("stream", True)
        try:
            result = self.session.request(method, url, **options)
            connection = getattr(result.raw, "_connection", None)
            sock = getattr(connection, "sock", None)
            if sock:
                self.local_ip = sock.getsockname()[0]
            result.content
        except requests.ReadTimeout:
            raise RemoteTimeout("网络等待超时，请稍后重试") from None
        except requests.RequestException:
            raise Problem("网络请求失败，请检查网络后重试") from None
        if not 200 <= result.status_code < 400:
            raise Problem(f"远端服务返回 HTTP {result.status_code}")
        return result

    def json(self, method, url, **options):
        try:
            # These endpoints send UTF-8, including when labelled text/plain.
            # Requests' Latin-1 fallback for that header corrupts Chinese names.
            result = json.loads(self.request(method, url, **options).content.decode("utf-8-sig"))
        except ValueError:
            raise Problem("远端没有返回可识别的数据") from None
        if not isinstance(result, dict):
            raise Problem("远端返回的数据格式不正确")
        return result


def device():
    models = []
    for line in (ROOT / "resources" / "android-devices.txt").read_text(encoding="utf-8-sig").splitlines():
        if line.strip() and not line.lstrip().startswith("#"):
            models.append(line.split("\t"))
    model, name, android = secrets.choice(models)
    return {"mobileModel": model, "mobileOsVersion": android, "mobileDeviceId": str(uuid.uuid4()),
            "ostype": "1", "device_name": name}


def checked(response, label):
    status = str(response.get("status", ""))
    if status in ("101", "102"):
        raise Problem("乐跑登录已失效，请重新扫码登录")
    if status != "1":
        code = status if re.fullmatch(r"-?\d{1,8}", status) else "unknown"
        logging.warning("lepao_rejected endpoint=%s status=%s", label, code)
        raise Problem(f"{label}失败（状态 {code}）")
    return response.get("data")


class Lepao:
    def __init__(self, http=None):
        self.http = http or Http()

    def params(self, account, extra=None):
        extra = extra or {}
        for key in ("uid", "school_id", "student_num"):
            if key in account and key in extra and str(account[key]) != str(extra[key]):
                raise Problem("请求中的账号资料与当前登录不一致")
        profile = account.get("device_profile", {})
        fields = ("mobileModel", "mobileOsVersion", "mobileDeviceId", "ostype")
        if any(not isinstance(profile.get(k), str) or not profile[k].strip() for k in fields):
            raise Problem("设备资料不完整，请重新扫码")
        if any(k in extra and str(extra[k]) != profile[k] for k in fields):
            raise Problem("请求设备与登录设备不一致")
        params = {"n": "211", "uid": str(account.get("uid", "1")),
                  "school_id": str(account.get("school_id", "-1")),
                  "timestamp": str(int(time.time())), "nonce": str(secrets.randbelow(900000) + 100000),
                  **{k: profile[k] for k in fields}, **extra, "version": "233", "version_name": "4.3.3"}
        if account.get("access_token"):
            params["token"] = account["access_token"]
        if account.get("student_num"):
            params["student_num"] = str(account["student_num"])
        return params

    def call(self, endpoint, account, extra=None):
        params = self.params(account, extra)
        login = endpoint == "Login/quickLoginV339"
        if login:
            for key in ("openid", "accesstoken"):
                params[key] = aes(params[key], LOGIN_KEYS[int(params["nonce"]) % 10])
        new = endpoint in ENCRYPTED
        params["sign"] = sign(params, new, login)
        body = {"key": aes(compact(params), ENVELOPES["PARAMS_NEW_GAME"])} if new else params
        response = self.http.json("POST", "https://api2.lptiyu.com/v3/api.php/" + endpoint, data=body)
        data = response.get("data")
        try:
            if str(response.get("is_encrypt")) == "1" and isinstance(data, str):
                response["data"] = json.loads(aes(data, ENVELOPES["NEW_GAME"], True))
            elif isinstance(data, str) and data.startswith(("{", "[")):
                response["data"] = json.loads(data)
        except (ValueError, TypeError):
            raise Problem("乐跑响应解码失败，请检查客户端协议版本") from None
        return response

    def login(self, grant, profile):
        extra = {"openid": grant["openid"], "accesstoken": grant["access_token"],
                 "type": "2" if grant["provider"] == "qq" else "3",
                 "nick_name": grant.get("nickname", ""), "avatar_url": grant.get("avatar", "")}
        if grant.get("unionid"):
            extra["unionid"] = grant["unionid"]
        data = checked(self.call("Login/quickLoginV339", {"device_profile": profile}, extra), "扫码登录")
        if not isinstance(data, dict) or not data.get("uid") or not data.get("access_token"):
            raise Problem("乐跑未返回完整登录结果，请重新扫码")
        return {"uid": str(data["uid"]), "access_token": data["access_token"],
                "refresh_token": data.get("refresh_token", ""), "openid": grant["openid"],
                "provider": grant["provider"], "nickname": grant.get("nickname") or data.get("name") or str(data["uid"]),
                "device_profile": profile}

    def profile(self, account):
        lookup = {k: v for k, v in account.items() if k not in ("school_id", "student_num")}
        data = checked(self.call("Student/InfoV14", lookup), "读取学籍")
        if not isinstance(data, dict) or str(data.get("uid")) != account["uid"]:
            raise Problem("学籍资料与当前账号不一致")
        if any(not data.get(k) or str(data[k]) == "0" for k in ("school_id", "student_num")):
            raise Problem("登录有效，但账号没有学校或学号，请先在乐跑完成学生认证")
        return {**account, **{k: str(data[k]) for k in ("school_id", "student_num")},
                "name": data.get("name", "")}
