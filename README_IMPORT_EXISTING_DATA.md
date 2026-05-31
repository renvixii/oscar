# Importing Existing Clinic Data into OSCAR / OpenOSP

This guide explains how to **prepare**, **validate**, and **plan** migration of existing clinic data into an OpenOSP environment. It is written for **local development, training, and test clinics first**.

## What this repository provides

| Included | Not included |
|----------|----------------|
| This documentation | Changes to Oscar application source code |
| CSV templates and column rules (`import/templates/`, `import/schema/`) | Ontario EMR DM / BC E2E XML conversion (plan separately) |
| **Validation** script (`import/validate_import_bundle.py`) — files only | Guaranteed support for every Oscar version without schema checks |
| **Import** script (`import/import_to_oscar.py`) — tracked batch load + purge | Production migration without clinic review |
| Example fake bundle (`import/example-bundle/`) | Import of `diagnoses.csv` (no safe single-table mapping) |

**Safest design for OpenOSP:** keep **validation** and **import** as separate steps. The importer writes only to well-understood Oscar tables, tags rows with `OPENOSP_IMPORT`, records every primary key in `openosp_import_tracking` plus a JSON **manifest**, and purges **by batch id only**.

---

## End-to-end workflow (validate → import → verify → purge)

```bash
chmod +x import/validate-bundle.sh import/import-bundle.sh

# 0. Backup (before any real import)
./openosp backup -m

# 1. Validate bundle (no database)
import/validate-bundle.sh /path/to/bundle

# 2. Preview database import
import/import-bundle.sh /path/to/bundle --dry-run --import

# 3. Import (prompts for batch id confirmation; add --force to skip)
import/import-bundle.sh /path/to/bundle --import

# 4. Verify in Oscar at http://localhost:8080/oscar

# 5. Purge this batch only (if needed on dev)
import/import-bundle.sh /path/to/bundle --dry-run --purge --batch-id YOUR-BATCH-ID
import/import-bundle.sh /path/to/bundle --purge --batch-id YOUR-BATCH-ID
```

After step 3, find the manifest at `import/manifests/<batch-id>.json` and updated `id_mapping.csv` in your bundle folder.

- **Do not** use real patient names, health card numbers, or real clinical documents on unsecured laptops.
- **Do not** import real protected health information (PHI) until privacy, legal, and clinic IT review are complete.
- Use **fake or de-identified** training data first (see `import/example-bundle/` and `scripts/sample-data/` for dev-only sample patients).
- Real migrations require **encrypted storage**, access controls, audit logging, and a written rollback plan.

---

## Warning: privacy and production use

## 1. What data can usually be imported

What you can migrate depends on your **old system**, **Oscar version**, and **province/program** (Ontario vs BC features differ). In general:

| Data type | Usually feasible | Typical approach |
|-----------|----------------|----------------|
| **Patient demographics** | Yes | CSV preparation + Oscar demographic import / manual entry / EMR DM XML |
| **Appointments** | Partial | Often CSV or scheduling import; may need manual cleanup |
| **Consultation / encounter notes** | Partial | Plain text in notes tables; formatting may be lossy |
| **Allergies** | Partial | Structured rows if exported cleanly |
| **Medications / prescriptions** | Partial | Active meds easier than full history |
| **Diagnoses / problem list** | Partial | Depends on coding in source system |
| **Lab results (structured)** | Partial | HL7 history vs PDF-only archives |
| **PDF documents** (labs, referrals, imaging, scans) | Yes | File copy + document metadata in Oscar |
| **Billing / claims** | Often separate | Usually not part of initial clinical import |
| **Images (photos)** | Partial | JPEG/PNG with document records |

Oscar stores documents on **disk** (in Docker OpenOSP: `volumes/OscarDocument/oscar/document/`) and links them in database tables such as `document` and `ctl_document`. Clinical rows live in tables such as `demographic`, `appointment`, `casemgmt_note`, `allergies`, `drugs`, and `prescription`—but **you should not hand-edit those tables in production without DBA/vendor support**.

---

## 2. What data needs to be cleaned first

Before any import attempt, clean source extracts:

1. **Remove duplicates** — same patient registered twice, duplicate MRNs.
2. **Normalize dates** — use `YYYY-MM-DD`; fix two-digit years and invalid dates.
3. **Standardize names** — trim spaces; decide how to handle compound last names.
4. **Validate sex/gender fields** — map to values your Oscar build accepts.
5. **Health card / PHN / HIN** — verify format for your province or use clearly marked placeholders in **dev only**.
6. **Provider numbers** — every clinical row needs a valid `provider_no` that exists in Oscar (e.g. bootstrap provider `999998` in local dev).
7. **Encoding** — save CSV as UTF-8; watch for smart quotes and Excel mangling.
8. **De-identify for training** — replace names, addresses, and identifiers when practicing on a laptop.
9. **Split large exports** — pilot with 5–20 patients, then scale up.
10. **Document filenames** — unique, no path characters (`/`, `\`, `..`).

Run the bundle validator after each cleaning pass:

```bash
chmod +x import/validate-bundle.sh
import/validate-bundle.sh /path/to/your-bundle
```

---

## 3. How to prepare patient demographics

Use `import/templates/demographics.csv` as the starting point.

### Required columns

| Column | Meaning |
|--------|---------|
| `old_patient_id` | Stable ID from the **legacy** system (never changes during migration) |
| `first_name` | Given name |
| `last_name` | Family name |
| `sex` | M/F or values your clinic mapping allows |
| `date_of_birth` | `YYYY-MM-DD` |

### Recommended optional columns

`chart_no`, `hin`, address, phone, email, `provider_no` (roster MD), `patient_status`, `import_batch_id`.

### Practices

- Keep `old_patient_id` unique across the file.
- Use `import_batch_id` (e.g. `PILOT-2026-03`) so you can trace a load.
- Prefix training names (`TRAIN_`) so they are obvious in Oscar search.
- After a successful import, record new Oscar `demographic_no` values in `id_mapping.csv` (see section 8).

---

## 4. How to prepare consultation notes

Use `import/templates/consultation_notes.csv`.

| Column | Guidance |
|--------|----------|
| `old_patient_id` | Must match demographics |
| `note_date` | Date of encounter (`YYYY-MM-DD`) |
| `provider_no` | Oscar provider who authored the note |
| `note_text` | Full note body; use plain text. Line breaks are OK inside quoted CSV fields |
| `signed` | `1` or `0` if you track signed state |
| `import_marker` | e.g. `IMPORT_PREP` for training rows |

**Tips:** Export one row per note from the legacy system. Keep original authorship dates. Very long HTML notes may need stripping to text before import. Oscar’s native import paths may package notes inside EMR DM XML rather than raw CSV—CSV here is for **planning and vendor ETL**, not a guaranteed built-in Oscar menu item.

---

## 5. How to prepare appointments

Use `import/templates/appointments.csv`.

| Column | Guidance |
|--------|----------|
| `old_patient_id` | Links to patient |
| `appointment_date` | `YYYY-MM-DD` |
| `start_time` / `end_time` | `HH:MM` or `HH:MM:SS` |
| `provider_no` | Scheduled provider |
| `reason`, `notes`, `status` | Map from legacy codes (document your mapping table) |

Validate time formats with the bundle validator. Historical cancelled appointments may be skipped in a first pass to reduce noise.

---

## 6. Allergies, medications, diagnosis, and labs

### Allergies (`import/templates/allergies.csv`)

- `description` — allergen name
- `reaction`, `type_code` — map from legacy codes
- One row per allergy per patient

### Medications (`import/templates/medications.csv`)

- `drug_name`, `generic_name`, `dosage`, `frequency`, `route`
- `start_date`, `end_date` — `YYYY-MM-DD`
- Import **active** medications first; archive historical meds in a later phase

### Diagnoses (`import/templates/diagnoses.csv`)

- `diagnosis_description`, `diagnosis_date`, optional `icd_code`
- Map problem-list status (active vs resolved) in `status`

### Lab results (`import/templates/lab_results.csv`)

- Structured analyte rows: `test_name`, `result_value`, `units`, `reference_range`
- If the legacy system only has PDFs, put PDFs in section 7 and skip structured lab rows for those tests

**Support note:** OpenOSP does not ship a universal loader for these CSVs into MariaDB. Your clinic may use Oscar’s **Demographic Export/Import (EMR DM / CMS)** XML for Ontario, **E2E-DTC (BC PITO)** where applicable, vendor migration services, or custom ETL reviewed by your Oscar integrator.

---

## 7. How to prepare PDF files (labs, referrals, imaging, scans)

### Folder layout

Place files in a `pdfs/` subdirectory next to your CSV files:

```
my-bundle/
  demographics.csv
  documents.csv
  pdfs/
    smith_cbc_2024-01-10.pdf
    smith_referral_cardio.pdf
```

### Document index (`import/templates/documents.csv`)

| Column | Guidance |
|--------|----------|
| `old_patient_id` | Patient the file belongs to |
| `file_name` | **Filename only** (inside `pdfs/`), not a full path |
| `document_description` | Clear title shown in Oscar (e.g. `Sample CBC Lab Result`) |
| `document_type` | e.g. `lab`, `consult`, `radiology` (match your Oscar document types) |
| `document_class` | Optional subclass (imaging type, etc.) |
| `observation_date` | Date on the report (`YYYY-MM-DD`) |
| `content_type` | Usually `application/pdf` |

### After import into Oscar

Oscar expects files under the configured document directory (OpenOSP Docker: `volumes/OscarDocument/oscar/document/`). Production imports usually:

1. Copy PDFs to the document store with a controlled naming convention.
2. Insert or import **document metadata** so the chart links to the file (`document` + `ctl_document`).

The preparation validator only checks that `pdfs/<file_name>` exists on disk—it does **not** copy files into OscarDocument.

---

## 8. How to map old patient IDs to Oscar patient IDs

Legacy systems use their own MRNs. Oscar assigns **`demographic_no`** (auto-increment) when a patient is created.

### During preparation

- Every CSV row uses **`old_patient_id`** from the legacy export.

### After a successful import

Maintain `import/templates/id_mapping.csv`:

| Column | When to fill |
|--------|----------------|
| `old_patient_id` | From legacy system |
| `oscar_demographic_no` | From Oscar after create (search demographic or export list) |
| `chart_no` | If assigned |
| `imported_at` | Timestamp |
| `import_batch_id` | Same batch id as CSV |
| `notes` | Exceptions, merged charts, etc. |

This file is your **audit trail** for support tickets (“legacy ID 8842 = Oscar demo 915”).

**Never reuse** an `old_patient_id` for a different person. If duplicates were merged in the legacy system, document that in `notes`.

---

## 9. How to test import using sample data first

### Step A — Practice validation only

```bash
import/validate-bundle.sh import/example-bundle
```

The example bundle intentionally references a missing PDF (`missing_xray.pdf`) so you can see how errors are reported. Fix or remove that row in your real bundle.

### Step B — Validate and import on disposable OpenOSP

1. Run OpenOSP locally (`./openosp start`).
2. Bootstrap or restore a **throwaway** database.
3. Use **fake patients** only (`TRAIN_` prefix, `IMPORT_PREP` / `OPENOSP_IMPORT` markers).
4. Run the full workflow in [End-to-end workflow](#end-to-end-workflow-validate--import--verify--purge) above.

### Step C — Try the example bundle

```bash
import/validate-bundle.sh import/example-bundle
import/import-bundle.sh import/example-bundle --dry-run --import
import/import-bundle.sh import/example-bundle --import
# batch id is IMPORT-DEV-001 from the CSV
```

Search Oscar for `TRAIN_DelaCruz` or chart `IMP-1001`.

### Step D — Optional dev seed data

The separate `scripts/seed-sample-data.sh` tool inserts **marked** sample patients for feature testing. It is **not** a legacy importer; do not confuse it with clinic migration.

---

## 10. How to backup before importing

Never import into production without a fresh backup.

### Database and documents (OpenOSP)

From the project root on a running clinic host:

```bash
./openosp backup -m
```

This backs up Oscar database content and OscarDocument-related data per your OpenOSP configuration. For off-site copies, see the main `README.md` (S3, HDC, etc.).

### Also capture

- `volumes/oscar.properties` and other `volumes/` config you rely on
- A list of import batch IDs and row counts
- The validated CSV/PDF bundle (encrypted, access-controlled)

### Before a pilot on dev

- Snapshot the MariaDB volume or run bootstrap on a dedicated test machine so you can wipe and retry.

---

## 11. How to verify imported data inside OSCAR

After import, log in and check **each pilot patient**:

| Check | What to do |
|-------|------------|
| Demographics | Search by last name / chart number; open master record |
| Appointments | Schedule or appointment list for expected dates |
| Notes | Clinical note list; open first and follow-up notes |
| Allergies | Allergy list matches source |
| Medications | Active medication profile |
| Documents | Document manager shows PDFs; open each PDF |
| Provider attribution | Correct MD on notes and documents |
| Counts | Compare row counts to your CSV line counts |

Export a small **post-import report** from Oscar or SQL (read-only) for your migration log: number of demographics created, documents attached, errors skipped.

---

## 12. How to rollback safely if needed

| Situation | Safer action |
|-----------|----------------|
| Pilot on **disposable** dev DB | `import/import-bundle.sh … --purge --batch-id BATCH` or re-bootstrap |
| Wrong **small** batch in test | Purge by batch id (uses manifest + tracking table only) |
| Production import problem | **Restore from backup** taken in section 10 |
| Partial document copy | Restore `volumes/OscarDocument/` from backup |

**Do not** use `scripts/seed-sample-data.sh --delete` on migrated data—it targets different markers (`OPENOSP_SAMPLE_DATA`).

**Do not** purge without the correct `--batch-id` manifest from your import run.

Document the rollback decision: batch id, time, backup file names, who approved restore.

---

## Helper tools in `import/`

### Step 1: `validate_import_bundle.py` (files only)

- Inspects CSV headers and required fields
- Detects duplicate `old_patient_id` and possible duplicate name+DOB
- Validates date and time formats
- Ensures child rows reference known patients
- Checks that each `documents.csv` file exists under `pdfs/`
- **Never** connects to MariaDB

```bash
import/validate-bundle.sh /path/to/bundle
```

### Step 2: `import_to_oscar.py` (database import / purge)

- Runs validator automatically unless `--skip-validation` (not recommended)
- **`--dry-run --import`**: preview tables, row counts, PDF copies (no writes)
- **`--import`**: inserts tracked rows in a transaction; copies PDFs to `volumes/OscarDocument/oscar/document/`; writes manifest
- **`--dry-run --purge --batch-id X`**: preview deletions for batch X only
- **`--purge --batch-id X`**: delete tracked rows + manifest PDFs for batch X only
- Prompts for confirmation on live import/purge unless `--force`
- Creates `openosp_import_tracking` table on first import (dev migration aid; does not change Oscar app code)

```bash
import/import-bundle.sh /path/to/bundle --dry-run --import
import/import-bundle.sh /path/to/bundle --import
import/import-bundle.sh /path/to/bundle --purge --batch-id IMPORT-DEV-001
```

### Tables touched by the importer

`demographic`, `appointment`, `casemgmt_note`, `allergies`, `prescription`, `drugs`, `document`, `ctl_document`, `providerLabRouting`, `patientLabRouting`, `labPatientPhysicianInfo`, `labTestResults`, `openosp_import_tracking`

### Templates and schema

- `import/templates/*.csv` — copy to your working directory
- `import/schema/expected_columns.json` — column rules
- `import/manifests/*.json` — one manifest per successful import (required for purge)

### Production / regional formats

For full clinic migrations, you may still need Oscar’s **Demographic Import (EMR DM XML)**, **E2E-DTC (BC)**, or a vendor ETL. This CSV importer is for **controlled dev/test loads** and small pilots—not a replacement for provincial migration programs.

---

## Related documentation

| Document | Topic |
|----------|--------|
| [README_BASIC_PATIENT_WORKFLOW.md](./README_BASIC_PATIENT_WORKFLOW.md) | Manual UI workflow with a sample patient |
| [import/README.md](./import/README.md) | Directory layout and quick commands |
| [scripts/sample-data/README.md](./scripts/sample-data/README.md) | Dev-only fake seed data (not legacy import) |
| Main [README.md](./README.md) | OpenOSP setup, backup, bootstrap |

---

## Import preparation checklist

- [ ] Legacy export obtained and stored securely  
- [ ] Dev/training copy de-identified  
- [ ] CSV templates filled; `old_patient_id` on every row  
- [ ] PDFs named and listed in `documents.csv`  
- [ ] `import/validate-bundle.sh` passes  
- [ ] `import/import-bundle.sh --dry-run --import` reviewed  
- [ ] Full backup completed (`./openosp backup -m`)  
- [ ] Pilot import on test environment (`import/import-bundle.sh --import`)  
- [ ] Verified in Oscar UI (demographics, notes, meds, PDFs)  
- [ ] Manifest saved (`import/manifests/<batch-id>.json`)  
- [ ] `id_mapping.csv` updated with Oscar `demographic_no`  
- [ ] Production import approved and scheduled  
- [ ] Rollback backup identified (or purge batch id noted for dev)  

---

*OpenOSP import tooling — validate first, import second, purge by batch only. No Oscar application code changes.*
