"""Prepare once, wait in real time, submit once and retain uncertain results."""

import base64
from datetime import datetime
from email.utils import formatdate
import hashlib
import hmac
import math
import re
import secrets
import time
import uuid

from . import plan
from .protocol import ENVELOPES, Lepao, aes, checked, compact
from .route import circuit, distance, normalize, track
from .storage import Problem

RECORD_KEYS = ["aB3dE9fG2hJkL5mN", "PqR7sT8uV9wXyZ1", "A2bC3dE4fG5hI6j", "K7lM8nO9pQ0rS1t", "U2vW3xY4zA5bC6d",
               "E7fG8hI9jK0lM1n", "O2pQ3rS4tU5vW6x", "Y7zA8bC9dE0fG1h", "I2jK3lM4nO5pQ6r", "S7tU8vW9xY0zA1b"]


def steps(duration, count):
    result = [int(count*min(60, duration-i)/duration) for i in range(0, duration, 60)]
    result[-1] += count-sum(result)
    return {"interval": 60, "list": result}


def checkpoint_logs(points, config, policy, assigned, start, duration):
    logs, after = [], 0
    _, lengths = circuit(config)
    lap = max(1, math.ceil(lengths[-1]/config["sample_m"]))
    for checkpoint in assigned:
        best, first = None, None
        for i in range(after, len(points)):
            if first is not None and i > first+lap:
                break
            gap = distance(points[i], checkpoint)
            if gap <= policy["radius"]:
                if first is None:
                    first = i
                if best is None or gap < best[1]:
                    best = (i, gap)
        if best is None:
            raise Problem(f'画线路线没有经过打卡点“{checkpoint["name"]}”，请调整后再开始')
        i = best[0]
        logs.append({"point_id": int(checkpoint["id"]) if checkpoint["id"].isdigit() else checkpoint["id"],
                     "time": start+min(duration, round(points[i]["elapsed"])),
                     "latitude": points[i]["a"], "longtitude": points[i]["o"],
                     "distance": round(config["distance_km"], 2)})
        after = i+3
    local = datetime.fromtimestamp(start, plan.BEIJING)
    window = next(w for w in policy["windows"] if plan.inside(w, local.hour*3600+local.minute*60+local.second))
    if len(logs) < window["count"]:
        raise Problem("实际打卡数量少于学校要求")
    return logs


def upload(http, sts, key, body):
    bucket = sts.get("bucket", "")
    if not isinstance(bucket, str) or not re.fullmatch(r"[a-z0-9][a-z0-9-]{1,61}[a-z0-9]", bucket):
        raise Problem("乐跑返回的上传地址不正确")
    if any(not isinstance(sts.get(k), str) or not sts[k] or "\n" in sts[k] or "\r" in sts[k] for k in ("AccessKeyId", "AccessKeySecret", "SecurityToken")):
        raise Problem("乐跑返回的上传授权不完整")
    date = formatdate(usegmt=True)
    digest = base64.b64encode(hashlib.md5(body).digest()).decode()
    headers = {"x-oss-security-token": sts["SecurityToken"]}
    if sts.get("callback"):
        headers["x-oss-callback"] = sts["callback"]
    canonical = "".join(f"{k}:{headers[k]}\n" for k in sorted(headers))
    message = f"PUT\n{digest}\ntext/plain\n{date}\n{canonical}/{bucket}/{key}"
    signature = base64.b64encode(hmac.new(sts["AccessKeySecret"].encode(), message.encode(), hashlib.sha1).digest()).decode()
    headers.update({"Date": date, "Content-MD5": digest, "Content-Type": "text/plain",
                    "Authorization": "OSS " + sts["AccessKeyId"] + ":" + signature})
    response = http.request("PUT", f"https://{bucket}.oss-cn-hangzhou.aliyuncs.com/{key}", data=body, headers=headers, timeout=(10, 30))
    if not 200 <= response.status_code < 300:
        raise Problem("轨迹上传未完成，请稍后重试")


class Personal:
    def __init__(self, state, api=None, clock=time.time):
        self.state, self.api, self.clock = state, api or Lepao(), clock

    def account(self):
        account = self.state.get("account")
        if not account:
            raise Problem("请先扫码登录")
        account = self.api.profile(account)
        self.state.update(account=account)
        return account

    def prepare_plan(self, mode="earliest", requested=None):
        account = self.account()
        config = normalize(self.state.get("route") or {})
        policy = plan.fetch(self.api, account, config["game_id"])
        slot = plan.choose(policy, config, mode, self.clock(), requested)
        config["assigned"] = plan.checkpoints(policy, config, slot["start"])
        points = track(config, slot["duration"])
        checkpoint_logs(points, config, policy, config["assigned"], slot["start"], slot["duration"])
        return {"config": config, "policy": policy, "slot": slot}

    def begin(self, prepared):
        if self.state.get("pending"):
            raise Problem("仍有未完成或待核对的任务，请先查看状态")
        account = self.account()
        config = prepared["config"]
        policy = plan.fetch(self.api, account, config["game_id"])
        relevant = ("term_id", "points", "windows", "radius", "set_point_type", "repeat_num", "min_m", "max1_m", "max2_m")
        if any(policy[k] != prepared["policy"][k] for k in relevant):
            raise Problem("学校规则发生变化，请重新预约")
        now = int(self.clock())
        slot = plan.choose(policy, config, "fixed", now, now)
        points = track(config, slot["duration"])
        logs = checkpoint_logs(points, config, policy, config["assigned"], now, slot["duration"])
        timestamp = checked(self.api.call("Run/getTimestampV278", account), "取得服务器时间")
        if not isinstance(timestamp, dict) or not timestamp.get("str"):
            raise Problem("服务器时间字符串不完整")
        sts = checked(self.api.call("report/getOssSts", account), "取得上传授权")
        fields = {"a", "o", "s", "l", "p", "b", "c"}
        body = aes(compact([{k: v for k, v in p.items() if k in fields} for p in points]), ENVELOPES["NEW_GAME"]).encode()
        day = datetime.fromtimestamp(now, plan.BEIJING).strftime("%Y-%m-%d")
        key = f"Public/Upload/file/run_record/{day}/{secrets.randbelow(999)+1}/{uuid.uuid4()}.txt"
        self.state.update(pending={"phase": "preparing"})
        try:
            upload(self.api.http, sts, key, body)
            ip = self.api.http.local_ip
            if not ip:
                raise Problem("无法读取本次连接的本机地址")
            uid, duration = int(account["uid"]), slot["duration"]
            count = int(config["distance_km"]*1000/.947)
            params = {"school_id": account["school_id"], "term_id": policy["term_id"], "game_id": config["game_id"],
                      "is_running_area_valid": "1", "log_data": compact(logs), "file_img": "1", "used_time": str(duration),
                      "start_time": str(now), "end_time": str(now+duration), "distance": f'{config["distance_km"]:.2f}',
                      "step_num": str(count), "record_file": key, "record_img": "", "newMobileDeviceId": "",
                      "step_info": compact(steps(duration, count)),
                      "record_str": hashlib.md5(f'{account["school_id"]}{uid}{timestamp["str"]}{RECORD_KEYS[uid % 10]}'.encode()).hexdigest(),
                      "pass_total": str(len(points)), "pass_effective_num": str(len(points)), "ip": ip, "gee_token": "",
                      "run_check_res": compact([{"uid": str(uid), "plan": 0, "inteval_result": "0", "inteval_value": "0", "is_stop": 0, "is_cheat": 0}]),
                      "run_face_verify_id": "1231231231231", "is_timeout_zfb": "0", "timestamp": str(now+duration)}
            self.state.update(pending={"phase": "running", "uid": account["uid"], "token": account["access_token"],
                                       "params": params, "start": now, "resume_at": now+duration+1, "window_end": slot["window_end"]})
        except Exception:
            self.state.update(pending=None)
            raise

    def finish(self):
        pending = self.state.get("pending") or {}
        if pending.get("phase") != "running":
            raise Problem("没有可恢复的跑步，请先查看状态")
        account = self.state.get("account") or {}
        if account.get("uid") != pending["uid"] or account.get("access_token") != pending["token"]:
            raise Problem("任务开始后登录状态改变，请先核对成绩")
        if self.clock() < pending["resume_at"]:
            raise Problem("尚未到提交时间")
        if self.clock()+30 >= pending["window_end"]:
            self.state.update(pending={**pending, "phase": "uncertain"})
            raise Problem("已经错过提交时段，本次不会再发起提交，请查看远端记录")
        self.state.update(pending={**pending, "phase": "submitting"})
        try:
            response = self.api.call("Run/stopRunV278", account, pending["params"])
            data = checked(response, "提交跑步")
        except Exception:
            self.state.update(pending={**pending, "phase": "uncertain"})
            raise Problem("提交结果尚未确认。请查看状态核对，程序不会自动重复提交") from None
        data = data if isinstance(data, dict) else {}
        result = {"start": pending["start"], "record_id": data.get("id", data.get("record_id")), "record_status": data.get("record_status")}
        self.state.update(pending=None, last_result=result)
        return result

    def records(self, since=None):
        account = self.state.get("account")
        if not account:
            raise Problem("请先扫码登录")
        since = int(since if since is not None else datetime.fromtimestamp(self.clock(), plan.BEIJING).replace(hour=0, minute=0, second=0, microsecond=0).timestamp())
        records, seen, previous = [], set(), math.inf
        for page in range(1, 21):
            data = checked(self.api.call("Run2/allRecordList", account, {"page": str(page), "year": str(datetime.fromtimestamp(since, plan.BEIJING).year)}), "核对成绩")
            rows = data.get("list") if isinstance(data, dict) else None
            if not isinstance(rows, list):
                raise Problem("远端成绩列表不完整，请稍后核对")
            for row in rows:
                if not isinstance(row, dict):
                    raise Problem("远端记录格式不正确")
                rid, start = str(row.get("id", "")), str(row.get("start_time", ""))
                if not rid or not start.isdigit() or int(start) > previous or rid in seen or str(row.get("uid")) != account["uid"] or "type" not in row:
                    raise Problem("远端记录不完整，请稍后核对")
                previous = int(start)
                seen.add(rid)
                if previous < since:
                    return records
                if str(row["type"]) == "1":
                    records.append(row)
            if not rows:
                return records
        raise Problem("远端成绩尚未查询完整，请稍后重试")

    def reconcile(self):
        pending = self.state.get("pending")
        if not pending:
            return True
        if pending["phase"] == "preparing":
            self.state.update(pending=None)
            return True
        for record in self.records(pending["start"]):
            if int(record["start_time"]) == pending["start"]:
                result = {"start": pending["start"], "record_id": record["id"], "record_status": record.get("record_status")}
                self.state.update(pending=None, last_result=result)
                return True
        return False
