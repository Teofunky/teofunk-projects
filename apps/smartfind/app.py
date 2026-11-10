"""SmartFind: окно умного поиска по Mac на основе Claude + Spotlight."""
import os
import socket
import threading
import time

import webview

import cleanup
import engine

HERE = os.path.dirname(os.path.abspath(__file__))
PORT = 47811  # для единственного экземпляра: повторный запуск поднимает уже открытое окно


def already_running():
    try:
        with socket.create_connection(("127.0.0.1", PORT), timeout=0.5) as s:
            s.sendall(b"show")
        return True
    except OSError:
        return False


def activate_app():
    try:
        from AppKit import NSApplication
        from PyObjCTools import AppHelper
        AppHelper.callAfter(lambda: NSApplication.sharedApplication().activateIgnoringOtherApps_(True))
    except Exception:
        pass


def bring_front(window):
    try:
        window.restore()
    except Exception:
        pass
    window.show()
    activate_app()
    try:
        window.evaluate_js("window.focusSearch && window.focusSearch()")
    except Exception:
        pass


def serve(window):
    srv = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
    srv.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
    try:
        srv.bind(("127.0.0.1", PORT))
    except OSError:
        return
    srv.listen(4)
    while True:
        conn, _ = srv.accept()
        with conn:
            try:
                if conn.recv(16) == b"show":
                    bring_front(window)
            except OSError:
                pass


class Api:
    def status(self):
        return {"has_key": bool(engine.get_api_key()), "model": engine.model()}

    def save_key(self, key):
        key = (key or "").strip()
        if not key.startswith("sk-"):
            return {"ok": False, "error": "Ключ API начинается с sk-"}
        try:
            engine.set_api_key(key)
            return {"ok": True}
        except Exception as e:
            return {"ok": False, "error": f"Не удалось сохранить в Связку ключей: {e}"}

    def search(self, query):
        query = (query or "").strip()
        if not query:
            return {"items": []}
        try:
            return engine.search(query)
        except Exception as e:
            engine.log("search error:", repr(e))
            return {"error": str(e)}

    def rerank(self, sid):
        try:
            return engine.rerank(sid) or {}
        except Exception as e:
            engine.log("rerank error:", repr(e))
            return {}

    def thumb(self, path):
        try:
            return engine.thumbnail(path)
        except Exception:
            return None

    def open_item(self, path):
        engine.open_path(path)
        return True

    def reveal_item(self, path):
        engine.reveal_path(path)
        return True

    def copy_item(self, path):
        engine.copy_path(path)
        return True

    def apply_cleanup(self, sid, selections):
        try:
            return cleanup.apply(sid, selections)
        except Exception as e:
            engine.log("apply error:", repr(e))
            return {"error": str(e)}

    def undo(self, run_id=None):
        try:
            return cleanup.undo(run_id)
        except Exception as e:
            engine.log("undo error:", repr(e))
            return {"error": str(e)}

    def history(self):
        try:
            return cleanup.history()
        except Exception:
            return []


def main():
    if already_running():
        return
    window = webview.create_window(
        "SmartFind", os.path.join(HERE, "ui", "index.html"), js_api=Api(),
        width=860, height=640, min_size=(620, 420))

    def on_start():
        threading.Thread(target=serve, args=(window,), daemon=True).start()
        time.sleep(0.4)
        activate_app()

    webview.start(on_start)


if __name__ == "__main__":
    main()
