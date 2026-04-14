# DMARCLens

A self-hosted PHP web application for parsing and visualizing DMARC aggregate reports (RUA). Upload XML reports from your email providers and get a clear picture of your DKIM/SPF alignment across all sending sources.

## Features

- **Dashboard** — aggregate stats across all reports: total messages, fully aligned, partial pass, full fail, and overall pass rate
- **Per-domain summary** — report count, message volume, alignment breakdown, and last report date for each domain
- **Report detail view** — published policy (p=, sp=, pct=, adkim=, aspf=), reporter metadata, source IP breakdown, and full record table
- **Flexible import** — file upload (.xml, .xml.gz, .zip) with drag-and-drop, or paste raw XML directly; multiple files in one upload; duplicate detection
- **Mailbox collector** — `get-email.php` connects via IMAP, pulls `.xml.gz` attachments, imports them, and deletes the emails automatically
- **Zero external dependencies** — plain PHP + SQLite, no Composer, no npm

## Requirements

- PHP 8.0+ with the `pdo_sqlite`, `zlib`, `zip`, and `imap` extensions
- A web server (Apache, Nginx, or PHP's built-in server)

## Quick Start

```bash
git clone <repo-url> dmarc-analyzer
cd dmarc-analyzer
php -S localhost:8080
```

Open http://localhost:8080 in your browser. The SQLite database is created automatically at `data/dmarc.sqlite` on first run.

## File Structure

```
dmarc-analyzer/
├── index.php          # Dashboard
├── upload.php         # Import reports (file upload or XML paste)
├── view.php           # Individual report detail
├── get-email.php      # Mailbox collector (IMAP → import → delete)
├── config.php         # Mail server credentials (not committed)
├── config.php.sample  # Template for config.php
├── includes/
│   ├── db.php         # Database layer (SQLite via PDO, auto-migration)
│   ├── parser.php     # DMARC XML parser (handles .xml, .gz, .zip)
│   └── layout.php     # Shared HTML layout and helper functions
├── assets/
│   └── style.css      # Stylesheet
├── sample/            # Example DMARC XML files
├── data/              # SQLite database (auto-created)
└── uploads/           # Temporary upload staging (auto-created)
```

## Importing Reports

DMARC providers send aggregate reports to the `rua=` address in your DNS record, typically as `.zip` or `.xml.gz` email attachments.

**Via file upload** — go to *Upload* and select one or more `.xml`, `.xml.gz`, or `.zip` files. Drag-and-drop onto the upload area is also supported. Multiple files can be imported in a single submission.

**Via paste** — copy the raw XML content of a report and paste it into the text area on the upload page.

Duplicate reports (matched by `report_id`) are detected and skipped automatically.

**Via mailbox collector** — `get-email.php` fetches reports directly from an IMAP inbox. See [Mailbox Collector](#mailbox-collector) below.

## Mailbox Collector

`get-email.php` connects to an IMAP mailbox, imports every `.xml.gz` attachment it finds, then deletes the email (only if no parse errors occurred).

**Setup:**

```bash
cp config.php.sample config.php
```

Edit `config.php` with your mail server details:

```php
define('MAILBOX',      '{imap.example.com:993/imap/ssl}INBOX');
define('MAILUSERNAME', 'dmarc@example.com');
define('MAILPASSWORD', 'yourpassword');
```

**Run manually** by visiting `get-email.php` in a browser, or schedule it with cron:

```cron
0 * * * * php /path/to/dmarc-analyzer/get-email.php
```

The `imap` PHP extension must be installed (`php-imap` on Debian/Ubuntu, `php-imap` on RHEL/Fedora).

## Database Schema

Two tables, created automatically:

| Table | Key columns |
|-------|-------------|
| `reports` | `report_id`, `org_name`, `domain`, `date_begin`, `date_end`, `policy_p`, `policy_sp`, `policy_pct`, `adkim`, `aspf` |
| `records` | `source_ip`, `count`, `disposition`, `dkim_result`, `spf_result`, `header_from`, `auth_dkim_*`, `auth_spf_*` |

## License

MIT
