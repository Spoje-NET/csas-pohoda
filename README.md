# CSAS Statement to Pohoda Importer
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)
![Packaging: deb](https://img.shields.io/badge/packaging-.deb-red?logo=debian&logoColor=white)

This project provides a PHP command to automate the process of downloading bank statements from Česká spořitelna (CSAS) and importing them into Pohoda accounting software.

## Features

- Download bank statements using [csas-statement-tools](https://github.com/VitexSoftware/csas-statement-tools)
- Import statements into Pohoda using [pohoda-abo-importer](https://github.com/Spoje-NET/pohoda-abo-importer)
- Optionally upload the ABO and PDF statements to SharePoint (Microsoft Graph) and attach the PDF link to the imported bank records in Pohoda (direct SQL Server access)
- Command-line interface for easy automation
- Logging by Ease (`EASE_LOGGER`), the JSON report goes to `RESULT_FILE`
- Unit tests included (PHPUnit)

## Requirements

- PHP 8.1+ with [Ease Core](https://github.com/VitexSoftware/EasePHP)
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
| `POHODA_BANK_IDS` | ID (abbreviation) of the bank account in Pohoda, e.g. `CS` (must exist in Pohoda) |
| `POHODA_BANK_CODE` | bank code of the account, `0800` for Česká spořitelna |
| `FROM_DATE`, `TO_DATE` | period `YYYY-MM-DD` |
| `CSAS_STATEMENT_SCOPE` | symbolic period when no dates are given (default `last_month`) |
| `OUTPUT_DIR`, `RESULT_FILE` | where to save statements and the JSON report |
| `OFFICE365_TENANT`, `OFFICE365_SITE`, `OFFICE365_CLIENTID`, `OFFICE365_CLSECRET`, `OFFICE365_PATH` | enable the SharePoint upload (Entra ID app with Graph *Sites.Selected* on the site); `OFFICE365_PATH` is the folder of the PDFs |
| `OFFICE365_PATH_ABO` | folder of the ABO files (default `OFFICE365_PATH`) |
| `SHAREPOINT_PERMANENT_LINK`, `SHAREPOINT_LINK_TYPE`, `SHAREPOINT_LINK_SCOPE` | permanent sharing link (default `true`, `view`, `organization`) or the plain path based URL |
| `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | Pohoda SQL Server; together with SharePoint it attaches the PDF link to the bank records |

```bash
csas-pohoda --from-date 2025-09-01 --to-date 2025-09-30 -e /path/to/.env
csas-pohoda --scope yesterday
csas-pohoda --file statements/27_CZ84..._CZK_2025-08-21.abo-standard   # import without downloading
```

The JSON report contains the downloaded files and the import report of `pohoda-abo-importer`.
Exit codes: `0` success (also when the bank has no statement for the period), `1` download failed, `2` import failed,
`3` SharePoint upload failed (the statements are imported anyway), `4` attaching the links in Pohoda failed.

With SharePoint configured the PDF of every statement is downloaded too and both files are uploaded before the import.
After the import the bank records of each statement are found in the Pohoda database by the transaction marker
`#ABO_<document>_<account>#` which the importer stores in the internal note (`BV.Pozn2`) and the link to the PDF is added
to them as an attachment (`DOC` table). Records which already have the link are left alone, so repeated runs are safe.
Importing the same statement again is safe, already imported movements are skipped.

## Testing

Run unit tests with:

```bash
phpunit
```

## Project Structure

```
bin/csas-pohoda                       # command (wrapper)
src/csas-pohoda.php                   # command line entry point
src/CsasPohoda/StatementSync.php      # download + import + archive logic
src/CsasPohoda/GraphSharePointClient.php # SharePoint upload via Microsoft Graph
src/CsasPohoda/PohodaAttachments.php  # link attachments in the Pohoda SQL database
tests/CsasPohoda/StatementSyncTest.php # unit tests
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

