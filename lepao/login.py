"""QR authorization. Application credentials belong to the release configuration."""

import base64
import csv
import hashlib
import html
import re
import secrets
import time
from urllib.parse import parse_qs, urlencode, urljoin, urlsplit

from .protocol import Http, RemoteTimeout
from .storage import Problem, application_config, atomic_json


class Wechat:
    def __init__(self, state, http=None, config=None):
        self.state = state
        self.http = http or Http()
        self.config = application_config() if config is None else config

    def get(self, path, params):
        data = self.http.json("GET", "https://api.weixin.qq.com/" + path, params=params)
        if data.get("errcode"):
            code = str(data["errcode"])
            if not re.fullmatch(r"-?\d{1,8}", code):
                code = "unknown"
            raise Problem(f"微信授权服务拒绝请求（{code}），请稍后重试")
        return data

    def start(self):
        appid, secret = self.config["app_id"], self.config["app_secret"]
        path = self.state.directory / "wx-ticket.json"
        try:
            import json
            cache = json.loads(path.read_text(encoding="utf-8")) if path.exists() else {}
        except (OSError, ValueError):
            cache = {}
        if cache.get("app_id") == appid and cache.get("expires", 0) > time.time() + 60 and cache.get("ticket"):
            ticket = cache["ticket"]
        else:
            token = self.get("cgi-bin/token", {"grant_type": "client_credential", "appid": appid, "secret": secret})
            if not token.get("access_token"):
                raise Problem("微信没有返回应用授权")
            data = self.get("cgi-bin/ticket/getticket", {"access_token": token["access_token"], "type": 2})
            if not data.get("ticket"):
                raise Problem("微信没有返回扫码票据")
            ticket = data["ticket"]
            lifetime = min(int(token.get("expires_in", 7200)), int(data.get("expires_in", 7200)))
            atomic_json(path, {"app_id": appid, "ticket": ticket, "expires": time.time() + max(0, lifetime - 300)})
        nonce, timestamp = secrets.token_hex(8), str(int(time.time()))
        signature = hashlib.sha1(f"appid={appid}&noncestr={nonce}&sdk_ticket={ticket}&timestamp={timestamp}".encode()).hexdigest()
        data = self.http.json("GET", "https://open.weixin.qq.com/connect/sdk/qrconnect", params={
            "appid": appid, "noncestr": nonce, "timestamp": timestamp, "scope": "snsapi_userinfo", "signature": signature})
        if not data.get("uuid") or not data.get("qrcode", {}).get("qrcodebase64"):
            code = data.get("errcode", "unknown")
            code = str(code) if re.fullmatch(r"-?\d{1,8}", str(code)) else "unknown"
            raise Problem(f"微信二维码获取失败（{code}）")
        try:
            image = base64.b64decode(data["qrcode"]["qrcodebase64"], validate=True)
        except ValueError:
            raise Problem("微信二维码图片无法读取") from None
        return {"uuid": data["uuid"], "image": image, "expires": time.time() + 300}

    def poll(self, qr):
        try:
            data = self.http.json("GET", "https://open.weixin.qq.com/connect/l/qrconnect",
                                  params={"f": "json", "uuid": qr["uuid"]}, timeout=(10, 35))
        except RemoteTimeout:
            # The SDK endpoint holds its response while nobody has scanned yet.
            # A read timeout ends this poll, not the QR authorization session.
            return {"state": "waiting"}
        code = data.get("wx_errcode")
        if str(code) == "405" and data.get("wx_code"):
            return {"state": "ok", "code": data["wx_code"]}
        if str(code) in ("402", "403"):
            return {"state": "expired"}
        if str(code) in ("404", "408"):
            return {"state": "scanned" if str(code) == "404" else "waiting"}
        safe = str(code) if re.fullmatch(r"-?\d{1,8}", str(code)) else "unknown"
        raise Problem(f"微信扫码状态异常（{safe}）")

    def exchange(self, qr, code):
        grant = self.get("sns/oauth2/access_token", {"appid": self.config["app_id"], "secret": self.config["app_secret"],
                        "code": code, "grant_type": "authorization_code"})
        if not grant.get("openid") or not grant.get("access_token"):
            raise Problem("微信授权已失效，请重新扫码")
        try:
            profile = self.get("sns/userinfo", {"access_token": grant["access_token"], "openid": grant["openid"], "lang": "zh_CN"})
        except Problem:
            profile = {}
        return {**grant, "provider": "wechat", "nickname": profile.get("nickname", ""),
                "avatar": profile.get("headimgurl", "")}


class QQ:
    APP_ID = "1105660458"
    HOSTS = {"openmobile.qq.com", "graph.qq.com", "xui.ptlogin2.qq.com", "ssl.ptlogin2.qq.com",
             "ptlogin2.qq.com", "ptlogin2.graph.qq.com", "ssl.ptlogin2.graph.qq.com", "connect.qq.com"}

    def __init__(self, state=None, http=None):
        self.http = http or Http()
        self.http.session.headers.update({"User-Agent": "Mozilla/5.0 (Linux; Android 11) AppleWebKit/537.36 Chrome/120.0.0.0 Mobile Safari/537.36"})

    @staticmethod
    def trusted(url, callback=False):
        try:
            p = urlsplit(url)
            invalid = p.username or p.password or p.port is not None
        except ValueError:
            return False
        if invalid:
            return False
        if callback and p.scheme == "auth" and p.netloc == "tauth.qq.com":
            return True
        if callback and p.scheme == "https" and p.netloc == "imgcache.qq.com" and p.path == "/open/connect/widget/mobile/login/proxy.htm":
            return True
        return p.scheme in ("http", "https") and p.netloc in QQ.HOSTS

    @staticmethod
    def decode(text):
        return html.unescape(re.sub(r"\\x([0-9a-fA-F]{2})", lambda m: chr(int(m[1], 16)), text).replace("\\/", "/"))

    def get(self, url, referer="https://xui.ptlogin2.qq.com/"):
        if not self.trusted(url) or not url.startswith("https://"):
            raise Problem("QQ 授权跳转地址异常")
        return self.http.request("GET", url, headers={"Referer": referer})

    def cookie(self, name):
        return next((c.value for c in self.http.session.cookies if c.name == name), "")

    def start(self):
        stamp = int(time.time())
        signature = hashlib.md5(f"com.lptiyu.tanke_d6ab92f37e0bdbff74d08a64c19ea1d8_{stamp}".encode()).hexdigest()
        auth = "https://openmobile.qq.com/oauth2.0/m_authorize?" + urlencode({
            "client_id": self.APP_ID, "response_type": "token", "redirect_uri": "auth://tauth.qq.com/",
            "display": "mobile", "style": "qr", "scope": "all", "sdkp": "a", "sdkv": "3.5.11.lite",
            "pf": "openmobile_android", "format": "json", "switch": "1", "time": str(stamp), "sign": signature})
        match = re.search(r'src\s*=\s*"([^"]+)"', self.get(auth).text)
        if not match:
            raise Problem("QQ 未建立授权会话，请重新获取二维码")
        xlogin = self.decode(match[1])
        if not self.trusted(xlogin) or urlsplit(xlogin).netloc != "xui.ptlogin2.qq.com" or urlsplit(xlogin).path != "/cgi-bin/xlogin":
            raise Problem("QQ 授权入口异常")
        params = {k: v[0] for k, v in parse_qs(urlsplit(xlogin).query).items()}
        if any(not params.get(k) for k in ("appid", "daid", "pt_3rd_aid", "h5sig", "s_url", "style")) or params["pt_3rd_aid"] != self.APP_ID:
            raise Problem("QQ 授权上下文与乐跑不一致")
        self.get(xlogin, auth)
        if not self.cookie("pt_login_sig"):
            raise Problem("QQ 没有签发扫码票据")
        image = self.get("https://xui.ptlogin2.qq.com/ssl/ptqrshow?" + urlencode({
            "s": "8", "e": "0", "appid": params["appid"], "type": "0", "t": time.time(),
            "daid": params["daid"], "pt_3rd_aid": self.APP_ID, "u1": params["s_url"]}), xlogin).content
        if not self.cookie("qrsig") or not image.startswith((b"\x89PNG", b"\xff\xd8", b"GIF8")):
            raise Problem("QQ 没有返回二维码图片")
        return {"image": image, "expires": time.time() + 150, "xlogin": xlogin, "params": params}

    @staticmethod
    def parse_poll(text):
        match = re.search(r"\bptuiCB\((.*)\)\s*;?\s*$", text, re.S)
        if not match:
            raise Problem("QQ 扫码状态无法识别")
        parts = next(csv.reader([match[1]], quotechar="'", escapechar="\\"))
        state = {"66": "waiting", "67": "scanned", "65": "expired", "68": "expired", "0": "ok"}.get(parts[0])
        if state is None:
            safe = parts[0] if re.fullmatch(r"-?\d{1,8}", parts[0]) else "unknown"
            raise Problem(f"QQ 拒绝了扫码请求（{safe}），请重新获取二维码")
        return {"state": state, "code": parts[2] if len(parts) > 2 and state == "ok" else ""}

    def poll(self, qr):
        token = 0
        for char in self.cookie("qrsig"):
            token = (token * 33 + ord(char)) & 0x7fffffff
        p = qr["params"]
        url = "https://xui.ptlogin2.qq.com/ssl/ptqrlogin?" + urlencode({
            "u1": p["s_url"], "from_ui": "1", "type": "1", "ptlang": "2052", "ptqrtoken": token,
            "daid": p["daid"], "aid": p["appid"], "pt_3rd_aid": self.APP_ID,
            "pt_openlogin_data": urlsplit(qr["xlogin"]).query + "&pt_flex=1", "device": "2", "ptopt": "1",
            "pt_uistyle": p["style"], "jsver": "v1.36.0", "login_sig": self.cookie("pt_login_sig"), "r": time.time()})
        return self.parse_poll(self.get(url, qr["xlogin"]).text)

    def exchange(self, qr, url):
        visited = set()
        for _ in range(10):
            if not self.trusted(url, True) or url in visited:
                raise Problem("QQ 授权跳转异常")
            visited.add(url)
            p = urlsplit(url)
            fields = {k: v[0] for k, v in {**parse_qs(p.query), **parse_qs(p.fragment)}.items()}
            if fields.get("openid") and fields.get("access_token"):
                identity = self.get("https://graph.qq.com/oauth2.0/me?" + urlencode({"access_token": fields["access_token"], "fmt": "json"})).json()
                if str(identity.get("client_id")) != self.APP_ID or identity.get("openid") != fields["openid"]:
                    raise Problem("QQ 授权与乐跑不匹配")
                try:
                    info = self.get("https://graph.qq.com/user/get_simple_userinfo?" + urlencode({
                        "access_token": fields["access_token"], "openid": fields["openid"],
                        "oauth_consumer_key": self.APP_ID, "format": "json"})).json()
                except (Problem, ValueError):
                    info = {}
                return {"provider": "qq", "openid": fields["openid"], "access_token": fields["access_token"],
                        "nickname": info.get("nickname", ""), "avatar": info.get("figureurl_qq_2", "")}
            if p.scheme == "auth":
                break
            response = self.get(re.sub(r"^http:", "https:", url), qr["xlogin"])
            location = response.headers.get("Location")
            if not location:
                match = re.search(r'''location(?:\.href)?\s*=\s*["']([^"']+)["']|location\.(?:replace|assign)\(\s*["']([^"']+)["']''', response.text)
                location = next((v for v in match.groups() if v), "") if match else ""
            if not location:
                break
            url = urljoin(url, self.decode(location))
        raise Problem("QQ 未返回授权结果，请重新扫码确认")
