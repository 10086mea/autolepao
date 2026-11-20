import argparse
from datetime import datetime, timedelta
import logging
import sys
import time

from . import __version__, plan
from .login import QQ, Wechat
from .protocol import device
from .run import Personal
from .storage import Problem, State


def stamp(value):
    return datetime.fromtimestamp(value, plan.BEIJING).strftime("%Y-%m-%d %H:%M:%S")


def ask(question, default=""):
    value = input(question + (f" [{default}]" if default else "") + "：").strip()
    return value or default


def login(personal, provider="wechat"):
    from .desktop import scan
    state = personal.state
    pending = state.get("pending") or {}
    if pending.get("phase") in ("preparing", "running"):
        raise Problem("请先恢复尚未结束的跑步，再重新登录")
    grant = scan(Wechat(state) if provider == "wechat" else QQ(state))
    previous = state.get("account") or {}
    profile = previous.get("device_profile") if previous.get("openid") == grant["openid"] and previous.get("provider") == provider else device()
    account = personal.api.login(grant, profile)
    if pending and account["uid"] != pending.get("uid"):
        raise Problem("请扫描待核对跑步所属的账号，核对完成后再切换账号")
    updates = {"account": account}
    if previous.get("uid") != account["uid"]:
        updates.update(route=None, last_result=None)
    state.update(**updates)
    print(f'登录成功：{account["nickname"]} · 乐跑 ID {account["uid"]}')


def edit_map(personal):
    from .desktop import draw
    account = personal.account()
    response = plan.overview(personal.api, account)
    data = response["data"]
    zones = data.get("run_zone_list") or [{"id": data.get("run_zone_id", data.get("game_id", "")), "name": data.get("run_zone_name", "当前跑区")}]
    if any(not str(z.get("id", "")).isdigit() for z in zones):
        raise Problem("乐跑没有返回可选跑区")
    for i, zone in enumerate(zones, 1):
        print(f'{i}. {zone.get("name") or zone["id"]}')
    choice = ask("选择跑区", "1") if len(zones) > 1 else "1"
    if not choice.isdigit() or not 1 <= int(choice) <= len(zones):
        raise Problem("请选择列表中的跑区")
    zone = zones[int(choice)-1]
    policy = plan.fetch(personal.api, account, str(zone["id"]))
    route = personal.state.get("route") or {}
    if route.get("game_id") != str(zone["id"]):
        route = {}
    result = draw(route, policy["points"], zone.get("name", ""))
    if result:
        result["game_id"] = str(zone["id"])
        personal.state.update(route=result)
        print("路线已保存，下次运行会继续使用。")


def show_result(result):
    value = str(result.get("record_status"))
    label = "有效" if value == "1" else "无效" if value == "0" else "等待乐跑判定"
    print(f'提交完成 · 记录 {result.get("record_id") or "待查询"} · {label}')


def status(personal):
    account = personal.account()
    print(f'{account["nickname"]} · 乐跑 ID {account["uid"]} · 学号 {account["student_num"]}')
    data = plan.overview(personal.api, account)["data"]
    count, target = data.get("total_num"), data.get("term_num")
    print(f'本学期已跑 {count if count is not None else "未知"} 次' + (f" / {target} 次" if target else ""))
    if data.get("term_start_time") and data.get("term_end_time"):
        print(f'学期：{stamp(int(data["term_start_time"]))[:10]} 至 {stamp(int(data["term_end_time"]))[:10]}')
    pending = personal.state.get("pending")
    if pending:
        if personal.reconcile():
            print("已在乐跑记录中核对本次结果。")
        elif pending["phase"] == "running":
            print(f'有待恢复的跑步，预计提交：{stamp(pending["resume_at"])}。选择“开始 / 恢复跑步”继续。')
        else:
            print("上次提交结果尚未确认。请在乐跑核对，程序不会重复提交。")
    if personal.state.get("last_result"):
        show_result(personal.state.get("last_result"))


def wait_until(at, label):
    print(f"{label}：{stamp(at)}（北京时间）")
    print("请保持程序运行；Ctrl+C 返回菜单。")
    while time.time() < at:
        time.sleep(min(30, max(.05, at-time.time())))


def run_once(personal, mode="earliest", requested=None):
    pending = personal.state.get("pending")
    if pending:
        if personal.reconcile():
            print("已核对上次跑步，请再次选择运行以新建任务。")
            return
        if pending["phase"] != "running":
            raise Problem("上次提交结果尚未确认，请先在乐跑核对成绩")
        wait_until(pending["resume_at"], "恢复跑步，预计提交")
        show_result(personal.finish())
        return
    if personal.records():
        raise Problem("今天已有乐跑记录，本次不再重复提交")
    prepared = personal.prepare_plan(mode, requested)
    c, p, slot = prepared["config"], prepared["policy"], prepared["slot"]
    print(f'{p["zone_name"]} · {c["distance_km"]:.2f} km · {c["pace_s_per_km"]:.0f} 秒/公里')
    print("本次打卡点：" + ("、".join(p["name"] or p["id"] for p in c["assigned"]) or "无需打卡"))
    wait_until(slot["start"], "预计开始")
    # A queued plan is checked against fresh rules before any upload.
    personal.begin(prepared)
    pending = personal.state.get("pending")
    wait_until(pending["resume_at"], "跑步已开始，预计提交")
    show_result(personal.finish())


def daily(personal):
    print("每日托管已启动，每天最多一次。关闭程序或按 Ctrl+C 会停止托管。")
    while True:
        account = personal.account()
        data = plan.overview(personal.api, account)["data"]
        if data.get("term_num") and int(data.get("total_num", 0)) >= int(data["term_num"]):
            print("本学期目标已完成，托管结束。")
            return
        if personal.state.get("pending") or not personal.records():
            run_once(personal, "random")
        tomorrow = (datetime.now(plan.BEIJING) + timedelta(days=1)).replace(hour=0, minute=1, second=0, microsecond=0)
        wait_until(tomorrow.timestamp(), "下次检查")


def main(argv=None):
    for stream in (sys.stdout, sys.stderr):
        if hasattr(stream, "reconfigure"):
            stream.reconfigure(encoding="utf-8")
    parser = argparse.ArgumentParser(description="乐跑个人客户端：扫码登录，在地图上画路线。")
    parser.add_argument("action", nargs="?", choices=("login", "map", "status", "run", "daily", "menu"), default="menu")
    parser.add_argument("--qq", action="store_true", help="使用 QQ 扫码登录")
    parser.add_argument("--version", action="version", version="AutoLepao " + __version__)
    args = parser.parse_args(argv)
    try:
        with State() as state:
            logging.basicConfig(filename=state.directory / "diagnostics.log", level=logging.WARNING,
                                format="%(asctime)s %(levelname)s %(message)s", encoding="utf-8")
            personal = Personal(state)
            actions = {"login": lambda: login(personal, "qq" if args.qq else "wechat"),
                       "map": lambda: edit_map(personal), "status": lambda: status(personal),
                       "run": lambda: run_once(personal), "daily": lambda: daily(personal)}
            if args.action != "menu":
                actions[args.action]()
                return 0
            print(f"步道乐跑 {__version__}")
            if not state.get("account"):
                try:
                    actions["login"]()
                except Problem as error:
                    print(str(error))
            while True:
                print("\n1 微信登录    2 QQ 登录    3 地图画线\n4 账号和成绩  5 开始 / 恢复跑步  6 每日托管  0 退出")
                try:
                    choice = ask("选择", "0")
                    if choice == "0":
                        return 0
                    if choice == "1":
                        login(personal)
                    elif choice == "2":
                        login(personal, "qq")
                    elif choice == "3":
                        edit_map(personal)
                    elif choice == "4":
                        status(personal)
                    elif choice == "5":
                        if not state.get("route"):
                            edit_map(personal)
                        mode = ask("1 尽早开始，2 随机时间，3 指定时间", "1")
                        if mode == "3":
                            try:
                                requested = datetime.strptime(ask("北京时间 YYYY-MM-DD HH:MM"), "%Y-%m-%d %H:%M").replace(tzinfo=plan.BEIJING).timestamp()
                            except ValueError:
                                raise Problem("时间格式不正确，例如 2026-12-01 07:30") from None
                            run_once(personal, "fixed", requested)
                        elif mode in ("1", "2"):
                            run_once(personal, "earliest" if mode == "1" else "random")
                        else:
                            raise Problem("请选择 1、2 或 3")
                    elif choice == "6":
                        if not state.get("route"):
                            edit_map(personal)
                        daily(personal)
                except KeyboardInterrupt:
                    print("\n已返回菜单。已开始的跑步会保留，可再次选择恢复。")
                except Problem as error:
                    print(str(error))
    except (Problem, EOFError, KeyboardInterrupt) as error:
        if isinstance(error, Problem):
            print(str(error))
            return 1
        return 0
    except Exception as error:
        # Requests exceptions can contain complete URLs with OAuth credentials.
        # Only the exception class is retained in diagnostics.
        logging.error("unexpected_error class=%s", type(error).__name__)
        print("程序未能完成操作，请查看 data/diagnostics.log，或重新启动程序。")
        return 1
