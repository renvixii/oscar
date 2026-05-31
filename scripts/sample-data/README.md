# OpenOSP sample data seeding (local dev only)

This tooling adds **fake, clearly marked** patients and clinical data to a running OpenOSP Docker stack. It does **not** change Oscar application code, UI, or schema migrations (except creating optional table `openosp_sample_seed_tracking`).

## Markers (safe delete)

| Marker | Usage |
|--------|--------|
| `TEST_*` | Patient `first_name` prefix |
| `SAMPLE_*` | Patient `last_name` prefix |
| `OPENOSP_SAMPLE_DATA` | Notes, document descriptions, lab comments |
| `OPENOSP_SAMPLE_DATA_*.pdf` | Document filenames on disk |

## What gets created

- **5 patients** (4 with PDF documents, 1 with prescriptions + lab lines)
- Appointments, case notes, vitals, allergies, encounters
- Documents linked via `document` + `ctl_document` + routing tables
- **4 PDF files** copied to `volumes/OscarDocument/oscar/document/`

Default provider: `999998` (openodoc).

## Prerequisites

- Stack running: `./openosp start`
- Database bootstrapped: `./openosp bootstrap`
- `local.env` with `MYSQL_ROOT_PASSWORD`

## Usage (from project root)

```bash
chmod +x scripts/seed-sample-data.sh

# Preview insert
scripts/seed-sample-data.sh --dry-run --insert

# Apply insert
scripts/seed-sample-data.sh --insert

# Preview delete
scripts/seed-sample-data.sh --dry-run --delete

# Remove only sample data
scripts/seed-sample-data.sh --delete
```

## Environment overrides

| Variable | Default |
|----------|---------|
| `OPENOSP_DB_CONTAINER` | Auto-detect (`open-osp-db-1`, `open-osp_db_1`, or `docker compose` db service) |
| `MYSQL_ROOT_PASSWORD` | From `local.env` |
| `MYSQL_DATABASE` | `oscar` |
| `OPENOSP_DOCUMENT_DIR` | `./volumes/OscarDocument/oscar/document` |

## File layout

```
scripts/
  seed-sample-data.sh
  sample-data/
    README.md
    generate_sample_pdfs.py
    generated-pdfs/          # created on first run
    sql/
      create_tracking_table.sql
      insert_sample_data.sql
      delete_sample_data.sql
```

## Viewing sample patients in Oscar

Log in at http://localhost:8080/oscar and search demographics for `SAMPLE` or chart numbers `OPENOSP-001` … `OPENOSP-005`.

## Safety

- **Never** runs insert without `--insert` or delete without `--delete`.
- Delete uses `openosp_sample_seed_tracking` plus marker filters; it does not remove non-sample patients.
- Intended for **local development only** — do not run against production clinics.
