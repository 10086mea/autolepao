import copy
from datetime import datetime
import io
import json
import math
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

import requests

from lepao import plan
from lepao.login import QQ, Wechat
from lepao.protocol import ENVELOPES, LOGIN_KEYS, Http, Lepao, RemoteTimeout, aes, compact, sign
from lepao.route import distance, gcj, normalize, track, wgs
from lepao.run import Personal, steps
from lepao.storage import Problem, State

DEVICE = {"mobileModel": "fixture-model", "mobileOsVersion": "14", "mobileDeviceId": "fixture-device", "ostype": "1"}
ACCOUNT = {"uid": "4242", "access_token": "fixture-token", "school_id": "999", "student_num": "fixture-student", "device_profile": DEVICE}
NOW = int(datetime(2026, 12, 1, 12, 0, tzinfo=plan.BEIJING).timestamp())


def before():
    return {"status": 1, "data": {
        "school_id": "999", "run_zone_id": "7", "term_id": "888", "total_num": "4", "term_num": "20",
        "term_start_time": NOW-86400, "term_end_time": NOW+86400*60,
        "run_start_time": 0, "run_end_time": 86400, "week_time": list(range(7)),
        "run_zone_list": [{"id": "7", "name": "Fixture field"}], "set_point_type": 1, "repeat_num": 0,
        "time_rule_arr": [{"start_time": 0, "end_time": 86400, "min_distance": .2, "max_distance": 10,
                           "max_pace": 180, "min_pace": 540, "min_log_num": 1, "point_num_online": 1, "point_num_offline": 0}],
        "run_line_info": {"log_max_distance": 100, "point_min_distance": 0, "point_max_distance1": 3, "point_max_distance2": 3,
                          "point_list": [{"id": "501", "address": "Fixture point", "jingwei": "30,110", "type": 1, "point_type": 1, "is_online": 1}]}}}


def drawn():
    a, o = wgs(30, 110)
    return {"points": [[a, o], [a+.0008, o], [a+.0008, o+.0008], [a, o+.0008]],
            "closed": True, "game_id": "7", "distance_km": .3, "pace_s_per_km": 360}


class FixtureHttp:
    def __init__(self):
        self.calls, self.puts, self.records = [], [], []
        self.before, self.fail_submit = before(), False
        self.local_ip = "192.0.2.20"
        self.session = requests.Session()

    def json(self, method, url, **options):
        if "weixin.qq.com" in url:
            if url.endswith("cgi-bin/token"):
                return {"access_token": "fixture-app-token", "expires_in": 7200}
            if url.endswith("getticket"):
                return {"ticket": "fixture-ticket", "expires_in": 7200}
            if "/connect/l/" in url:
                return {"wx_errcode": 405, "wx_code": "fixture-code"}
            if url.endswith("qrconnect"):
                return {"uuid": "fixture-qr", "qrcode": {"qrcodebase64": "R0lGODk="}}
            if url.endswith("oauth2/access_token"):
                return {"openid": "fixture-openid", "access_token": "fixture-wx-token", "unionid": "fixture-union"}
            if url.endswith("userinfo"):
                return {"nickname": "Fixture nickname"}
            raise AssertionError("Unexpected WeChat endpoint")
        params = options["data"]
        if "key" in params:
            params = json.loads(aes(params["key"], ENVELOPES["PARAMS_NEW_GAME"], True))
        endpoint = url.split("api.php/")[-1]
        self.calls.append((endpoint, params))
        if endpoint == "Login/quickLoginV339":
            return {"status": 1, "data": {"uid": "4242", "access_token": "fixture-token"}}
        if endpoint == "Student/InfoV14":
            return {"status": 1, "data": {"uid": "4242", "school_id": "999", "student_num": "fixture-student"}}
        if endpoint == "Run2/beforeRunV260":
            return copy.deepcopy(self.before)
        if endpoint == "Run/getTimestampV278":
            return {"status": 1, "data": {"str": "fixture-stamp"}}
        if endpoint == "report/getOssSts":
            return {"status": 1, "data": {"bucket": "fixture-bucket", "AccessKeyId": "fixture", "AccessKeySecret": "fixture", "SecurityToken": "fixture"}}
        if endpoint == "Run/stopRunV278":
            if self.fail_submit:
                raise Problem("fixture timeout")
            return {"status": 1, "data": {"id": "1001", "record_status": 1}}
        if endpoint == "Run2/allRecordList":
            return {"status": 1, "data": {"list": self.records if params["page"] == "1" else []}}
        raise AssertionError("Unexpected fixture endpoint: " + endpoint)

    def request(self, method, url, **options):
        self.puts.append((method, url, options))
        return type("Response", (), {"status_code": 200})()


class ProtocolTests(unittest.TestCase):
    def test_utf8_json_is_not_decoded_as_latin1_from_text_plain_header(self):
        http = Http()
        response = requests.Response()
        response.status_code = 200
        response.headers["Content-Type"] = "text/plain"
        response.encoding = "ISO-8859-1"
        response._content = '{"nickname":"测试昵称"}'.encode("utf-8")
        with patch.object(http, "request", return_value=response):
            self.assertEqual(http.json("GET", "https://example.test/")["nickname"], "测试昵称")

    def test_matches_php_envelopes_and_signatures(self):
        golden = json.loads((Path(__file__).parent / "golden.json").read_text(encoding="utf-8"))
        params = {"uid": "1", "nonce": "123457", "openid": "fixture-openid", "accesstoken": "fixture-token",
                  "mobileDeviceId": "fixture-device", "nick_name": "测试昵称", "version": "233", "version_name": "4.3.3"}
        self.assertEqual(sign(params, True, True), golden["new_sign"])
        self.assertEqual(sign(params), golden["old_sign"])
        self.assertEqual(aes("fixture-openid", LOGIN_KEYS[7]), golden["login_field"])
        self.assertEqual(aes(compact(params), ENVELOPES["PARAMS_NEW_GAME"]), golden["request"])
        self.assertEqual(json.loads(aes(golden["response"], ENVELOPES["NEW_GAME"], True))["uid"], "4242")

    def test_all_ten_login_keys_roundtrip(self):
        for key in LOGIN_KEYS:
            self.assertEqual(aes(aes("fixture", key), key, True), "fixture")

    def test_login_then_profile_uses_same_device_and_latest_version(self):
        http, api = FixtureHttp(), None
        api = Lepao(http)
        grant = {"provider": "wechat", "openid": "fixture-openid", "access_token": "fixture-wx-token", "unionid": "fixture-union"}
        account = api.login(grant, DEVICE)
        account = api.profile(account)
        login_params, profile_params = http.calls[0][1], http.calls[1][1]
        self.assertNotIn("token", login_params)
        self.assertEqual(login_params["unionid"], "fixture-union")
        self.assertEqual(aes(login_params["openid"], LOGIN_KEYS[int(login_params["nonce"]) % 10], True), "fixture-openid")
        self.assertEqual(account["student_num"], "fixture-student")
        for p in (login_params, profile_params, api.params(account, {"version": "216", "version_name": "4.1.6"})):
            self.assertEqual(p["version"], "233")
            self.assertEqual(p["mobileDeviceId"], DEVICE["mobileDeviceId"])
        self.assertEqual(profile_params["token"], "fixture-token")

    def test_rejects_cross_account_or_cross_device_parameters(self):
        api = Lepao(FixtureHttp())
        for extra in ({"uid": "99"}, {"student_num": "wrong"}, {"mobileDeviceId": "wrong"}):
            with self.assertRaises(Problem):
                api.params(ACCOUNT, extra)

    def test_network_error_never_displays_oauth_url(self):
        http = Http()
        with patch.object(http.session, "request", side_effect=requests.ConnectionError("https://example.test/?secret=private-fixture")):
            with self.assertRaises(Problem) as error:
                http.json("GET", "https://example.test/")
        self.assertNotIn("private-fixture", str(error.exception))


class AuthTests(unittest.TestCase):
    def test_idle_wechat_long_poll_is_waiting_not_failed_login(self):
        http = FixtureHttp()
        http.json = lambda *a, **k: (_ for _ in ()).throw(RemoteTimeout("fixture idle timeout"))
        with tempfile.TemporaryDirectory() as tmp:
            wx = Wechat(State(tmp), http, {"app_id": "fixture", "app_secret": "fixture"})
            self.assertEqual(wx.poll({"uuid": "fixture-qr"}), {"state": "waiting"})

    def test_relogin_preserves_device_and_does_not_prompt_for_credentials(self):
        from lepao.cli import login
        with tempfile.TemporaryDirectory() as tmp:
            state = State(tmp)
            state.update(account={**ACCOUNT, "provider": "wechat", "openid": "fixture-openid"})
            grant = {"provider": "wechat", "openid": "fixture-openid", "access_token": "fixture-wx-token"}
            personal = Personal(state, Lepao(FixtureHttp()))
            with patch("lepao.desktop.scan", return_value=grant), patch("lepao.cli.Wechat"), patch("builtins.input", side_effect=AssertionError("must not prompt")):
                login(personal)
            self.assertEqual(state.get("account")["device_profile"], DEVICE)

    def test_complete_wechat_flow_without_prompting_for_secret(self):
        with tempfile.TemporaryDirectory() as tmp:
            wx = Wechat(State(tmp), FixtureHttp(), {"app_id": "fixture-app", "app_secret": "fixture-secret"})
            qr = wx.start()
            result = wx.poll(qr)
            self.assertEqual(result["state"], "ok")
            grant = wx.exchange(qr, result["code"])
            self.assertEqual(grant["provider"], "wechat")
            self.assertEqual(grant["unionid"], "fixture-union")
            self.assertEqual(grant["nickname"], "Fixture nickname")
            self.assertTrue((Path(tmp) / "wx-ticket.json").is_file())
            self.assertEqual(wx.start()["image"], qr["image"])

    def test_wechat_rejection_sanitizes_upstream_text(self):
        http = FixtureHttp()
        http.json = lambda *a, **k: {"errcode": 40125, "errmsg": "secret=private-fixture"}
        with tempfile.TemporaryDirectory() as tmp:
            wx = Wechat(State(tmp), http, {"app_id": "fixture", "app_secret": "fixture"})
            with self.assertRaisesRegex(Problem, "40125") as error:
                wx.start()
            self.assertNotIn("private-fixture", str(error.exception))

    def test_qq_callback_parser_and_host_allowlist(self):
        self.assertEqual(QQ.parse_poll("ptuiCB('67','0','','0','scanned','');")["state"], "scanned")
        self.assertEqual(QQ.parse_poll("ptuiCB('0','0','https://graph.qq.com/ok','0','ok','');")["state"], "ok")
        with self.assertRaises(Problem):
            QQ.parse_poll("ptuiCB('19','0','','0','sign of app is error(100044)','');")
        for url in ("https://evil.test/", "https://graph.qq.com.evil.test/", "https://user:pass@graph.qq.com/", "auth://evil.test/"):
            self.assertFalse(QQ.trusted(url, True))
        self.assertTrue(QQ.trusted("auth://tauth.qq.com/#openid=fixture", True))


class GeometryTests(unittest.TestCase):
    def test_conversion_and_outside_china(self):
        source = (39.9, 116.4)
        shifted = gcj(*source)
        restored = wgs(shifted["a"], shifted["o"])
        self.assertAlmostEqual(source[0], restored[0], places=7)
        self.assertAlmostEqual(source[1], restored[1], places=7)
        self.assertEqual(gcj(50, -100), {"a": 50, "o": -100})

    def test_track_has_exact_duration_distance_and_reproducible_speed(self):
        config = normalize(drawn())
        route = track(config, 108)
        self.assertAlmostEqual(route[-1]["elapsed"], 108, places=6)
        self.assertAlmostEqual(route[-1]["distance_m"], 300, places=3)
        self.assertLess(max(distance(a, b) for a, b in zip(route, route[1:])), 4.41)
        self.assertEqual(route, track(config, 108))
        changed = track({**config, "trajectory_seed": config["trajectory_seed"]+1}, 108)
        self.assertNotEqual([p["s"] for p in changed], [p["s"] for p in route])

    def test_invalid_drawings_are_rejected(self):
        for changes in ({"points": []}, {"points": [[30, 110], [30, 110]]}, {"distance_km": "nan"}, {"pace_s_per_km": True}):
            with self.assertRaises(Problem):
                normalize({**drawn(), **changes})


class ScheduleTests(unittest.TestCase):
    def setUp(self):
        self.policy = plan.parse(before(), ACCOUNT, "7")
        self.config = normalize(drawn())

    def test_random_is_always_future_and_finishes_before_deadline(self):
        self.policy["layers"] = [[{"start": 6*3600, "end": 22*3600}]]
        for at in (NOW, NOW+9*3600+50*60):
            for _ in range(30):
                slot = plan.choose(self.policy, self.config, "random", now=at)
                self.assertGreaterEqual(slot["start"], at)
                self.assertLessEqual(slot["end"]+plan.MARGIN, slot["window_end"])

    def test_fixed_late_start_rejected_and_random_moves_to_next_day(self):
        self.policy["layers"] = [[{"start": 6*3600, "end": 22*3600}]]
        at = NOW+9*3600+59*60
        with self.assertRaises(Problem):
            plan.choose(self.policy, self.config, "fixed", at, at)
        slot = plan.choose(self.policy, self.config, "random", at)
        self.assertGreaterEqual(slot["start"], NOW+18*3600)

    def test_assignment_is_separate_from_candidates(self):
        self.policy["points"].append({"id": "502", "name": "other", "a": 30.0005, "o": 110, "type": 2, "point_type": 1, "online": 1})
        assigned = plan.checkpoints(self.policy, self.config, NOW)
        self.assertEqual(len(assigned), 1)
        self.assertEqual(len(self.policy["points"]), 2)

    def test_unsupported_and_incomplete_rules_fail_before_upload(self):
        self.policy["windows"][0]["offline"] = 1
        with self.assertRaises(Problem):
            plan.checkpoints(self.policy, self.config, NOW)
        response = before()
        del response["data"]["run_line_info"]["point_list"][0]["is_online"]
        with self.assertRaises(Problem):
            plan.parse(response, ACCOUNT, "7")


class SubmissionTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.state = State(self.tmp.name)
        self.state.update(account=copy.deepcopy(ACCOUNT), route=drawn())
        self.http = FixtureHttp()
        self.now = NOW
        self.personal = Personal(self.state, Lepao(self.http), lambda: self.now)

    def tearDown(self):
        self.tmp.cleanup()

    def start(self):
        prepared = self.personal.prepare_plan()
        self.personal.begin(prepared)
        return self.state.get("pending")

    def test_end_to_end_upload_wait_submit_and_reload(self):
        pending = self.start()
        self.assertEqual(pending["phase"], "running")
        self.assertEqual(len(self.http.puts), 1)
        put = self.http.puts[0][2]
        self.assertIn("Authorization", put["headers"])
        payload = json.loads(aes(put["data"].decode(), ENVELOPES["NEW_GAME"], True))
        self.assertGreater(len(payload), 50)
        self.assertNotIn("elapsed", payload[0])
        logs = json.loads(pending["params"]["log_data"])
        self.assertEqual(logs[0]["point_id"], 501)
        self.assertEqual(pending["params"]["ip"], "192.0.2.20")
        with self.assertRaises(Problem):
            self.personal.finish()
        self.now = pending["resume_at"]
        result = self.personal.finish()
        self.assertEqual(result["record_status"], 1)
        self.assertIsNone(State(self.tmp.name).get("pending"))
        self.assertEqual(sum(endpoint == "Run/stopRunV278" for endpoint, _ in self.http.calls), 1)

    def test_timeout_is_not_automatically_retried(self):
        pending = self.start()
        self.now = pending["resume_at"]
        self.http.fail_submit = True
        with self.assertRaises(Problem):
            self.personal.finish()
        self.assertEqual(self.state.get("pending")["phase"], "uncertain")
        with self.assertRaises(Problem):
            self.personal.finish()
        self.assertEqual(sum(endpoint == "Run/stopRunV278" for endpoint, _ in self.http.calls), 1)
        self.http.records = [{"id": "1001", "uid": "4242", "type": 1, "start_time": pending["start"], "record_status": 1}]
        self.assertTrue(self.personal.reconcile())
        self.assertIsNone(self.state.get("pending"))

    def test_interruption_during_submit_remains_blocked_after_restart(self):
        pending = self.start()
        self.now = pending["resume_at"]
        original = self.personal.api.call
        def interrupt(endpoint, *args, **kwargs):
            if endpoint == "Run/stopRunV278":
                raise KeyboardInterrupt
            return original(endpoint, *args, **kwargs)
        with patch.object(self.personal.api, "call", side_effect=interrupt):
            with self.assertRaises(KeyboardInterrupt):
                self.personal.finish()
        reloaded = State(self.tmp.name)
        self.assertEqual(reloaded.get("pending")["phase"], "submitting")
        with self.assertRaises(Problem):
            Personal(reloaded, Lepao(self.http), lambda: self.now).finish()

    def test_changed_rules_do_not_start(self):
        prepared = self.personal.prepare_plan()
        self.http.before["data"]["run_line_info"]["log_max_distance"] = 80
        with self.assertRaises(Problem):
            self.personal.begin(prepared)
        self.assertFalse(self.http.puts)
        self.assertIsNone(self.state.get("pending"))

    def test_changed_login_blocks_submission(self):
        pending = self.start()
        self.state.update(account={**ACCOUNT, "access_token": "new-fixture-token"})
        self.now = pending["resume_at"]
        with self.assertRaises(Problem):
            self.personal.finish()
        self.assertFalse(any(e == "Run/stopRunV278" for e, _ in self.http.calls))

    def test_missing_checkpoint_blocks_before_upload(self):
        self.http.before["data"]["run_line_info"]["point_list"][0]["jingwei"] = "30.02,110"
        with self.assertRaises(Problem):
            self.personal.prepare_plan()
        self.assertFalse(self.http.puts)
        self.assertIsNone(self.state.get("pending"))

    def test_atomic_state_and_single_process_lock(self):
        with self.state:
            with self.assertRaises(Problem):
                with State(self.tmp.name):
                    pass
        with State(self.tmp.name):
            pass
        self.assertEqual(steps(108, 300)["list"], [166, 134])


if __name__ == "__main__":
    unittest.main()
