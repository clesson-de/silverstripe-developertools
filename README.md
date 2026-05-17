# silverstripe-developertools

A collection of developer tools for Silverstripe CMS, accessible directly from the CMS admin panel.

## Features

### Composer Packages

- Lists all installed Composer packages (including dev dependencies)
- Shows name, version, type, license, and description
- Searchable by package name or description
- Sortable columns

## Installation

```bash
composer require clesson-de/silverstripe-developertools
```

Then run:

```bash
vendor/bin/sake dev/build flush=1
```

## Usage

After installation, a **"Packages"** entry appears in the CMS left-hand menu (visible to admins only).

## Requirements

- Silverstripe Framework ^6
- PHP ^8.1

## License

BSD 3-Clause — see [LICENSE](LICENSE).
