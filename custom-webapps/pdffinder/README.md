# PDF Finder

A small PHP app for searching PDF files by patient name, chart number, or file name. It runs separately from OSCAR/OpenOSP (typically at **http://localhost:8082**).

## Features

- Index PDFs under folders listed in `config.php` (existing behaviour)
- Rebuild index, search, view in browser, download
- **Optional OSCAR integration** (read-only): search Oscar MariaDB + `OscarDocument/oscar/document` PDFs

When OSCAR integration is **disabled** (default), behaviour is unchanged from the original PDF Finder.

---

## Quick start (PDF Finder only)

1. Edit `config.php` — set `$PDF_DIRECTORIES` to your PDF folders.
2. Open **Rebuild Index** and scan folders.
3. Search from the home page.

---

## Enable OSCAR integration

### 1. Configuration

Copy the example file and edit values:

```bash
cp config.oscar.local.php.example config.oscar.local.php
```

Or set **environment variables** (recommended in Docker):

| Variable | Example | Description |
|----------|---------|-------------|
| `OSCAR_INTEGRATION_ENABLED` | `true` | Turn integration on/off |
| `OSCAR_DB_HOST` | `127.0.0.1` or `db` | MariaDB host (`db` inside Docker network) |
| `OSCAR_DB_PORT` | `3306` | MariaDB port |
| `OSCAR_DB_NAME` | `oscar` | Database name |
| `OSCAR_DB_USER` | `root` | DB user (read-only user preferred in production) |
| `OSCAR_DB_PASSWORD` | *(from `local.env`)* | **Never expose in the browser** |
| `OSCAR_DOCUMENT_PATH` | `/path/to/volumes/OscarDocument` | Host path to OscarDocument root |

Default document files are read from:

`{OSCAR_DOCUMENT_PATH}/oscar/document/`

This matches OpenOSP Docker: `./volumes/OscarDocument/oscar/document/`

### 2. Docker example (pdffinder + OpenOSP)

Run pdffinder on the same Docker network as OpenOSP so `OSCAR_DB_HOST=db` works.

See `docker-compose.example.yml` in this folder. Typical mounts:

- `./` → `/var/www/html` (pdffinder code)
- `../../volumes/OscarDocument` → `/var/lib/OscarDocument` (read-only)
- Pass `MYSQL_ROOT_PASSWORD` from OpenOSP `local.env` as `OSCAR_DB_PASSWORD`

### 3. Disable integration

Set `OSCAR_INTEGRATION_ENABLED=false` or remove `config.oscar.local.php` overrides.

No database connection is attempted when disabled.

---

## Search sources (when OSCAR enabled)

On the search page, choose **Search in**:

| Option | What it does |
|--------|----------------|
| **All sources** | PDF Finder index + Oscar DB + OscarDocument scan |
| **PDF Finder folders only** | Original indexed folders only |
| **OSCAR database** | `demographic` + `document` + `ctl_document` (PDFs on disk must exist) |
| **OscarDocument PDF files** | Filename/path match under `oscar/document` only |

Results show patient name, demographic number (when known), document title, file name, date, source, and **View** / **Download**.

---

## How to test

1. Start OpenOSP (`./openosp start`) and pdffinder (port 8082).
2. Enable OSCAR integration with credentials that match your `local.env`.
3. Ensure sample PDFs exist under `volumes/OscarDocument/oscar/document/` (e.g. run `import/import-bundle.sh --import` on a dev bundle, or copy a test PDF).
4. Search for a patient first name from your Oscar database (e.g. `TRAIN` if using import test data).
5. Click **View** on an Oscar result — PDF opens read-only in the browser.

---

## Security limitations

- **Read-only** — no upload, edit, or delete in OSCAR.
- **Prepared statements** for all Oscar SQL queries.
- **Credentials stay on the server** — not sent to the browser.
- **PDF viewing** is limited to files under `OscarDocument/oscar/document` with `.pdf` extension; path traversal is blocked.
- **Oscar DB search** uses only `document`, `ctl_document`, and `demographic` with an explicit join — no guessed relationships.
- Use a **dedicated read-only** MariaDB user in production if possible.
- Do not use real PHI on unsecured machines.

---

## File layout

```
pdffinder/
  config.php                 # PDF folders + index path (unchanged)
  config.oscar.local.php     # Optional OSCAR overrides (gitignore this)
  includes/
    functions.php            # Core helpers + unified search
    oscar_config.php         # OSCAR settings from env
    oscar.php                # OSCAR search + secure path checks
  view.php / download.php    # PDF Finder indexed files (unchanged)
  view-oscar.php             # OSCAR PDFs only
  download-oscar.php
  docker-compose.example.yml
```

---

## Troubleshooting

| Problem | Check |
|---------|--------|
| OSCAR search shows DB warning | Is MariaDB running? Is `OSCAR_DB_HOST` correct (`127.0.0.1` on host, `db` in Docker)? Password in `local.env`? |
| DB results but View fails | PDF file must exist as `oscar/document/{docfilename}` on disk |
| OscarDocument search empty | `OSCAR_DOCUMENT_PATH` mount and `oscar/document` subfolder |
| Integration still off | `OSCAR_INTEGRATION_ENABLED` must be `true` |

---

## Related

- OpenOSP: http://localhost:8080/oscar
- Import test patients/PDFs: `import/import-bundle.sh` in the open-osp repo (dev only)
