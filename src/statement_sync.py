#!/usr/bin/env python3
"""
Download bank statements from Česká spořitelna (CSAS) and import them into Pohoda.

The work is done by two installed tools:

* ``csas-statement-downloader`` (package csas-statement-tools) saves statements in the ABO format
* ``pohoda-abo-importer`` (package pohoda-abo-importer) creates the bank movements in Pohoda via mServer

Everything is configured by environment variables (as provided by MultiFlexi credentials):

* CSAS_API_KEY, CSAS_ACCESS_TOKEN, CSAS_ACCOUNT_IBAN or CSAS_ACCOUNT_UUID, CSAS_SANDBOX_MODE
* POHODA_URL, POHODA_USERNAME, POHODA_PASSWORD, POHODA_ICO, POHODA_BANK_IDS
* FROM_DATE and TO_DATE (YYYY-MM-DD) or CSAS_STATEMENT_SCOPE (e.g. last_month, yesterday)
* OUTPUT_DIR, RESULT_FILE

Exit codes: 0 success (also when there is nothing to import), 1 download failed, 2 import failed.
"""
import argparse
import json
import os
import subprocess
import sys
import tempfile
import time
from datetime import datetime
from pathlib import Path
from typing import Dict, List, Optional, Tuple

DEFAULT_OUTPUT_DIR = "statements"
DEFAULT_SCOPE = "last_month"
STATEMENT_FORMAT = "abo-standard"
DOWNLOADER = "/usr/bin/csas-statement-downloader"
IMPORTER = "/usr/bin/pohoda-abo-importer"

EXIT_OK = 0
EXIT_DOWNLOAD_FAILED = 1
EXIT_IMPORT_FAILED = 2


def load_env_file(env_path: str = ".env") -> dict:
    """Load variables from a .env file (missing file means no variables)."""
    env_vars = {}
    env_file = Path(env_path)
    if env_file.exists():
        with env_file.open() as f:
            for line in f:
                line = line.strip()
                if not line or line.startswith("#"):
                    continue
                if "=" in line:
                    key, value = line.split("=", 1)
                    env_vars[key.strip()] = value.strip()
    return env_vars


def parse_date(value: str) -> str:
    """Validate a YYYY-MM-DD date and return it unchanged."""
    datetime.strptime(value, "%Y-%m-%d")
    return value


def build_scope(from_date: Optional[str], to_date: Optional[str], scope: Optional[str]) -> str:
    """
    Build the period for the downloader.

    A date range wins over a symbolic scope; without both the default ``last_month`` is used.
    """
    if from_date or to_date:
        if not (from_date and to_date):
            raise ValueError("Both FROM_DATE and TO_DATE have to be set")
        start, end = parse_date(from_date), parse_date(to_date)
        if start > end:
            raise ValueError("FROM_DATE is after TO_DATE")
        return f"{start}>{end}"
    return scope or DEFAULT_SCOPE


def download_statements(
    scope: str, output_dir: str, env: Optional[Dict[str, str]] = None
) -> Tuple[Optional[List[str]], Dict]:
    """
    Download ABO statements for the given scope.

    Returns ``(files, info)``; ``files`` is None when the download failed and an empty list
    when the bank has no statement for the period.
    """
    os.makedirs(output_dir, exist_ok=True)
    started = time.time() - 2
    run_env = dict(os.environ if env is None else env)
    run_env["CSAS_STATEMENT_SCOPE"] = scope
    cmd = [DOWNLOADER, f"-d{output_dir}", f"-f{STATEMENT_FORMAT}", "-o", "php://stdout"]
    try:
        result = subprocess.run(cmd, capture_output=True, text=True, env=run_env)
    except OSError as exc:
        return None, {"error": f"Cannot run {DOWNLOADER}: {exc}"}

    info = {"command": " ".join(cmd), "returncode": result.returncode, "scope": scope}
    if result.returncode != 0:
        info["error"] = (result.stderr or result.stdout or "downloader failed").strip()[-2000:]
        return None, info

    files = sorted(
        str(p)
        for p in Path(output_dir).glob(f"*.{STATEMENT_FORMAT}")
        if p.stat().st_mtime >= started
    )
    info["files"] = files
    return files, info


def import_statements(files: List[str], env: Optional[Dict[str, str]] = None) -> Tuple[Optional[dict], Dict]:
    """
    Import ABO files into Pohoda with pohoda-abo-importer.

    Returns ``(report, info)``; ``report`` is None when no report was produced.
    """
    run_env = dict(os.environ if env is None else env)
    with tempfile.NamedTemporaryFile(delete=False, suffix=".json") as tf:
        report_path = tf.name
    cmd = [IMPORTER, *files, "-o", report_path]
    info = {"command": " ".join(cmd)}
    try:
        result = subprocess.run(cmd, capture_output=True, text=True, env=run_env)
    except OSError as exc:
        info["error"] = f"Cannot run {IMPORTER}: {exc}"
        return None, info

    info["returncode"] = result.returncode
    report = None
    try:
        with open(report_path, "r") as f:
            report = json.load(f)
    except (OSError, ValueError):
        info["error"] = (result.stderr or result.stdout or "importer produced no report").strip()[-2000:]
    finally:
        try:
            os.unlink(report_path)
        except OSError:
            pass

    if report is None and result.returncode != 0 and "error" not in info:
        info["error"] = "importer failed"
    return report, info


def build_report(status: str, message: str, **artifacts) -> dict:
    """MultiFlexi style result document."""
    return {
        "status": status,
        "message": message,
        "timestamp": datetime.now().astimezone().isoformat(),
        "artifacts": {k: v for k, v in artifacts.items() if v is not None},
    }


def run(args: argparse.Namespace) -> Tuple[int, dict]:
    """Download and import; return ``(exit code, report)``."""
    env = dict(os.environ)
    if args.env_file:
        env.update(load_env_file(args.env_file))

    try:
        scope = build_scope(
            args.from_date or env.get("FROM_DATE") or None,
            args.to_date or env.get("TO_DATE") or None,
            args.scope or env.get("CSAS_STATEMENT_SCOPE") or None,
        )
    except ValueError as exc:
        return EXIT_DOWNLOAD_FAILED, build_report("error", str(exc))

    output_dir = args.output_dir or env.get("OUTPUT_DIR") or DEFAULT_OUTPUT_DIR
    files, download_info = download_statements(scope, output_dir, env)
    if files is None:
        return EXIT_DOWNLOAD_FAILED, build_report(
            "error", "Failed to download statement(s) from CSAS.", downloader=download_info
        )
    if not files:
        return EXIT_OK, build_report(
            "success", f"No statements for {scope}, nothing to import.", downloader=download_info, statements=[]
        )

    importer_report, import_info = import_statements(files, env)
    if importer_report is None or importer_report.get("status") == "error" or import_info.get("returncode"):
        return EXIT_IMPORT_FAILED, build_report(
            "error",
            (importer_report or {}).get("message", "Failed to import statement(s) to Pohoda."),
            statements=files,
            downloader=download_info,
            importer=import_info,
            importer_report=importer_report,
        )

    return EXIT_OK, build_report(
        importer_report.get("status", "success"),
        importer_report.get("message", "Statement(s) imported to Pohoda."),
        statements=files,
        downloader=download_info,
        importer_report=importer_report,
    )


def main(argv: Optional[List[str]] = None) -> int:
    parser = argparse.ArgumentParser(description="Download CSAS statements and import them to Pohoda.")
    parser.add_argument("--from-date", help="Start date YYYY-MM-DD (env FROM_DATE).")
    parser.add_argument("--to-date", help="End date YYYY-MM-DD (env TO_DATE).")
    parser.add_argument("--scope", help="Symbolic period, e.g. last_month, yesterday (env CSAS_STATEMENT_SCOPE).")
    parser.add_argument("--output-dir", help=f"Directory for statements (env OUTPUT_DIR, default {DEFAULT_OUTPUT_DIR}).")
    parser.add_argument("-e", "--env-file", help="Optional .env file with the configuration.")
    parser.add_argument("-o", "--output", help="Write the JSON report to this file (env RESULT_FILE, default stdout).")
    args = parser.parse_args(argv)

    code, report = run(args)
    document = json.dumps(report, indent=2, ensure_ascii=False)
    destination = args.output or os.environ.get("RESULT_FILE")
    if destination and destination not in ("-", "php://stdout"):
        Path(destination).write_text(document + "\n")
    else:
        print(document)
    return code


if __name__ == "__main__":
    sys.exit(main())
