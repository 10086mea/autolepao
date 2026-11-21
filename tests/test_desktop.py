"""Exercise Tk teardown followed by a second event loop, where leaked timers fail."""

import io
import tkinter as tk
from tkinter import ttk
import unittest
from unittest.mock import patch

from PIL import Image

from lepao import desktop
from lepao.storage import Problem


class DesktopTests(unittest.TestCase):
    def root(self):
        try:
            root = desktop.Window()
        except tk.TclError:
            self.skipTest("A Tk display is required")
        root.withdraw()
        root.tk.eval("set ::callback_errors {}; proc bgerror {message} {lappend ::callback_errors $message}")
        self.addCleanup(root.destroy)
        return root

    def pump_second_window(self):
        second = self.root()
        second.after(160, second.quit)
        second.mainloop()
        second.destroy()

    def test_root_and_child_timers_do_not_survive_window_close(self):
        first = self.root()
        child = ttk.Frame(first)
        first.after(80, lambda: None)
        child.after(80, lambda: None)
        first.destroy()
        self.pump_second_window()
        self.assertEqual(first.tk.eval("llength $::callback_errors"), "0")
        self.assertEqual(first.tk.splitlist(first.tk.call("after", "info")), ())

    def test_save_map_then_open_another_window_without_tcl_errors(self):
        first = self.root()
        def children(widget):
            for child in widget.winfo_children():
                yield child
                yield from children(child)
        def save():
            next(w for w in children(first) if isinstance(w, ttk.Button) and w.cget("text") == "保存路线").invoke()
        first.after(60, save)
        # Keep the real map widget and its timer; omit only network worker loops.
        with patch.object(desktop, "window", return_value=first), \
             patch("tkintermapview.TkinterMapView.pre_cache"), \
             patch("tkintermapview.TkinterMapView.load_images_background"):
            result = desktop.draw({"points": [[30, 110], [30.001, 110]], "closed": False})
        self.assertEqual(len(result["points"]), 2)
        self.pump_second_window()
        self.assertEqual(first.tk.eval("llength $::callback_errors"), "0")

    def test_cancel_qr_window_does_not_leave_poll_ui_timers(self):
        first = self.root()
        png = io.BytesIO()
        Image.new("RGB", (32, 32), "white").save(png, format="PNG")
        class Provider:
            def start(self):
                import time
                return {"image": png.getvalue(), "expires": time.time()+60}
            def poll(self, qr):
                return {"state": "waiting"}
        def cancel():
            for child in first.winfo_children():
                for button in child.winfo_children():
                    if isinstance(button, ttk.Button) and button.cget("text") == "取消登录":
                        button.invoke()
                        return
        first.after(120, cancel)
        with patch.object(desktop, "window", return_value=first):
            with self.assertRaisesRegex(Problem, "取消登录"):
                desktop.scan(Provider())
        self.pump_second_window()
        self.assertEqual(first.tk.eval("llength $::callback_errors"), "0")


if __name__ == "__main__":
    unittest.main()
