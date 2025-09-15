# CSAS Statement to Pohoda Importer

This project provides a Python script to automate the process of downloading bank statements from Česká spořitelna (CSAS) and importing them into Pohoda accounting software.

## Features

- Download bank statements using [csas-statement-tools](https://github.com/VitexSoftware/csas-statement-tools)
- Import statements into Pohoda using [pohoda-abo-importer](https://github.com/Spoje-NET/pohoda-abo-importer)
- Command-line interface for easy automation
- Error handling and logging
- Unit tests included

## Requirements

- Python 3.8+
- `csas-statement-tools` Python package
- `pohoda-abo-importer` Python package

## Installation

Install dependencies using pip:

```bash
pip install csas-statement-tools pohoda-abo-importer
```

## Usage

Run the script to download statements and import them into Pohoda:

```bash
python src/statement_sync.py --from-date YYYY-MM-DD --to-date YYYY-MM-DD --output-dir /path/to/output --pohoda-url URL --pohoda-token TOKEN
```

### Arguments

- `--from-date` Start date in `YYYY-MM-DD` format (required)
- `--to-date` End date in `YYYY-MM-DD` format (required)
- `--output-dir` Directory to save downloaded statements (default: `statements`)
- `--pohoda-url` Pohoda API URL (required)
- `--pohoda-token` Pohoda API token (required)

## Testing

Run unit tests with:

```bash
python -m unittest discover tests
```

## Project Structure

```
src/statement_sync.py        # Main script
tests/test_statement_sync.py # Unit tests
```

## License

MIT

## Author

VitexSoftware, Spoje-NET
