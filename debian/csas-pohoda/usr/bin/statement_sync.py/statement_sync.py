"""
Script to download bank statements using csas-statement-tools and import them into Pohoda using pohoda-abo-importer.

Usage:
    python statement_sync.py --from-date YYYY-MM-DD --to-date YYYY-MM-DD --output-dir /path/to/output --pohoda-url URL --pohoda-token TOKEN

Requirements:
    - csas-statement-tools
    - pohoda-abo-importer
"""
import argparse
import os
import sys
from typing import Optional

import subprocess
import json
from pathlib import Path


# Constants for default values
DEFAULT_OUTPUT_DIR = "statements"

def load_env_file(env_path: str = ".env") -> dict:
    """
    Load environment variables from a .env file.

    Args:
        env_path (str): Path to the .env file.

    Returns:
        dict: Dictionary of environment variables.
    """
    env_vars = {}
    env_file = Path(env_path)
    if env_file.exists():
        with env_file.open() as f:
            for line in f:
                line = line.strip()
                if not line or line.startswith('#'):
                    continue
                if '=' in line:
                    key, value = line.split('=', 1)
                    env_vars[key.strip()] = value.strip()
    return env_vars


def download_statements(from_date: str, to_date: str, output_dir: str) -> Optional[str]:
    """
    Download bank statements using csas-statement-tools.

    Args:
        from_date (str): Start date in YYYY-MM-DD format.
        to_date (str): End date in YYYY-MM-DD format.
        output_dir (str): Directory to save downloaded statements.

    Returns:
        Optional[str]: Path to the downloaded statement file, or None if failed.
    """
    try:
        php_downloader = os.path.expanduser("~/Projects/VitexSoftware/csas-statement-tools/src/csas-statement-downloader.php")
        cmd = [
            "php", php_downloader,
            f"-d{output_dir}",
            "-fabo-standard",
            "-o", "php://stdout"
        ]
        env = os.environ.copy()
        env.update(load_env_file())
        result = subprocess.run(cmd, capture_output=True, text=True, env=env)
        if result.returncode != 0:
            print(f"Error downloading statements: {result.stderr}", file=sys.stderr)
            return None, None
        # Try to parse JSON output if available, else fallback to file scan
        try:
            report = json.loads(result.stdout)
            # Try to get processed_files from artifacts
            processed = report.get("artifacts", {}).get("processed_files", [])
            # Extract file paths from processed_files entries
            import re
            files = []
            for entry in processed:
                m = re.search(r"Processed ([^:]+):", entry)
                if m:
                    files.append(m.group(1))
            print(f"Downloaded statements: {files}")
            return files, report
        except Exception as e:
            print(f"Error parsing downloader report: {e}", file=sys.stderr)
            return None, None
    except Exception as e:
        print(f"Error running csas-statement-downloader: {e}", file=sys.stderr)
        return None, None


def import_statement_to_pohoda(statement_file: str, pohoda_url: str, pohoda_token: str) -> bool:
    """
    Import the statement file into Pohoda using pohoda-abo-importer.

    Args:
        statement_file (str): Path to the statement file.
        pohoda_url (str): Pohoda API URL.
        pohoda_token (str): Pohoda API token.

    Returns:
        bool: True if import was successful, False otherwise.
    """
    try:
        php_importer = os.path.expanduser("~/Projects/SpojeNetIT/pohoda-abo-importer/src/importer.php")
        # Output report to a temp file
        import tempfile
        with tempfile.NamedTemporaryFile(delete=False, suffix=".json") as tf:
            report_path = tf.name
        cmd = [
            "php", php_importer,
            "-o", report_path
        ] + statement_file
        env = os.environ.copy()
        env.update(load_env_file())
        result = subprocess.run(cmd, capture_output=True, text=True, env=env)
        if result.returncode != 0:
            print(f"Error importing statement to Pohoda: {result.stderr}", file=sys.stderr)
            return None, None
        # Read and parse the importer report
        try:
            with open(report_path, "r") as f:
                importer_report = json.load(f)
            print(f"Import result: {importer_report}")
            return importer_report, report_path
        except Exception as e:
            print(f"Error parsing importer report: {e}", file=sys.stderr)
            return None, None
    except Exception as e:
        print(f"Error running pohoda-abo-importer: {e}", file=sys.stderr)
        return None, None


def main():
    """
    Main function to parse arguments and execute statement download and import.
    """
    parser = argparse.ArgumentParser(description="Download CSAS statements and import to Pohoda.")
    parser.add_argument("--from-date", required=True, help="Start date in YYYY-MM-DD format.")
    parser.add_argument("--to-date", required=True, help="End date in YYYY-MM-DD format.")
    parser.add_argument("--output-dir", default=DEFAULT_OUTPUT_DIR, help="Directory to save statements.")
    parser.add_argument("--pohoda-url", required=True, help="Pohoda API URL.")
    parser.add_argument("--pohoda-token", required=True, help="Pohoda API token.")
    args = parser.parse_args()

    import json
    from datetime import datetime

    os.makedirs(args.output_dir, exist_ok=True)
    statement_files, downloader_report = download_statements(args.from_date, args.to_date, args.output_dir)
    report = {
        "timestamp": datetime.now().isoformat(),
        "artifacts": {},
        "metrics": {},
    }
    if not statement_files:
        report["status"] = "error"
        report["message"] = "Failed to download statement(s)."
        if downloader_report:
            report["downloader_report"] = downloader_report
        print(json.dumps(report, indent=2))
        sys.exit(1)
    report["artifacts"]["imported_statement"] = statement_files
    importer_report, importer_report_path = import_statement_to_pohoda(statement_files, args.pohoda_url, args.pohoda_token)
    if not importer_report:
        report["status"] = "error"
        report["message"] = "Failed to import statement(s) to Pohoda."
        if downloader_report:
            report["downloader_report"] = downloader_report
        print(json.dumps(report, indent=2))
        sys.exit(2)
    # Merge reports
    report["status"] = importer_report.get("status", "success")
    report["message"] = importer_report.get("message", "Statement(s) successfully imported to Pohoda.")
    report["artifacts"]["importer_report"] = importer_report
    if downloader_report:
        report["artifacts"]["downloader_report"] = downloader_report
    print(json.dumps(report, indent=2))


if __name__ == "__main__":
    main()
