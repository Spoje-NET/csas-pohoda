"""
Unit tests for statement_sync.py
"""
import argparse
import json
import os
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import MagicMock, patch

sys.path.insert(0, os.path.join(os.path.dirname(__file__), "..", "src"))

import statement_sync as ss


def completed(returncode=0, stdout="", stderr=""):
    result = MagicMock()
    result.returncode = returncode
    result.stdout = stdout
    result.stderr = stderr
    return result


class TestHelpers(unittest.TestCase):
    def test_load_env_file(self):
        with tempfile.TemporaryDirectory() as tmp:
            env = Path(tmp) / ".env"
            env.write_text("A=1\n# comment\n\nB = two=2\n")
            self.assertEqual(ss.load_env_file(str(env)), {"A": "1", "B": "two=2"})
            self.assertEqual(ss.load_env_file(str(Path(tmp) / "missing")), {})

    def test_build_scope_range(self):
        self.assertEqual(ss.build_scope("2025-09-01", "2025-09-15", None), "2025-09-01>2025-09-15")

    def test_build_scope_symbolic_and_default(self):
        self.assertEqual(ss.build_scope(None, None, "yesterday"), "yesterday")
        self.assertEqual(ss.build_scope(None, None, None), ss.DEFAULT_SCOPE)

    def test_build_scope_invalid(self):
        with self.assertRaises(ValueError):
            ss.build_scope("2025-09-01", None, None)
        with self.assertRaises(ValueError):
            ss.build_scope("2025-09-15", "2025-09-01", None)
        with self.assertRaises(ValueError):
            ss.build_scope("01.09.2025", "15.09.2025", None)


class TestSecurity(unittest.TestCase):
    def test_redact_hides_secret_values(self):
        env = {"CSAS_ACCESS_TOKEN": "tok-123456", "POHODA_PASSWORD": "s3cr3tpw", "OTHER": "visible"}
        text = "Authorization: tok-123456 password=s3cr3tpw visible"
        self.assertEqual(ss.redact(text, env), "Authorization: *** password=*** visible")

    def test_private_dir_is_created_with_mode_0700(self):
        with tempfile.TemporaryDirectory() as tmp:
            path = ss.ensure_private_dir(str(Path(tmp) / "a" / "b"))
            self.assertEqual(oct(os.stat(path).st_mode & 0o777), "0o700")

    def test_private_dir_rejects_symlink(self):
        with tempfile.TemporaryDirectory() as tmp:
            target = Path(tmp) / "target"
            target.mkdir()
            link = Path(tmp) / "link"
            link.symlink_to(target)
            with self.assertRaises(OSError):
                ss.ensure_private_dir(str(link))

    def test_private_dir_tightens_open_permissions(self):
        with tempfile.TemporaryDirectory() as tmp:
            d = Path(tmp) / "open"
            d.mkdir(mode=0o777)
            d.chmod(0o777)
            ss.ensure_private_dir(str(d))
            self.assertEqual(oct(os.stat(d).st_mode & 0o777), "0o700")

    @patch("statement_sync.subprocess.run")
    def test_error_text_is_redacted(self, run):
        with tempfile.TemporaryDirectory() as tmp:
            run.return_value = completed(returncode=1, stderr="bad token tok-123456")
            files, info = ss.download_statements("last_month", tmp, {"CSAS_ACCESS_TOKEN": "tok-123456"})
            self.assertNotIn("tok-123456", json.dumps(info))


class TestDownload(unittest.TestCase):
    @patch("statement_sync.subprocess.run")
    def test_download_uses_private_run_dir_and_sets_scope(self, run):
        with tempfile.TemporaryDirectory() as tmp:
            foreign = Path(tmp) / "other-company.abo-standard"
            foreign.write_text("must never be imported")

            def fake(cmd, **kwargs):
                run_dir = Path(cmd[1][2:])
                (run_dir / "1_acc_x_CZK_2025-09-01.abo-standard").write_text("data")
                (run_dir / "report.pdf").write_text("ignored")
                return completed()

            run.side_effect = fake
            files, info = ss.download_statements("2025-09-01>2025-09-15", tmp, {"PATH": "/usr/bin"})
            self.assertEqual([Path(f).name for f in files], ["1_acc_x_CZK_2025-09-01.abo-standard"])
            self.assertNotIn(str(foreign), files)
            cmd = run.call_args[0][0]
            self.assertIn("-fabo-standard", cmd)
            self.assertNotEqual(cmd[1], f"-d{tmp}")
            self.assertTrue(cmd[1].startswith(f"-d{tmp}/run-"))
            self.assertEqual(oct(os.stat(info["run_dir"]).st_mode & 0o777), "0o700")
            self.assertEqual(run.call_args[1]["env"]["CSAS_STATEMENT_SCOPE"], "2025-09-01>2025-09-15")

    @patch("statement_sync.subprocess.run")
    def test_download_no_statements(self, run):
        with tempfile.TemporaryDirectory() as tmp:
            run.return_value = completed(stdout="no statements returned\n")
            files, info = ss.download_statements("last_month", tmp, {})
            self.assertEqual(files, [])

    @patch("statement_sync.subprocess.run")
    def test_download_failure(self, run):
        with tempfile.TemporaryDirectory() as tmp:
            run.return_value = completed(returncode=401, stderr="Unauthorized")
            files, info = ss.download_statements("last_month", tmp, {})
            self.assertIsNone(files)
            self.assertIn("Unauthorized", info["error"])

    @patch("statement_sync.subprocess.run", side_effect=FileNotFoundError("missing"))
    def test_download_tool_missing(self, run):
        with tempfile.TemporaryDirectory() as tmp:
            files, info = ss.download_statements("last_month", tmp, {})
            self.assertIsNone(files)
            self.assertIn("Cannot run", info["error"])


class TestImport(unittest.TestCase):
    @patch("statement_sync.subprocess.run")
    def test_import_reads_report(self, run):
        def fake(cmd, **kwargs):
            Path(cmd[cmd.index("-o") + 1]).write_text(json.dumps({"status": "success", "message": "5 imported"}))
            return completed()

        run.side_effect = fake
        report, info = ss.import_statements(["/tmp/a.abo-standard"], {})
        self.assertEqual(report["status"], "success")
        self.assertEqual(run.call_args[0][0][1], "/tmp/a.abo-standard")

    @patch("statement_sync.subprocess.run")
    def test_import_without_report(self, run):
        run.return_value = completed(returncode=1, stderr="mServer down")
        report, info = ss.import_statements(["/tmp/a.abo-standard"], {})
        self.assertIsNone(report)
        self.assertIn("mServer down", info["error"])


class TestRun(unittest.TestCase):
    def args(self, **kw):
        base = dict(from_date=None, to_date=None, scope=None, output_dir="/tmp/x", env_file=None, output=None)
        base.update(kw)
        return argparse.Namespace(**base)

    @patch("statement_sync.import_statements")
    @patch("statement_sync.download_statements")
    def test_success(self, dl, imp):
        dl.return_value = (["/tmp/x/a.abo-standard"], {"returncode": 0})
        imp.return_value = ({"status": "success", "message": "ok"}, {"returncode": 0})
        code, report = ss.run(self.args(from_date="2025-09-01", to_date="2025-09-15"))
        self.assertEqual(code, ss.EXIT_OK)
        self.assertEqual(report["status"], "success")
        self.assertEqual(dl.call_args[0][0], "2025-09-01>2025-09-15")

    @patch("statement_sync.download_statements", return_value=([], {"returncode": 0}))
    def test_nothing_to_import_is_success(self, dl):
        code, report = ss.run(self.args())
        self.assertEqual(code, ss.EXIT_OK)
        self.assertIn("nothing to import", report["message"])

    @patch("statement_sync.download_statements", return_value=(None, {"error": "x"}))
    def test_download_failure_exit_code(self, dl):
        code, report = ss.run(self.args())
        self.assertEqual(code, ss.EXIT_DOWNLOAD_FAILED)
        self.assertEqual(report["status"], "error")

    @patch("statement_sync.import_statements", return_value=({"status": "error", "message": "bad"}, {"returncode": 1}))
    @patch("statement_sync.download_statements", return_value=(["/tmp/x/a.abo-standard"], {}))
    def test_import_failure_exit_code(self, dl, imp):
        code, report = ss.run(self.args())
        self.assertEqual(code, ss.EXIT_IMPORT_FAILED)
        self.assertEqual(report["message"], "bad")

    def test_invalid_dates(self):
        code, report = ss.run(self.args(from_date="2025-09-01"))
        self.assertEqual(code, ss.EXIT_DOWNLOAD_FAILED)

    def test_report_never_contains_environment(self):
        with patch.dict(os.environ, {"POHODA_PASSWORD": "s3cr3t"}):
            with patch("statement_sync.download_statements", return_value=(None, {"error": "x"})):
                _, report = ss.run(self.args())
        self.assertNotIn("s3cr3t", json.dumps(report))


if __name__ == "__main__":
    unittest.main()
