#!/usr/bin/env bash
# OpenOSP local development sample data seeder.
# Inserts/deletes ONLY rows marked OPENOSP_SAMPLE_DATA (TEST_* / SAMPLE_* patients).
# Does not modify application source code.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
SAMPLE_DIR="${SCRIPT_DIR}/sample-data"
SQL_DIR="${SAMPLE_DIR}/sql"
PDF_SOURCE_DIR="${SAMPLE_DIR}/generated-pdfs"

SEED_MARKER="${OPENOSP_SEED_MARKER:-OPENOSP_SAMPLE_DATA}"
MYSQL_DATABASE="${MYSQL_DATABASE:-oscar}"
DRY_RUN=0
DO_INSERT=0
DO_DELETE=0

# Planned seed volumes (for dry-run summary)
EXPECTED_PATIENTS=5
EXPECTED_APPOINTMENTS=4
EXPECTED_NOTES=3
EXPECTED_MEASUREMENTS=2
EXPECTED_ALLERGIES=2
EXPECTED_ENCOUNTERS=1
EXPECTED_PRESCRIPTIONS=1
EXPECTED_DRUGS=1
EXPECTED_DOCUMENTS=4
EXPECTED_LAB_INFO=1
EXPECTED_LAB_RESULTS=2

PDF_FILES=(
  "OPENOSP_SAMPLE_DATA_cbc_blood_test.pdf"
  "OPENOSP_SAMPLE_DATA_chest_xray_report.pdf"
  "OPENOSP_SAMPLE_DATA_ultrasound_report.pdf"
  "OPENOSP_SAMPLE_DATA_referral_letter.pdf"
)

PATIENT_NAMES=(
  "TEST_ALICE SAMPLE_JONES"
  "TEST_BOB SAMPLE_SMITH"
  "TEST_CAROL SAMPLE_LEE"
  "TEST_DANA SAMPLE_KIM"
  "TEST_ERIN SAMPLE_WALSH"
)

usage() {
  cat <<EOF
Usage: scripts/seed-sample-data.sh (--insert | --delete) [--dry-run]

  --insert          Insert sample data (required for insert mode)
  --delete          Delete only sample data created by this tool (required for delete mode)
  --dry-run         Preview actions without applying changes

Examples:
  scripts/seed-sample-data.sh --dry-run --insert
  scripts/seed-sample-data.sh --insert
  scripts/seed-sample-data.sh --dry-run --delete
  scripts/seed-sample-data.sh --delete

Environment overrides:
  OPENOSP_DB_CONTAINER   Docker DB container (default: auto-detect)
  MYSQL_ROOT_PASSWORD    DB password (default: from local.env)
  MYSQL_DATABASE         Database name (default: oscar)
  OPENOSP_DOCUMENT_DIR   Host path for Oscar documents
                         (default: ./volumes/OscarDocument/oscar/document)

EOF
}

log() { printf '[seed-sample-data] %s\n' "$*"; }
die() { log "ERROR: $*"; exit 1; }

parse_args() {
  if [[ $# -eq 0 ]]; then
    usage
    exit 1
  fi
  while [[ $# -gt 0 ]]; do
    case "$1" in
      --dry-run) DRY_RUN=1; shift ;;
      --insert) DO_INSERT=1; shift ;;
      --delete) DO_DELETE=1; shift ;;
      -h|--help) usage; exit 0 ;;
      *) die "Unknown argument: $1" ;;
    esac
  done
  if [[ "$DO_INSERT" -eq 0 && "$DO_DELETE" -eq 0 ]]; then
    die "Specify --insert and/or --delete"
  fi
  if [[ "$DO_INSERT" -eq 1 && "$DO_DELETE" -eq 1 ]]; then
    die "Use --insert or --delete in separate runs, not both"
  fi
}

load_env() {
  if [[ -f "${PROJECT_ROOT}/local.env" ]]; then
    # shellcheck disable=SC1091
    set -a
    source "${PROJECT_ROOT}/local.env"
    set +a
  fi
  if [[ -z "${MYSQL_ROOT_PASSWORD:-}" ]]; then
    die "MYSQL_ROOT_PASSWORD not set. Run ./openosp setup or export MYSQL_ROOT_PASSWORD."
  fi
  OPENOSP_DOCUMENT_DIR="${OPENOSP_DOCUMENT_DIR:-${PROJECT_ROOT}/volumes/OscarDocument/oscar/document}"
}

resolve_db_container() {
  if [[ -n "${OPENOSP_DB_CONTAINER:-}" ]]; then
    DB_CONTAINER="$OPENOSP_DB_CONTAINER"
    if ! docker inspect "$DB_CONTAINER" >/dev/null 2>&1; then
      die "Container not found: $DB_CONTAINER"
    fi
    return
  fi

  local candidates=(
    "open-osp-db-1"
    "open-osp_db_1"
  )
  for c in "${candidates[@]}"; do
    if docker inspect "$c" >/dev/null 2>&1; then
      DB_CONTAINER="$c"
      return
    fi
  done

  if [[ -f "${PROJECT_ROOT}/docker-compose.yml" ]]; then
    local cid
    cid="$(cd "${PROJECT_ROOT}" && docker compose ps -q db 2>/dev/null | head -n1 || true)"
    if [[ -n "$cid" ]]; then
      DB_CONTAINER="$(docker inspect -f '{{.Name}}' "$cid" | sed 's#^/##')"
      return
    fi
  fi

  die "Could not find MariaDB container. Set OPENOSP_DB_CONTAINER or start the stack with ./openosp start"
}

assert_container_running() {
  local state
  state="$(docker inspect -f '{{.State.Running}}' "$DB_CONTAINER" 2>/dev/null || echo false)"
  [[ "$state" == "true" ]] || die "Container $DB_CONTAINER is not running"
  log "Using database container: $DB_CONTAINER"
}

mysql_exec() {
  local sql="$1"
  docker exec -i "$DB_CONTAINER" mysql -uroot -p"${MYSQL_ROOT_PASSWORD}" "${MYSQL_DATABASE}" -N -e "$sql"
}

mysql_file() {
  local file="$1"
  docker exec -i "$DB_CONTAINER" mysql -uroot -p"${MYSQL_ROOT_PASSWORD}" "${MYSQL_DATABASE}" <"$file"
}

assert_database_exists() {
  local cnt
  cnt="$(mysql_exec "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='${MYSQL_DATABASE}';")"
  [[ "$cnt" == "1" ]] || die "Database '${MYSQL_DATABASE}' does not exist"
  log "Database '${MYSQL_DATABASE}' found"
}

column_exists() {
  local table="$1" column="$2"
  local cnt
  cnt="$(mysql_exec "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='${MYSQL_DATABASE}' AND TABLE_NAME='${table}' AND COLUMN_NAME='${column}';")"
  [[ "$cnt" == "1" ]]
}

table_exists() {
  local table="$1"
  local cnt
  cnt="$(mysql_exec "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='${MYSQL_DATABASE}' AND TABLE_NAME='${table}';")"
  [[ "$cnt" == "1" ]]
}

validate_schema() {
  local tables=(
    demographic appointment provider casemgmt_note measurements allergies
    prescription drugs encounter document ctl_document providerLabRouting
    patientLabRouting labPatientPhysicianInfo labTestResults
  )
  local missing=0
  for t in "${tables[@]}"; do
    if ! table_exists "$t"; then
      log "MISSING TABLE: $t"
      missing=1
    fi
  done
  [[ "$missing" -eq 0 ]] || die "Required tables missing in ${MYSQL_DATABASE}"

  # Required columns used by insert SQL
  local checks=(
    "demographic:first_name"
    "demographic:last_name"
    "demographic:demographic_no"
    "appointment:appointment_no"
    "appointment:provider_no"
    "casemgmt_note:note_id"
    "casemgmt_note:note"
    "measurements:id"
    "measurements:demographicNo"
    "allergies:allergyid"
    "allergies:DESCRIPTION"
    "prescription:script_no"
    "drugs:drugid"
    "document:document_no"
    "document:docfilename"
    "document:docdesc"
    "document:restrictToProgram"
    "ctl_document:document_no"
    "providerLabRouting:id"
    "patientLabRouting:id"
    "labPatientPhysicianInfo:id"
    "labTestResults:id"
  )
  for pair in "${checks[@]}"; do
    local t="${pair%%:*}" c="${pair##*:}"
    if ! column_exists "$t" "$c"; then
      log "MISSING COLUMN: ${t}.${c}"
      missing=1
    fi
  done
  [[ "$missing" -eq 0 ]] || die "Schema validation failed; database may be an incompatible Oscar version"

  column_exists casemgmt_note appointmentNo || column_exists casemgmt_note appointment_no \
    || die "casemgmt_note missing appointmentNo/appointment_no column"

  local prov_cnt
  prov_cnt="$(mysql_exec "SELECT COUNT(*) FROM provider WHERE provider_no='999998';")"
  [[ "$prov_cnt" -ge 1 ]] || die "Provider 999998 (openodoc) not found; bootstrap the database first"

  log "Schema validation passed"
}

count_existing_sample() {
  EXISTING_PATIENTS="$(mysql_exec "SELECT COUNT(*) FROM demographic WHERE first_name LIKE 'TEST_%' AND last_name LIKE 'SAMPLE_%';" 2>/dev/null || echo 0)"
  EXISTING_DOCS="$(mysql_exec "SELECT COUNT(*) FROM document WHERE docdesc LIKE '%${SEED_MARKER}%';" 2>/dev/null || echo 0)"
  EXISTING_TRACKED="$(mysql_exec "SELECT COUNT(*) FROM openosp_sample_seed_tracking WHERE seed_marker='${SEED_MARKER}';" 2>/dev/null || echo 0)"
}

ensure_pdfs() {
  mkdir -p "${PDF_SOURCE_DIR}"
  if [[ ! -f "${PDF_SOURCE_DIR}/${PDF_FILES[0]}" ]]; then
    log "Generating sample PDF files..."
    if command -v python3 >/dev/null 2>&1; then
      python3 "${SAMPLE_DIR}/generate_sample_pdfs.py" "${PDF_SOURCE_DIR}"
    else
      die "python3 required to generate sample PDFs (missing ${PDF_FILES[0]})"
    fi
  fi
}

copy_pdfs() {
  mkdir -p "${OPENOSP_DOCUMENT_DIR}"
  local copied=0
  for f in "${PDF_FILES[@]}"; do
    local src="${PDF_SOURCE_DIR}/${f}"
    local dst="${OPENOSP_DOCUMENT_DIR}/${f}"
    [[ -f "$src" ]] || die "Missing PDF source: $src"
    if [[ "$DRY_RUN" -eq 1 ]]; then
      log "[dry-run] would copy: $src -> $dst"
    else
      cp -f "$src" "$dst"
      log "Copied PDF: $dst"
    fi
    copied=$((copied + 1))
  done
  return 0
}

remove_pdf_files() {
  for f in "${PDF_FILES[@]}"; do
    local dst="${OPENOSP_DOCUMENT_DIR}/${f}"
    if [[ -f "$dst" ]]; then
      if [[ "$DRY_RUN" -eq 1 ]]; then
        log "[dry-run] would remove file: $dst"
      else
        rm -f "$dst"
        log "Removed PDF: $dst"
      fi
    fi
  done
}

print_insert_preview() {
  log "=== DRY-RUN INSERT PREVIEW ==="
  log "Tables to insert into:"
  printf '  - %s\n' demographic appointment casemgmt_note measurements allergies \
    encounter document ctl_document providerLabRouting patientLabRouting \
    prescription drugs labPatientPhysicianInfo labTestResults \
    openosp_sample_seed_tracking
  log "Planned record counts:"
  log "  patients:        ${EXPECTED_PATIENTS}"
  log "  appointments:    ${EXPECTED_APPOINTMENTS}"
  log "  case notes:      ${EXPECTED_NOTES}"
  log "  measurements:    ${EXPECTED_MEASUREMENTS}"
  log "  allergies:       ${EXPECTED_ALLERGIES}"
  log "  encounters:      ${EXPECTED_ENCOUNTERS}"
  log "  prescriptions:   ${EXPECTED_PRESCRIPTIONS}"
  log "  drugs:           ${EXPECTED_DRUGS}"
  log "  documents (DB):  ${EXPECTED_DOCUMENTS}"
  log "  lab headers:     ${EXPECTED_LAB_INFO}"
  log "  lab result lines: ${EXPECTED_LAB_RESULTS}"
  log "Sample patients:"
  printf '  - %s\n' "${PATIENT_NAMES[@]}"
  log "PDF files to copy into: ${OPENOSP_DOCUMENT_DIR}"
  printf '  - %s\n' "${PDF_FILES[@]}"
  log "Existing sample patients in DB: ${EXISTING_PATIENTS:-0}"
  if [[ "${EXISTING_PATIENTS:-0}" != "0" ]]; then
    log "WARN: sample patients already exist; insert may duplicate unless you --delete first"
  fi
  log "SQL script: ${SQL_DIR}/insert_sample_data.sql ($(wc -l <"${SQL_DIR}/insert_sample_data.sql" | tr -d ' ') lines)"
  log "SQL preview (first 25 lines):"
  sed -n '1,25p' "${SQL_DIR}/insert_sample_data.sql" | sed 's/^/    /'
  log "=== end preview ==="
}

print_delete_preview() {
  log "=== DRY-RUN DELETE PREVIEW ==="
  log "Will delete tracked rows from openosp_sample_seed_tracking (marker: ${SEED_MARKER})"
  log "Will delete marker-tagged rows in clinical/document tables"
  log "Will remove PDF files matching OPENOSP_SAMPLE_DATA_*.pdf from document directory"
  log "Existing sample patients: ${EXISTING_PATIENTS:-0}"
  log "Existing sample documents: ${EXISTING_DOCS:-0}"
  log "Existing tracked rows: ${EXISTING_TRACKED:-0}"
  log "SQL script: ${SQL_DIR}/delete_sample_data.sql"
  log "=== end preview ==="
}

run_insert() {
  if [[ "$DRY_RUN" -eq 1 ]]; then
    print_insert_preview
    ensure_pdfs
    copy_pdfs
    return 0
  fi

  log "Creating tracking table (if needed)..."
  mysql_file "${SQL_DIR}/create_tracking_table.sql"

  ensure_pdfs
  copy_pdfs

  log "Inserting sample data (transactional SQL)..."
  if ! mysql_file "${SQL_DIR}/insert_sample_data.sql"; then
    die "Insert failed; database unchanged if transaction rolled back"
  fi

  log "SUCCESS: sample data inserted"
  mysql_exec "SELECT demographic_no, first_name, last_name, chart_no FROM demographic WHERE first_name LIKE 'TEST_%' AND last_name LIKE 'SAMPLE_%' ORDER BY demographic_no;"
  mysql_exec "SELECT document_no, docdesc, docfilename FROM document WHERE docdesc LIKE '%${SEED_MARKER}%' ORDER BY document_no;"
}

run_delete() {
  if [[ "$DRY_RUN" -eq 1 ]]; then
    print_delete_preview
    remove_pdf_files
    return 0
  fi

  if ! table_exists openosp_sample_seed_tracking; then
    log "Tracking table not found; using marker-based delete only"
  fi

  log "Deleting sample data..."
  if ! mysql_file "${SQL_DIR}/delete_sample_data.sql"; then
    die "Delete failed"
  fi

  remove_pdf_files
  log "SUCCESS: sample data removed"
  mysql_exec "SELECT COUNT(*) AS remaining_sample_patients FROM demographic WHERE first_name LIKE 'TEST_%' AND last_name LIKE 'SAMPLE_%';"
}

main() {
  cd "${PROJECT_ROOT}"
  parse_args "$@"
  load_env
  resolve_db_container
  assert_container_running
  assert_database_exists
  validate_schema
  count_existing_sample

  if [[ "$DO_INSERT" -eq 1 ]]; then
    run_insert
  fi
  if [[ "$DO_DELETE" -eq 1 ]]; then
    run_delete
  fi
}

main "$@"
