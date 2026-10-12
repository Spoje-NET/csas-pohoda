# CSAS Statement to Pohoda Importer
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)
![Packaging: deb](https://img.shields.io/badge/packaging-.deb-red?logo=debian&logoColor=white)

This project provides a Python script to automate the process of downloading bank statements from Česká spořitelna (CSAS) and importing them into Pohoda accounting software.

## Features

- Download bank statements using [csas-statement-tools](https://github.com/VitexSoftware/csas-statement-tools)
- Import statements into Pohoda using [pohoda-abo-importer](https://github.com/Spoje-NET/pohoda-abo-importer)
- Command-line interface for easy automation
- Error handling and logging
- Unit tests included

## Requirements

- Python 3.8+
- `csas-statement-tools` (downloads statements in the ABO format)
- `pohoda-abo-importer` (creates the bank movements in Pohoda through mServer)

```bash
sudo apt install csas-pohoda            # command line
sudo apt install multiflexi-csas-pohoda # MultiFlexi application
```

## Usage

All configuration comes from the environment (MultiFlexi credentials *Csas* and *mServer* provide it):

| Variable | Meaning |
|---|---|
| `CSAS_API_KEY`, `CSAS_ACCESS_TOKEN` | CSAS API access (the access token lives only 5 minutes, MultiFlexi renews it before every job) |
| `CSAS_ACCOUNT_IBAN` or `CSAS_ACCOUNT_UUID` | the account to download |
| `POHODA_URL`, `POHODA_USERNAME`, `POHODA_PASSWORD`, `POHODA_ICO` | Pohoda mServer |
| `POHODA_BANK_IDS` | ID (abbreviation) of the bank account in Pohoda |
| `FROM_DATE`, `TO_DATE` | period `YYYY-MM-DD` |
| `CSAS_STATEMENT_SCOPE` | symbolic period when no dates are given (default `last_month`) |
| `OUTPUT_DIR`, `RESULT_FILE` | where to save statements and the JSON report |

```bash
csas-pohoda --from-date 2025-09-01 --to-date 2025-09-30 -e /path/to/.env
csas-pohoda --scope yesterday
```

The JSON report contains the downloaded files and the import report of `pohoda-abo-importer`.
Exit codes: `0` success (also when the bank has no statement for the period), `1` download failed, `2` import failed.
Importing the same statement again is safe, already imported movements are skipped.

## Testing

Run unit tests with:

```bash
python3 -m unittest discover tests
```

## Project Structure

```
bin/csas-pohoda              # command (wrapper)
src/statement_sync.py        # download + import logic
tests/test_statement_sync.py # unit tests
multiflexi/                  # MultiFlexi application definition
```

## License

MIT

## Author

VitexSoftware, Spoje-NET

## MultiFlexi

CSas to Pohoda is ready for run as [MultiFlexi](https://multiflexi.eu) application.
See the full list of ready-to-run applications within the MultiFlexi platform on the [application list page](https://www.multiflexi.eu/apps.php).

[![MultiFlexi App](https://github.com/VitexSoftware/MultiFlexi/blob/main/doc/multiflexi-app.svg)](https://www.multiflexi.eu/apps.php)

