# WARP.md

This file provides guidance to WARP (warp.dev) when working with code in this repository.

## Project Overview

CSAS Statement to Pohoda Importer is a Python orchestration tool that automates downloading bank statements from Česká spořitelna (CSAS) and importing them into Pohoda accounting software. It acts as a bridge between two PHP-based tools:

- **csas-statement-tools**: Downloads bank statements from CSAS API
- **pohoda-abo-importer**: Imports ABO format statements into Pohoda

## Development Commands

### Running the Main Script
```bash
# Basic usage
python src/statement_sync.py --from-date 2025-09-01 --to-date 2025-09-15 --pohoda-url http://localhost:8000 --pohoda-token YOUR_TOKEN

# With custom output directory
python src/statement_sync.py --from-date 2025-09-01 --to-date 2025-09-15 --output-dir /tmp/statements --pohoda-url http://localhost:8000 --pohoda-token YOUR_TOKEN
```

### Testing
```bash
# Run all tests
python -m unittest discover tests

# Run specific test file
python -m unittest tests.test_statement_sync

# Run tests with verbose output
python -m unittest discover tests -v
```

### Token Management
```bash
# Refresh CSAS access token (requires csas-statement-tools)
make token
```

### Docker Operations
```bash
# Build Docker image
make buildimage

# Build multi-arch Docker image and push
make buildx

# Run Docker container with environment variables
make drun
```

## Architecture

### Core Components

**`src/statement_sync.py`**: Main orchestration script that:
- Loads environment variables from `.env` file
- Calls external PHP tools via subprocess
- Processes JSON reports from both tools
- Merges reports into unified output format
- Provides comprehensive error handling

**Key Dependencies**:
- **External PHP Tools**: Located at hardcoded paths (`~/Projects/VitexSoftware/csas-statement-tools/` and `~/Projects/SpojeNetIT/pohoda-abo-importer/`)
- **Environment Variables**: Loaded from `.env` file for authentication tokens

### Data Flow

1. **Download Phase**: Calls `csas-statement-downloader.php` with date range
2. **Processing**: Parses JSON output to extract downloaded file paths
3. **Import Phase**: Calls `importer.php` with downloaded statement files
4. **Reporting**: Merges reports from both tools into unified JSON output

### Configuration

**Environment Variables** (via `.env` file):
- CSAS authentication tokens and credentials
- Pohoda API configuration

**MultiFlexi Integration**: 
- `multiflexi/csas-pohoda.app.json`: Defines application metadata, environment variables, and artifact specifications
- Must conform to MultiFlexi application schema
- Reports must conform to MultiFlexi report schema

### Testing Architecture

Tests use unittest framework with mocking for external dependencies:
- Mock `StatementDownloader` and `PohodaImporter` classes
- Test success and failure scenarios
- Located in `tests/` directory

## Development Standards

Based on `.github/copilot-instructions.md`:

- **Language**: All code, comments, and messages in English
- **Documentation**: Include docblocks for all functions and classes with parameters and return types
- **Type Hints**: Always include type hints for function parameters and return types
- **Error Handling**: Proper exception handling with meaningful error messages
- **Security**: Never expose sensitive information in code
- **Testing**: Create/update unit tests when adding/modifying classes
- **Performance**: Consider optimization where necessary
- **Compatibility**: Ensure compatibility with latest Python version

## Key Files

- `src/statement_sync.py`: Main application logic
- `tests/test_statement_sync.py`: Unit tests
- `multiflexi/csas-pohoda.app.json`: MultiFlexi application configuration
- `.env`: Environment variables (not in repo, created by `make token`)
- `Makefile`: Build and utility commands
- `.github/workflows/php.yml`: CI/CD pipeline (note: mentions PHP but project is Python)