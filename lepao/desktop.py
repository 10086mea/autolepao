"""Small native windows for scanning and drawing; the application stays a CLI."""

import io
import queue
import threading
import time
import tkinter as tk
from tkinter import messagebox, ttk

from PIL import Image, ImageTk

from . import __version__
from .route import distance, normalize, wgs
from .storage import Problem


class Window(tk.Tk):
    def destroy(self):
        if getattr(self, "_closing", False):
            return
        self._closing = True
        # Tk keeps timers after a window is destroyed. A later Tk mainloop can
        # run them after their Python callback commands have already been deleted.
        # Cancel at Tcl level so each widget still owns its command cleanup.
        for timer in self.tk.splitlist(self.tk.call("after", "info")):
            self.tk.call("after", "cancel", timer)
        super().destroy()


def window(title, size):
    import sys
    if sys.platform == "win32":
        import ctypes
        try:
            ctypes.windll.shcore.SetProcessDpiAwareness(1)
        except (AttributeError, OSError):
            pass
    root = Window()
    root.title(title)
    width, height = map(int, size.split("x"))
    root.geometry(f"{min(width, root.winfo_screenwidth()-60)}x{min(height, root.winfo_screenheight()-100)}")
    root.option_add("*Font", "{Microsoft YaHei UI} 10")
    ttk.Style(root).configure("TButton", padding=(12, 7))
    return root


def scan(provider):
    root = window("乐跑 · 扫码登录", "480x540")
    frame = ttk.Frame(root, padding=24)
    frame.pack(fill="both", expand=True)
    name = "微信" if provider.__class__.__name__ == "Wechat" else "QQ"
    ttk.Label(frame, text=f"使用{name}扫码登录", font=("Microsoft YaHei UI", 18, "bold")).pack(pady=(0, 14))
    image_label = ttk.Label(frame, anchor="center")
    image_label.pack(fill="both", expand=True)
    status = tk.StringVar(value="正在获取二维码…")
    ttk.Label(frame, textvariable=status, anchor="center").pack(fill="x", pady=14)
    events, stopped, result = queue.Queue(), threading.Event(), {}

    def work():
        try:
            qr = provider.start()
            events.put(("image", qr["image"]))
            failures = 0
            while not stopped.is_set() and time.time() < qr["expires"]:
                try:
                    polled = provider.poll(qr)
                    failures = 0
                except Problem:
                    failures += 1
                    if failures >= 3:
                        raise
                    events.put(("status", "网络暂时中断，正在重新查询…"))
                    stopped.wait(2)
                    continue
                if stopped.is_set():
                    return
                state = polled["state"]
                if state == "ok":
                    events.put(("status", "已确认，正在完成授权…"))
                    grant = provider.exchange(qr, polled["code"])
                    if not stopped.is_set():
                        events.put(("grant", grant))
                    return
                if state == "expired":
                    break
                events.put(("status", "已扫码，请在手机上确认" if state == "scanned" else f"请用{name}扫码并确认"))
                stopped.wait(2)
            if not stopped.is_set():
                events.put(("error", "二维码已过期或授权已取消，请重新登录"))
        except Problem as error:
            events.put(("error", str(error)))
        except Exception:
            events.put(("error", "扫码授权未完成，请重新获取二维码"))

    def consume():
        while not events.empty():
            kind, value = events.get_nowait()
            if kind == "image":
                try:
                    image = Image.open(io.BytesIO(value))
                    image.thumbnail((340, 340), Image.Resampling.NEAREST)
                    image_label.image = ImageTk.PhotoImage(image, master=root)
                    image_label.configure(image=image_label.image)
                except (OSError, ValueError):
                    result["error"] = "二维码图片无法显示"
                    stopped.set()
                    root.destroy()
                    return
            elif kind == "status":
                status.set(value)
            else:
                result[kind] = value
                stopped.set()
                root.destroy()
                return
        root.after(100, consume)

    def cancel():
        stopped.set()
        root.destroy()

    ttk.Button(frame, text="取消登录", command=cancel).pack()
    root.protocol("WM_DELETE_WINDOW", cancel)
    threading.Thread(target=work, daemon=True).start()
    root.after(100, consume)
    root.mainloop()
    if "error" in result:
        raise Problem(result["error"])
    if "grant" not in result:
        raise Problem("已取消登录")
    return result["grant"]


def draw(previous=None, points=None, zone=""):
    from tkintermapview import TkinterMapView
    import requests

    class Map(TkinterMapView):
        def pre_cache(self):
            # Request visible tiles only, not the library's surrounding 8 rings.
            return

        def request_image_from_server(self, tile_server, zoom, x, y):
            url = tile_server.replace("{z}", str(zoom)).replace("{x}", str(x)).replace("{y}", str(y))
            with requests.get(url, timeout=(5, 12), headers={"User-Agent": self.user_agent}) as response:
                response.raise_for_status()
                return io.BytesIO(response.content)

    previous, candidates = previous or {}, points or []
    root = window("乐跑 · 在地图上画路线", "1100x760")
    root.minsize(800, 550)
    toolbar = ttk.Frame(root, padding=(12, 12, 12, 6))
    toolbar.pack(fill="x")
    ttk.Label(toolbar, text=zone or "画出你的跑步路线", font=("Microsoft YaHei UI", 13, "bold")).pack(side="left")
    search = tk.StringVar()
    entry = ttk.Entry(toolbar, textvariable=search, width=32)
    entry.pack(side="left", padx=(22, 8))
    coordinate_status = tk.StringVar(value="输入纬度,经度可直接定位")
    mapping = Map(root, width=1050, height=560, corner_radius=0,
                  user_agent=f"AutoLepao/{__version__} (+https://github.com/10086mea/autolepao)")
    mapping.pack(fill="both", expand=True, padx=12)
    controls = [tuple(p) for p in previous.get("points", [])]
    markers, path, result = [], None, {}
    closed = tk.BooleanVar(value=previous.get("closed", True))
    enabled = tk.BooleanVar(value=True)
    km = tk.StringVar(value=str(previous.get("distance_km", 2.15)))
    pace = tk.StringVar(value=str(previous.get("pace_s_per_km", 360)))
    detail = tk.StringVar()
    search_events = queue.Queue()

    def locate():
        query = search.get().strip()
        if not query:
            return
        try:
            a, o = map(float, query.split(","))
            if not -85 <= a <= 85 or not -180 <= o <= 180:
                raise ValueError
            mapping.set_position(a, o)
            mapping.set_zoom(17)
            coordinate_status.set("已定位")
            return
        except ValueError:
            pass
        coordinate_status.set("正在查找地点…")
        search_button.configure(state="disabled")

        def search_place():
            try:
                response = requests.get("https://nominatim.openstreetmap.org/search", params={"q": query, "format": "jsonv2", "limit": 1},
                                        headers={"User-Agent": mapping.user_agent}, timeout=(5, 15))
                response.raise_for_status()
                places = response.json()
                search_events.put((float(places[0]["lat"]), float(places[0]["lon"])) if places else None)
            except (requests.RequestException, ValueError, KeyError, IndexError):
                search_events.put(None)

        threading.Thread(target=search_place, daemon=True).start()

    def check_search():
        if not search_events.empty():
            position = search_events.get_nowait()
            search_button.configure(state="normal")
            if position:
                mapping.set_position(*position)
                mapping.set_zoom(17)
                coordinate_status.set("已定位，点击地图添加路线点")
            else:
                coordinate_status.set("没有查到地点，也可以输入纬度,经度定位")
        root.after(100, check_search)

    search_button = ttk.Button(toolbar, text="定位", command=locate)
    search_button.pack(side="left")
    entry.bind("<Return>", lambda _: locate())
    ttk.Checkbutton(toolbar, text="点击地图画线", variable=enabled).pack(side="right")
    ttk.Label(root, textvariable=coordinate_status, anchor="w").pack(fill="x", padx=12, pady=(6, 0))

    def redraw():
        nonlocal path
        for marker in markers:
            marker.delete()
        markers.clear()
        if path:
            path.delete()
            path = None
        for i, point in enumerate(controls):
            markers.append(mapping.set_marker(*point, text=str(i+1), marker_color_circle="#148c78", marker_color_outside="#0c5b4e"))
        line = controls + ([controls[0]] if closed.get() and len(controls) > 2 else [])
        if len(line) > 1:
            path = mapping.set_path(line, color="#148c78", width=4)
        length = sum(distance({"a": a[0], "o": a[1]}, {"a": b[0], "o": b[1]}) for a, b in zip(line, line[1:]))
        detail.set(f"{len(controls)} 个路线点 · 画线长度 {length:.0f} 米。左键添加，拖动地图，右键撤回。")

    def add(position):
        if enabled.get() and len(controls) < 512:
            controls.append(tuple(position))
            redraw()

    def undo():
        if controls:
            controls.pop()
            redraw()

    def clear():
        controls.clear()
        redraw()

    def save():
        route = {"points": [list(p) for p in controls], "closed": closed.get(), "distance_km": km.get(), "pace_s_per_km": pace.get()}
        try:
            normalized = normalize(route)
        except Problem as error:
            messagebox.showerror("路线还不能保存", str(error), parent=root)
            return
        result.update(route, distance_km=normalized["distance_km"], pace_s_per_km=normalized["pace_s_per_km"])
        root.destroy()

    mapping.add_left_click_map_command(add)
    mapping.add_right_click_menu_command("撤回上一个点", undo, pass_coords=False)
    for point in candidates:
        mapping.set_marker(*wgs(point["a"], point["o"]), text=point.get("name") or point["id"],
                           marker_color_circle="#e8a030", marker_color_outside="#a36b10")
    focus = controls[0] if controls else wgs(candidates[0]["a"], candidates[0]["o"]) if candidates else (35.0, 105.0)
    mapping.set_position(*focus)
    mapping.set_zoom(17 if controls or candidates else 4)
    ttk.Label(root, textvariable=detail).pack(anchor="w", padx=12, pady=6)
    bottom = ttk.Frame(root, padding=12)
    bottom.pack(fill="x")
    ttk.Checkbutton(bottom, text="首尾相连", variable=closed, command=redraw).pack(side="left")
    ttk.Label(bottom, text="总里程 km").pack(side="left", padx=(18, 4))
    ttk.Entry(bottom, textvariable=km, width=7).pack(side="left")
    ttk.Label(bottom, text="配速 秒/公里").pack(side="left", padx=(18, 4))
    ttk.Entry(bottom, textvariable=pace, width=7).pack(side="left")
    ttk.Button(bottom, text="保存路线", command=save).pack(side="right")
    ttk.Button(bottom, text="清空", command=clear).pack(side="right", padx=8)
    ttk.Button(bottom, text="撤回", command=undo).pack(side="right")
    ttk.Label(root, text="地图 © OpenStreetMap contributors · 橙色标记是候选打卡点，本次分配结果在预约时显示", anchor="e").pack(fill="x", padx=12, pady=(0, 10))
    redraw()
    root.after(100, check_search)
    root.mainloop()
    return result or None
