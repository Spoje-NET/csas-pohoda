# AGENTS.md

This file provides guidance to WARP (warp.dev) when working with code in this repository.

## Project Overview

CSAS Statement to Pohoda Importer is a PHP orchestration tool that automates downloading bank statements from Česká spořitelna (CSAS) and importing them into Pohoda accounting software. It acts as a bridge between two PHP-based tools:

- **csas-statement-tools**: Downloads bank statements from CSAS API
- **pohoda-abo-importer**: Imports ABO format statements into Pohoda

## Development Commands

### Running the Main Script
```bash
# Basic usage (after composer install)
php src/csas-pohoda.php --from-date 2025-09-01 --to-date 2025-09-15 -e .env

# Import an already downloaded statement
php src/csas-pohoda.php --file statements/a.abo-standard
```

### Testing
```bash
phpunit
```

### Token Management
```bash
# Refresh CSAS access token (requires csas-statement-tools)
# IMPORTANT: CSAS access tokens expire after 5 minutes!
make token
```

**CRITICAL TOKEN EXPIRATION NOTICE**: CSAS access tokens have a very short lifespan of only **5 minutes**. Always run `make token` immediately before executing the statement downloader or running any CSAS operations. The token refresh command shows the expiration time - make sure to complete all operations before that time.

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

**`src/CsasPohoda/StatementSync.php`**: Main orchestration script that:
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

Tests use PHPUnit; `StatementSync::runProcess()` is overridden to script the external tools:
- Test success and failure scenarios
- Located in `tests/` directory

## Development Standards

Based on `.github/copilot-instructions.md`:

- **Language**: All code, comments, and messages in English
- **Documentation**: Include docblocks for all methods and classes with parameters and return types
- **Type Hints**: `declare(strict_types=1)`, typed parameters and return types
- **Error Handling**: Proper exception handling with meaningful error messages
- **Security**: Never expose sensitive information in code
- **Testing**: Create/update unit tests when adding/modifying classes
- **Performance**: Consider optimization where necessary
- **Compatibility**: PHP 8.1+, Debian autoloader (`debian/autoload.php`), no Composer at runtime

## Key Files

- `src/csas-pohoda.php`: Command line entry point
- `src/CsasPohoda/StatementSync.php`: Main application logic
- `src/CsasPohoda/GraphSharePointClient.php`, `PohodaAttachments.php`: SharePoint upload (Graph) and link attachments (PohodaSQL)
- `tests/CsasPohoda/StatementSyncTest.php`: Unit tests
- `multiflexi/csas-pohoda.app.json`: MultiFlexi application configuration
- `.env`: Environment variables (not in repo, created by `make token`)
- `Makefile`: Build and utility commands
- `.github/workflows/php.yml`: CI/CD pipeline