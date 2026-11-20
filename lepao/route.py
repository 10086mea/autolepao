"""Drawn WGS84 routes, GCJ02 conversion and the submission timeline."""

from bisect import bisect_left
import hashlib
import math
import secrets

from .storage import Problem


def number(value, label, low, high):
    try:
        parsed = float(value)
        if isinstance(value, bool) or not math.isfinite(parsed) or not low <= parsed <= high:
            raise ValueError
        return parsed
    except (TypeError, ValueError):
        raise Problem(f"{label}需要在 {low:g} 到 {high:g} 之间") from None


def distance(a, b):
    la, lb = math.radians(a["a"]), math.radians(b["a"])
    d = math.sin((lb - la) / 2) ** 2 + math.cos(la) * math.cos(lb) * math.sin(math.radians(b["o"] - a["o"]) / 2) ** 2
    return 6371000 * 2 * math.asin(min(1, math.sqrt(d)))


def gcj(lat, lng):
    if not 72.004 <= lng <= 137.8347 or not .8293 <= lat <= 55.8271:
        return {"a": lat, "o": lng}
    x, y, pi = lng - 105, lat - 35, math.pi
    dy = -100 + 2*x + 3*y + .2*y*y + .1*x*y + .2*math.sqrt(abs(x))
    dy += (20*math.sin(6*x*pi) + 20*math.sin(2*x*pi))*2/3
    dy += (20*math.sin(y*pi) + 40*math.sin(y/3*pi))*2/3
    dy += (160*math.sin(y/12*pi) + 320*math.sin(y*pi/30))*2/3
    dx = 300 + x + 2*y + .1*x*x + .1*x*y + .1*math.sqrt(abs(x))
    dx += (20*math.sin(6*x*pi) + 20*math.sin(2*x*pi))*2/3
    dx += (20*math.sin(x*pi) + 40*math.sin(x/3*pi))*2/3
    dx += (150*math.sin(x/12*pi) + 300*math.sin(x/30*pi))*2/3
    radians = math.radians(lat)
    magic = 1 - .006693421622965943 * math.sin(radians) ** 2
    return {"a": lat + dy*180 / ((6378245*(1-.006693421622965943))/(magic*math.sqrt(magic))*pi),
            "o": lng + dx*180 / (6378245/math.sqrt(magic)*math.cos(radians)*pi)}


def wgs(lat, lng):
    # Invert the display conversion rather than feeding GCJ02 into an OSM map.
    a, o = lat, lng
    for _ in range(6):
        translated = gcj(a, o)
        a -= translated["a"] - lat
        o -= translated["o"] - lng
    return a, o


def normalize(drawn):
    points = drawn.get("points", [])
    if not isinstance(points, list) or not 2 <= len(points) <= 512:
        raise Problem("请在地图上画出 2 至 512 个点")
    controls = []
    length = 0
    for pair in points:
        if not isinstance(pair, (list, tuple)) or len(pair) != 2:
            raise Problem("画线路线格式不正确")
        point = gcj(number(pair[0], "纬度", -85, 85), number(pair[1], "经度", -180, 180))
        if controls:
            gap = distance(controls[-1], point)
            if not .5 <= gap <= 5000:
                raise Problem("相邻路线点需要相距 0.5 米至 5 公里")
            length += gap
        controls.append(point)
    if drawn.get("closed"):
        length += distance(controls[-1], controls[0])
    if not 20 <= length <= 50000:
        raise Problem("画线路线长度需要在 20 米至 50 公里之间")
    return {"control_points": controls, "closed": bool(drawn.get("closed")),
            "distance_km": number(drawn.get("distance_km", 2.15), "里程", .2, 100),
            "pace_s_per_km": number(drawn.get("pace_s_per_km", 360), "配速（秒/公里）", 60, 3600),
            "sample_m": 4.4, "trajectory_seed": secrets.randbelow(2147483648),
            "game_id": str(drawn.get("game_id", ""))}


def circuit(config):
    ring = list(config["control_points"])
    if not config["closed"]:
        ring += ring[1:-1][::-1]
    ring.append(ring[0])
    lengths = [0.0]
    for a, b in zip(ring, ring[1:]):
        lengths.append(lengths[-1] + distance(a, b))
    if lengths[-1] < 1:
        raise Problem("路线长度不足")
    return ring, lengths


def track(config, duration):
    total, spacing = config["distance_km"] * 1000, config.get("sample_m", 4.4)
    if duration <= 0 or spacing <= 0 or not 1 <= total/spacing <= 25000:
        raise Problem("轨迹时长、里程或采样参数无效")
    ring, lengths = circuit(config)

    def position(at):
        offset = at % lengths[-1]
        i = min(len(ring)-1, max(1, bisect_left(lengths, offset)))
        f = (offset-lengths[i-1])/max(1e-12, lengths[i]-lengths[i-1])
        return {k: round(ring[i-1][k] + (ring[i][k]-ring[i-1][k])*f, 8) for k in ("a", "o")}

    points = [{**position(0), "distance_m": 0.0}]
    covered, base = 0.0, 0.0
    while covered < total - 1e-5:
        if len(points) > 30000:
            raise Problem("路线采样过密，请调整路线")
        base += spacing
        following, previous = position(base), points[-1]
        step = distance(previous, following)
        if step < 1e-6:
            raise Problem("路线存在重合采样点，请调整画线位置")
        if covered + step > total:
            f = (total-covered)/step
            following = {k: previous[k] + (following[k]-previous[k])*f for k in ("a", "o")}
            step = distance(previous, following)
        covered += step
        points.append({**following, "distance_m": covered})
    mean = total/duration
    low, high = 1000/3600, 1000/60
    if not low <= mean <= high:
        raise Problem("配速超出支持范围")
    targets = []
    for counter in range(math.ceil(covered/100)+2):
        random = int(hashlib.sha256(f'{config["trajectory_seed"]}:{counter}'.encode()).hexdigest()[:8], 16)/4294967295
        targets.append(mean*(1+.12*(2*random-1)))
    steps, raw = [], []
    for previous, point in zip(points, points[1:]):
        steps.append(point["distance_m"]-previous["distance_m"])
        x = (point["distance_m"]+previous["distance_m"])/200
        k, f = int(x), x-int(x)
        f = f*f*(3-2*f)
        raw.append(targets[k]+(targets[k+1]-targets[k])*f)
    a, b = .001, 1000.0
    for _ in range(70):
        scale = (a+b)/2
        elapsed = sum(d/max(low, min(high, s*scale)) for d, s in zip(steps, raw))
        if elapsed > duration:
            a = scale
        else:
            b = scale
    elapsed, mark_time, mark_distance, next_km = 0.0, 0.0, 0.0, 1000
    points[0].update(elapsed=0.0, s=0.0, l=1)
    for i, (step, target) in enumerate(zip(steps, raw), 1):
        speed = max(low, min(high, target*(a+b)/2))
        before = elapsed
        elapsed += step/speed
        points[i].update(elapsed=elapsed, s=speed, l=1)
        if points[i]["distance_m"] >= next_km-1e-5:
            hit = before + (next_km-points[i-1]["distance_m"])/speed
            points[i].update(b=1.0, c=round(hit-mark_time))
            mark_distance, mark_time, next_km = next_km, hit, next_km+1000
    points[0]["s"] = points[1]["s"]
    points[-2]["p"] = 1
    remainder = covered-mark_distance
    if remainder > 1e-4:
        points[-1].update(b=remainder/1000, c=round((elapsed-mark_time)*1000/remainder))
    return points
