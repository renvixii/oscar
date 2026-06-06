# PDF Finder

A small PHP app for searching PDF files by patient name, chart number, or file name. It runs separately from OSCAR/OpenOSP (typically at **http://localhost:9082** in OpenOSP Docker).

## Features

- Index PDFs under folders listed in `config.php` (local search)
- Rebuild index, search, view in browser, download
- **Optional OSCAR integration** (read-only): search Oscar MariaDB + `OscarDocument/oscar/document` PDFs
- **Optional SMB integration** (read-only): search one or more NAS SMB shares via `smbclient`, with indexes stored locally

When OSCAR and SMB integrations are **disabled**, behaviour matches the original local PDF Finder.

---

## Quick start (local folders only)

1. Edit `config.php` — set `$PDF_DIRECTORIES` to your PDF folders.
2. Open **Rebuild Index** and scan folders.
3. Search from the home page.

---

## SMB integration (read-only NAS shares)

pdffinder can search PDFs on SMB/CIFS shares **without bind mounts** and **without writing anything to the remote share**. All listing is done with `smbclient`; all caches live under `storage/` inside pdffinder.

### 1. Install smbclient

**Docker (pdffinder Dockerfile — already included):**

```dockerfile
RUN apt-get update \
    && apt-get install -y --no-install-recommends smbclient \
    && rm -rf /var/lib/apt/lists/*
```

Rebuild the pdffinder image after changing the Dockerfile.

**Host / WSL:**

```bash
sudo apt-get install smbclient
```

### 2. Configure SMB sources

Copy the example config and edit:

```bash
cp config.smb.local.php.example config.smb.local.php
```

Each source in `$SMB_SOURCES` supports:

| Field | Description |
|-------|-------------|
| `id` | Unique slug (`westmount`, `archive`, …) — used in URLs and index filenames |
| `enabled` | `true` / `false` |
| `label` | Display name in search results (e.g. `WESTMOUNT SMB`) |
| `host` | NAS IP or hostname |
| `share` | SMB share name |
| `username` | Read-only SMB user |
| `password` | **Server-side only** — never shown in the browser |
| `subdirectory` | Optional folder on the share to scope indexing |
| `index_file` | Optional; default `storage/smb_index_{id}.json` |

Example:

```php
$SMB_SOURCES = [
    [
        'id' => 'westmount',
        'enabled' => true,
        'label' => 'WESTMOUNT SMB',
        'host' => '192.168.1.100',
        'share' => 'WESTMOUNT',
        'username' => 'dockerreader',
        'password' => 'your-readonly-password',
        'subdirectory' => '',
    ],
];
```

You can define **multiple sources**; each gets its own local index file.

### 3. Rebuild SMB indexes

1. Open **Rebuild Index** in the UI, or POST to `rebuild-index.php`.
2. Click **Rebuild all enabled SMB indexes** or rebuild one source at a time.

Indexing runs:

```text
smbclient //HOST/SHARE -U username%password -m SMB3 -g -c "recurse; ls *.pdf"
```

(with optional `cd "subdir";` prefix). Results are saved to:

```text
storage/smb_index_{source_id}.json
```

**Nothing is written to the NAS** — no uploads, deletes, renames, or cache files on the share.

### 4. Search and view

- On the search page, choose **All sources**, **All SMB shares**, or a specific SMB source.
- Results show the source **label** (not the full remote path).
- **View** / **Download** stream the PDF through PHP using a safe indexed ID (`smb:source_id:hash`).
- Remote paths and SMB credentials are never exposed in URLs or HTML.

### 5. Diagnostics

Open **test-smb-connection.php** to check:

- `smbclient` installed
- Each source enabled / disabled
- Connection success or failure
- Live PDF count and sample filenames
- Passwords masked

---

## Enable OSCAR integration

### Configuration

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

See `docker-compose.example.yml` for running beside OpenOSP.

---

## Search sources

When OSCAR and/or SMB are enabled, the search page offers:

| Option | What it does |
|--------|----------------|
| **All sources** | Local index + OSCAR + all enabled SMB indexes |
| **PDF Finder folders only** | Local `config.php` folders |
| **OSCAR database** | Oscar `document` / `demographic` tables |
| **OscarDocument PDF files** | Files under `oscar/document` |
| **All SMB shares** | All enabled SMB local indexes |
| **{label} (SMB)** | One configured SMB source |

---

## Security notes

### SMB

- **Read-only by design** — only `ls` / `get` commands are used; no write operations.
- **Credentials stay on the server** — config file is gitignored; diagnostics mask passwords.
- **Shell safety** — all `smbclient` arguments use `escapeshellarg()`; user search queries never enter shell commands.
- **Path safety** — view/download accepts only IDs from the local SMB index; `..` and non-PDF paths are rejected.
- **Local cache only** — indexes under `storage/`; `storage/.htaccess` blocks direct web access to JSON files.

### OSCAR

- Read-only SQL and filesystem access; prepared statements; path checks under `OscarDocument/oscar/document`.

---

## File layout

```
pdffinder/
  config.php                      # Local PDF folders + index path
  config.smb.local.php            # SMB sources (gitignore)
  config.smb.local.php.example
  config.oscar.local.php          # Optional OSCAR overrides
  includes/
    functions.php                 # Core helpers + unified search
    smb_config.php                # SMB settings loader
    smb.php                       # smbclient list/index/stream
    oscar_config.php / oscar.php
  storage/
    pdf_index.json                # Local folder index (gitignore)
    smb_index_{id}.json           # Per-SMB-source cache (gitignore)
  view.php / download.php         # Local indexed files
  view-smb.php / download-smb.php # SMB streamed PDFs
  view-oscar.php / download-oscar.php
  rebuild-index.php               # Local + SMB index rebuild
  test-smb-connection.php         # SMB diagnostics
  test-oscar-connection.php       # OSCAR diagnostics
  Dockerfile                      # php + pdo_mysql + smbclient
```

---

## Troubleshooting

| Problem | Check |
|---------|--------|
| SMB search empty | Rebuild SMB index on **Rebuild Index** page |
| `smbclient not installed` | Rebuild pdffinder Docker image or `apt install smbclient` |
| SMB connection failed | Run `test-smb-connection.php`; verify host, share, user, firewall |
| View fails for SMB PDF | File must exist in local index; rebuild if NAS content changed |
| OSCAR DB warning | MariaDB running? `OSCAR_DB_HOST=db` in Docker? |
| No sources in dropdown | Enable at least one SMB source or OSCAR integration |

---

## Related

- OpenOSP Oscar UI: configured host port (e.g. http://localhost:9080/oscar)
- Import test patients/PDFs: `import/` in the open-osp repo (dev only)
