# Import preparation directory

Tools for **preparing**, **validating**, and **importing** CSV/PDF bundles into a local OpenOSP Oscar database.

| Path | Purpose |
|------|---------|
| [../README_IMPORT_EXISTING_DATA.md](../README_IMPORT_EXISTING_DATA.md) | Full migration guide |
| `templates/` | Empty CSV templates |
| `schema/expected_columns.json` | Column rules for the validator |
| `example-bundle/` | Fake bundle for practice |
| `validate_import_bundle.py` | **Step 1:** file validation only (no DB) |
| `validate-bundle.sh` | Wrapper for validator |
| `import_to_oscar.py` | **Step 2:** import / purge (MariaDB + PDF copy) |
| `import-bundle.sh` | Wrapper for importer |
| `manifests/` | JSON logs per import batch (required for safe purge) |
| `sql/create_import_tracking_table.sql` | Batch tracking table (created on first import) |

## Workflow (always in this order)

```bash
chmod +x import/validate-bundle.sh import/import-bundle.sh

# 1. Validate files (no database access)
import/validate-bundle.sh /path/to/bundle

# 2. Preview import
import/import-bundle.sh /path/to/bundle --dry-run --import

# 3. Real import (requires confirmation; use --force to skip prompt)
import/import-bundle.sh /path/to/bundle --import

# 4. Verify in Oscar UI (search patients, open charts, check documents)

# 5. Preview purge
import/import-bundle.sh /path/to/bundle --dry-run --purge --batch-id YOUR-BATCH-ID

# 6. Purge only this batch (requires confirmation)
import/import-bundle.sh /path/to/bundle --purge --batch-id YOUR-BATCH-ID
```

Batch id comes from `import_batch_id` in `demographics.csv`, or is auto-generated. After import, see `import/manifests/<batch-id>.json`.

## What the importer loads

| CSV | Oscar tables |
|-----|----------------|
| `demographics.csv` | `demographic` |
| `appointments.csv` | `appointment` |
| `consultation_notes.csv` | `casemgmt_note` |
| `allergies.csv` | `allergies` |
| `medications.csv` | `prescription`, `drugs` |
| `documents.csv` + `pdfs/` | `document`, `ctl_document`, routing tables + file copy |
| `lab_results.csv` | `labPatientPhysicianInfo`, `labTestResults`, `patientLabRouting` |
| `diagnoses.csv` | **Not imported** (no safe single-table mapping) |

All imported rows are tagged with `OPENOSP_IMPORT` in text fields and tracked in `openosp_import_tracking`.

## Safety

| Tool | Database | Deletes data |
|------|----------|--------------|
| Validator | Never connects | Never |
| Importer `--dry-run` | Read-only schema checks on live import path | Never |
| Importer `--import` | Inserts tracked rows | Never |
| Importer `--purge` | Deletes **only** rows for `--batch-id` | Yes (that batch only) |

**Before any real import:** run `./openosp backup -m`. Test on local/staging first. Do not use real PHI until reviewed.

## Environment

| Variable | Default |
|----------|---------|
| `MYSQL_ROOT_PASSWORD` | From `local.env` |
| `OPENOSP_DB_CONTAINER` | Auto-detect |
| `MYSQL_DATABASE` | `oscar` |
| `OPENOSP_DOCUMENT_DIR` | `./volumes/OscarDocument/oscar/document` |
