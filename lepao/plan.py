"""School rules, valid start times and the client's checkpoint assignment."""

from datetime import datetime, timedelta, timezone
import math
import secrets
import time

from .protocol import checked
from .route import distance, number
from .storage import Problem

BEIJING = timezone(timedelta(hours=8), "Asia/Shanghai")
MARGIN = 300


def inside(window, seconds):
    a, b = window["start"], window["end"]
    return a <= seconds < b if a < b else seconds >= a or seconds < b


def parse(response, account, zone):
    d = checked(response, "读取跑步规则")
    if not isinstance(d, dict) or str(d.get("school_id")) != str(account["school_id"]):
        raise Problem("跑前接口返回的学校与账号不一致")
    default = str(d.get("run_zone_id", d.get("game_id", "")))
    zones = d.get("run_zone_list", [])
    selected = next((z for z in zones if str(z.get("id")) == zone), None)
    if ("run_zone_list" in d or zone != default) and selected is None:
        raise Problem("这个跑区不在当前账号的可选列表中")
    if default != zone:
        raise Problem("服务器尚未确认所选跑区，请重试")
    try:
        line = d["run_line_info"]
        windows = []
        for w in d["time_rule_arr"]:
            item = {"start": int(number(w["start_time"], "开始时间", 0, 86399)),
                    "end": int(number(w["end_time"], "结束时间", 0, 86400)),
                    "min_distance": number(w["min_distance"], "最短里程", 0, 100),
                    "max_distance": number(w["max_distance"], "最长里程", 0, 100),
                    "fastest_pace": number(w["max_pace"], "最快配速", 1, 3600),
                    "slowest_pace": number(w["min_pace"], "最慢配速", 1, 3600),
                    "count": int(number(w.get("min_log_num", line.get("point_num", 0)), "打卡数量", 0, 100)),
                    "online": number(w["point_num_online"], "在线打卡数量", 0, 100),
                    "offline": number(w["point_num_offline"], "扫码打卡数量", 0, 100)}
            if item["start"] == item["end"] or item["min_distance"] > item["max_distance"] or item["fastest_pace"] > item["slowest_pace"]:
                raise ValueError
            for key in ("online", "offline"):
                if item[key] != int(item[key]):
                    raise ValueError
                item[key] = int(item[key])
            windows.append(item)
        if not windows:
            raise ValueError
        layers = [[{"start": int(d["run_start_time"]), "end": int(d["run_end_time"])}]]
        for times in (d.get("running_time"), (selected or {}).get("time")):
            if times is not None:
                layers.append([{"start": int(w["start_time"]), "end": int(w["end_time"])} for w in times])
        for layer in layers:
            if not layer or any(not 0 <= w["start"] < 86400 or not 0 <= w["end"] <= 86400 or w["start"] == w["end"] for w in layer):
                raise ValueError
        points = []
        for p in line.get("point_list", []):
            lat, lng = p["jingwei"].split(",")
            points.append({"id": str(p["id"]), "name": p.get("address", ""),
                           "a": number(lat, "纬度", -85, 85), "o": number(lng, "经度", -180, 180),
                           "type": int(p["type"]), "point_type": int(p["point_type"]), "online": int(p["is_online"])})
        start, end = int(d["term_start_time"]), int(d["term_end_time"])
        weekdays = list(map(int, d.get("week_time", range(7))))
        if start >= end or not str(d["term_id"]).isdigit() or not weekdays or set(weekdays)-set(range(7)):
            raise ValueError
        return {"game_id": zone, "zone_name": (selected or {}).get("name", d.get("run_zone_name", "")),
                "school_id": str(d["school_id"]), "term_id": str(d["term_id"]), "term_start": start, "term_end": end,
                "weekdays": weekdays, "windows": windows, "layers": layers, "points": points,
                "radius": number(line["log_max_distance"], "打卡半径", 1, 1000),
                "set_point_type": int(d.get("set_point_type", 0)), "repeat_num": int(d.get("repeat_num", -1)),
                "min_m": number(line["point_min_distance"], "打卡最短距离", 0, 500)*1000,
                "max1_m": number(line["point_max_distance1"], "打卡最远距离", 0, 500)*1000,
                "max2_m": number(line["point_max_distance2"], "打卡备用距离", 0, 500)*1000,
                "count": int(d["total_num"]), "target": int(d.get("term_num", 0))}
    except (KeyError, ValueError, TypeError, Problem):
        raise Problem("乐跑返回的规则不完整，暂时无法确认打卡点或有效时段") from None


def overview(api, account):
    response = api.call("Run2/beforeRunV260", account)
    d = checked(response, "读取跑区")
    if not isinstance(d, dict):
        raise Problem("账号未返回学校和跑区资料，请先在乐跑完成认证")
    return response


def fetch(api, account, zone):
    response = overview(api, account)
    d = response["data"]
    zones = d.get("run_zone_list", [])
    if zones and not any(str(z.get("id")) == zone for z in zones):
        raise Problem("当前账号没有这个跑区")
    if str(d.get("run_zone_id", d.get("game_id", ""))) != zone:
        checked(api.call("Run/setRunZone", account, {"run_zone_id": zone}), "切换跑区")
        response = overview(api, account)
    return parse(response, account, zone)


def duration(config):
    return math.ceil(round(config["distance_km"] * config["pace_s_per_km"], 6))


def ranges(windows, day):
    return [(day+off*86400+w["start"], day+off*86400+w["end"]+(86400 if w["end"] < w["start"] else 0))
            for off in (-1, 0, 1) for w in windows]


def choose(policy, config, mode="earliest", now=None, requested=None):
    now = time.time() if now is None else now
    earliest = requested if mode == "fixed" else now
    if earliest is None or earliest < now:
        raise Problem("预约时间已过，请选择未来的北京时间")
    earliest = max(earliest, policy["term_start"])
    midnight = datetime.fromtimestamp(earliest, BEIJING).replace(hour=0, minute=0, second=0, microsecond=0)
    seconds = duration(config)
    for offset in range(15):
        date = midnight + timedelta(days=offset)
        day = int(date.timestamp())
        if (date.weekday()+1) % 7 not in policy["weekdays"]:
            continue
        possible = []
        for w in policy["windows"]:
            if not w["min_distance"] <= config["distance_km"] <= w["max_distance"] or not w["fastest_pace"] <= config["pace_s_per_km"] <= w["slowest_pace"]:
                continue
            intervals = ranges([w], day)
            for layer in policy["layers"]:
                intervals = [(max(a, x), min(b, y)) for a, b in intervals for x, y in ranges(layer, day) if max(a, x) < min(b, y)]
            for a, b in intervals:
                a, b = math.ceil(max(a, earliest, day)), min(b, day+86400, policy["term_end"])
                if a + seconds + MARGIN <= b:
                    possible.append((a, b))
        if not possible:
            continue
        possible = sorted(set(possible))
        if mode == "fixed":
            match = next(((a, b) for a, b in possible if a == requested), None)
            if match is None:
                raise Problem("这个时间来不及在有效时段内跑完并提交，请提前开始")
            start, end = match
        elif mode == "earliest":
            start, end = possible[0]
        elif mode == "random":
            merged = []
            for a, b in possible:
                b = math.floor(b-seconds-MARGIN)
                if merged and a <= merged[-1][1]+1:
                    merged[-1][1] = max(merged[-1][1], b)
                else:
                    merged.append([a, b])
            choice = secrets.randbelow(sum(b-a+1 for a, b in merged))
            for a, b in merged:
                if choice <= b-a:
                    start = a+choice
                    break
                choice -= b-a+1
            end = next(b for a, b in possible if a <= start and start+seconds+MARGIN <= b)
        else:
            raise Problem("未知预约方式")
        return {"start": start, "end": start+seconds, "window_end": end, "duration": seconds}
    raise Problem("未来两周没有适合这个里程和配速的时段，请检查学校规则")


def checkpoints(policy, config, at):
    local = datetime.fromtimestamp(at, BEIJING)
    seconds = local.hour*3600+local.minute*60+local.second
    windows = [w for w in policy["windows"] if inside(w, seconds)]
    if len(windows) != 1:
        raise Problem("当前打卡数量不明确")
    w = windows[0]
    if w["offline"]:
        raise Problem("这个跑区要求现场扫码，当前程序无法代为完成")
    if not w["online"]:
        if w["count"]:
            raise Problem("服务器打卡数量不一致")
        return []
    if policy["set_point_type"] != 1 or policy["repeat_num"] != 0:
        raise Problem("当前学校的打卡分配方式尚不支持")
    pool, seen = [], set()
    origin = config["control_points"][0]
    for p in policy["points"]:
        if p["id"] in seen or p["type"] not in (1, 2) or p["point_type"] not in (1, 2):
            raise Problem("服务器打卡点重复或类型不明确")
        seen.add(p["id"])
        if p["point_type"] != 1 or (p["type"] == 2 and not p["online"]):
            continue
        gap = distance(origin, p)
        if p["type"] != 1 and gap < policy["min_m"]:
            continue
        pool.append({**p, "origin_distance": gap})
    pool.sort(key=lambda p: p["origin_distance"])
    count = w["online"]
    if count < 1 or len(pool) < count or pool[count-1]["origin_distance"] > policy["max2_m"]:
        raise Problem("起点附近没有足够的有效打卡点，请调整路线起点")
    mandatory = [p for p in pool if p["type"] == 1]
    chosen, options = [], list(pool)
    if mandatory:
        first = secrets.choice([p for p in mandatory if p["online"]] or mandatory)
        chosen.append(first)
        rest = [p for p in pool if p["id"] != first["id"]]
        for radius in (policy["max1_m"], policy["max2_m"]):
            options = [p for p in rest if p["type"] == 1 or policy["min_m"] < distance(first, p) < radius]
            if len(options) >= count-1:
                break
    while len(chosen) < count:
        if not options:
            raise Problem("无法按学校规则分配足够的打卡点")
        chosen.append(options.pop(secrets.randbelow(len(options))))
    secrets.SystemRandom().shuffle(chosen)
    return [{k: p[k] for k in ("id", "name", "a", "o")} for p in chosen]
