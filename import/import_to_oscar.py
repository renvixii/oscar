#!/usr/bin/env python3
"""
OpenOSP bundle importer — loads validated CSV/PDF bundles into MariaDB.

Workflow:
  1. import/validate-bundle.sh  (required first)
  2. import/import-bundle.sh --dry-run --import
  3. import/import-bundle.sh --import
  4. import/import-bundle.sh --dry-run --purge --batch-id BATCH
  5. import/import-bundle.sh --purge --batch-id BATCH

Uses openosp_import_tracking + JSON manifest for safe purge-by-batch.
Does NOT modify Oscar application source code.
"""

from __future__ import print_function

import argparse
import json
import os
import shutil
import subprocess
import sys
from datetime import datetime

SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, SCRIPT_DIR)

from import_bundle_lib import (  # noqa: E402
    IMPORT_MARKER,
    MANIFESTS_DIR,
    PROJECT_ROOT,
    MysqlClient,
    assert_container_running,
    load_local_env,
    normalize_time,
    parse_dob_parts,
    read_csv,
    resolve_db_container,
    sql_escape,
    write_csv,
)

REQUIRED_TABLES = [
    "demographic", "appointment", "casemgmt_note", "allergies",
    "prescription", "drugs", "document", "ctl_document",
    "providerLabRouting", "patientLabRouting",
    "labPatientPhysicianInfo", "labTestResults",
]

PURGE_TABLE_ORDER = [
    "labTestResults", "labPatientPhysicianInfo", "patientLabRouting",
    "providerLabRouting", "ctl_document", "drugs", "prescription",
    "allergies", "casemgmt_note", "appointment", "document", "demographic",
]


class ImportSummary(object):
    def __init__(self):
        self.counts = {}
        self.files = []
        self.tables = set()
        self.patients = []
        self.batch_id = None

    def add(self, kind, n=1):
        self.counts[kind] = self.counts.get(kind, 0) + n

    def print_report(self, title, dry_run):
        print("\n" + "=" * 72)
        print(title)
        print("=" * 72)
        print("Batch ID: %s" % self.batch_id)
        print("Mode: %s" % ("DRY-RUN (no changes)" if dry_run else "LIVE"))
        print("\nDatabase tables affected:")
        for t in sorted(self.tables):
            print("  - %s" % t)
        print("\nRecords to %s:" % ("create (preview)" if dry_run else "create"))
        for k, v in sorted(self.counts.items()):
            print("  %s: %s" % (k, v))
        if self.files:
            print("\nPDF files to copy: %s" % len(self.files))
            for f in self.files[:15]:
                print("  %s -> %s" % (f["source"], f["dest"]))
            if len(self.files) > 15:
                print("  ... and %s more" % (len(self.files) - 15))
        if self.patients:
            print("\nSample patients:")
            for p in self.patients[:10]:
                print("  %s %s (%s)" % (p.get("first_name"), p.get("last_name"), p.get("old_patient_id")))
        if not dry_run and self.batch_id:
            manifest = os.path.join(MANIFESTS_DIR, "%s.json" % self.batch_id)
            print("\nManifest will be saved to: %s" % manifest)
        print("=" * 72)


def run_validator(bundle_dir, strict=False):
    validator = os.path.join(SCRIPT_DIR, "validate_import_bundle.py")
    cmd = [sys.executable, validator, bundle_dir, "--dry-run"]
    if strict:
        cmd.append("--strict")
    print("Running bundle validator first...")
    result = subprocess.run(cmd, cwd=PROJECT_ROOT)
    if result.returncode != 0:
        raise RuntimeError(
            "Bundle validation failed. Fix errors with validate-bundle.sh before importing."
        )
    print("Validator: PASS\n")


def resolve_batch_id(bundle_dir, explicit=None):
    if explicit:
        return explicit
    path = os.path.join(bundle_dir, "demographics.csv")
    _, rows = read_csv(path)
    batch_ids = set()
    for _, row in rows:
        if row.get("import_batch_id"):
            batch_ids.add(row["import_batch_id"])
    if len(batch_ids) == 1:
        return batch_ids.pop()
    if len(batch_ids) > 1:
        raise RuntimeError(
            "Multiple import_batch_id values in demographics.csv; pass --batch-id explicitly."
        )
    return "OPENOSP-IMPORT-%s" % datetime.now().strftime("%Y%m%d-%H%M%S")


def load_bundle(bundle_dir):
    data = {"demographics": [], "appointments": [], "notes": [], "allergies": [],
            "medications": [], "documents": [], "lab_results": []}
    path = os.path.join(bundle_dir, "demographics.csv")
    if not os.path.isfile(path):
        raise RuntimeError("demographics.csv is required")
    _, data["demographics"] = read_csv(path)
    optional = [
        ("appointments.csv", "appointments"),
        ("consultation_notes.csv", "notes"),
        ("allergies.csv", "allergies"),
        ("medications.csv", "medications"),
        ("documents.csv", "documents"),
        ("lab_results.csv", "lab_results"),
    ]
    for fname, key in optional:
        fpath = os.path.join(bundle_dir, fname)
        if os.path.isfile(fpath):
            _, data[key] = read_csv(fpath)
    if os.path.isfile(os.path.join(bundle_dir, "diagnoses.csv")):
        print("NOTE: diagnoses.csv is present but not imported (no safe single-table mapping).")
    return data


def plan_import(bundle_dir, data, batch_id):
    s = ImportSummary()
    s.batch_id = batch_id
    s.tables.update(["openosp_import_tracking", "demographic"])
    for _, row in data["demographics"]:
        s.add("demographic", 1)
        s.patients.append(row)
    if data["appointments"]:
        s.tables.add("appointment")
        s.add("appointment", len(data["appointments"]))
    if data["notes"]:
        s.tables.add("casemgmt_note")
        s.add("casemgmt_note", len(data["notes"]))
    if data["allergies"]:
        s.tables.add("allergies")
        s.add("allergies", len(data["allergies"]))
    if data["medications"]:
        s.tables.update(["prescription", "drugs"])
        s.add("prescription", len(data["medications"]))
        s.add("drugs", len(data["medications"]))
    if data["documents"]:
        s.tables.update(["document", "ctl_document", "providerLabRouting", "patientLabRouting"])
        s.add("document", len(data["documents"]))
        pdf_dir = os.path.join(bundle_dir, "pdfs")
        safe_batch = batch_id.replace("/", "_").replace(" ", "_")
        doc_dir = os.environ.get(
            "OPENOSP_DOCUMENT_DIR",
            os.path.join(PROJECT_ROOT, "volumes", "OscarDocument", "oscar", "document"),
        )
        for _, row in data["documents"]:
            src = os.path.join(pdf_dir, row["file_name"])
            dest_name = "OPENOSP_IMPORT_%s_%s" % (safe_batch, row["file_name"])
            dest = os.path.join(doc_dir, dest_name)
            s.files.append({"source": src, "dest": dest, "row": row})
    if data["lab_results"]:
        s.tables.update(["labPatientPhysicianInfo", "labTestResults", "patientLabRouting"])
        groups = set()
        for _, row in data["lab_results"]:
            groups.add((row["old_patient_id"], row.get("collection_date", "")))
        s.add("labPatientPhysicianInfo", len(groups))
        s.add("labTestResults", len(data["lab_results"]))
        s.add("patientLabRouting (lab)", len(groups))
    return s


def confirm_action(action, batch_id, force):
    if force:
        return
    print("\n*** CONFIRMATION REQUIRED ***")
    if action == "import":
        prompt = "Type batch id '%s' to proceed with IMPORT: " % batch_id
        expected = batch_id
    else:
        prompt = "Type 'PURGE %s' to proceed with DELETE: " % batch_id
        expected = "PURGE %s" % batch_id
    try:
        answer = input(prompt).strip()
    except EOFError:
        answer = ""
    if answer != expected:
        raise RuntimeError("Confirmation failed — aborted.")


def validate_db_schema(db):
    missing = [t for t in REQUIRED_TABLES if not db.table_exists(t)]
    if missing:
        raise RuntimeError("Missing required tables: %s" % ", ".join(missing))
    if not db.column_exists("casemgmt_note", "appointmentNo"):
        raise RuntimeError("casemgmt_note.appointmentNo column missing — unsupported schema")
    prov = db.query_scalar("SELECT COUNT(*) FROM provider WHERE provider_no='999998'")
    if prov == "0":
        print("WARN: provider 999998 not found; ensure provider_no values in CSV exist.")


def tagged_text(text, marker_col=None):
    body = text or ""
    if marker_col and marker_col in body:
        return body
    if IMPORT_MARKER in body:
        return body
    return "%s\n%s" % (IMPORT_MARKER, body)


class BundleImporter(object):
    def __init__(self, db, bundle_dir, batch_id, doc_dir, dry_run, demographics_rows):
        self.db = db
        self.bundle_dir = bundle_dir
        self.batch_id = batch_id
        self.doc_dir = doc_dir
        self.dry_run = dry_run
        self.demographics_rows = demographics_rows
        self.id_map = {}
        self.manifest = {
            "import_batch_id": batch_id,
            "bundle_dir": os.path.abspath(bundle_dir),
            "imported_at": datetime.now().isoformat(),
            "import_marker": IMPORT_MARKER,
            "id_mapping": {},
            "records": [],
            "files": [],
        }

    def _track(self, table, pk_column, pk_value, extra_ref=None):
        self.manifest["records"].append({
            "table_name": table, "pk_column": pk_column,
            "pk_value": str(pk_value), "extra_ref": extra_ref or "",
        })
        if not self.dry_run:
            self.db.track(self.batch_id, table, pk_column, pk_value, extra_ref)

    def import_all(self, data):
        if not self.dry_run:
            self.db.exec_file(os.path.join(SCRIPT_DIR, "sql", "create_import_tracking_table.sql"))
            self.db.exec_script("START TRANSACTION;")
        try:
            self._import_demographics(data["demographics"])
            self._import_appointments(data["appointments"])
            self._import_notes(data["notes"])
            self._import_allergies(data["allergies"])
            self._import_medications(data["medications"])
            self._import_documents(data["documents"])
            self._import_labs(data["lab_results"])
            if not self.dry_run:
                self.db.exec_script("COMMIT;")
                self._write_manifest()
                self._write_id_mapping()
        except Exception:
            if not self.dry_run:
                try:
                    self.db.exec_script("ROLLBACK;")
                except RuntimeError:
                    pass
            raise

    def _import_demographics(self, rows):
        now = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
        for _, row in rows:
            old_id = row["old_patient_id"]
            y, m, d = parse_dob_parts(row["date_of_birth"])
            provider = row.get("provider_no") or "999998"
            status = row.get("patient_status") or "AC"
            pref = row.get("pref_name") or row.get("first_name", "")
            if self.dry_run:
                self.id_map[old_id] = "(new)"
                continue
            sql = (
                "INSERT INTO demographic (first_name, last_name, address, city, province, postal, "
                "phone, phone2, email, year_of_birth, month_of_birth, date_of_birth, hin, ver, "
                "chart_no, provider_no, sex, eff_date, lastUpdateDate, pref_name, patient_status) "
                "VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, CURDATE(), %s, %s, %s);"
                % (
                    sql_escape(row.get("first_name")), sql_escape(row.get("last_name")),
                    sql_escape(row.get("address", "")), sql_escape(row.get("city", "")),
                    sql_escape(row.get("province", "")), sql_escape(row.get("postal", "")),
                    sql_escape(row.get("phone", "")), sql_escape(row.get("phone2", "")),
                    sql_escape(row.get("email", "")),
                    sql_escape(y), sql_escape(m), sql_escape(d),
                    sql_escape(row.get("hin", "")), sql_escape(row.get("hin_version", "")),
                    sql_escape(row.get("chart_no", "")), sql_escape(provider),
                    sql_escape(row.get("sex", "")), sql_escape(now),
                    sql_escape(pref), sql_escape(status),
                )
            )
            demo_no = self.db.insert_returning_id(sql)
            self.id_map[old_id] = demo_no
            self.manifest["id_mapping"][old_id] = demo_no
            self._track("demographic", "demographic_no", demo_no, old_id)

    def _demo_no(self, old_id):
        if old_id not in self.id_map:
            raise RuntimeError("Unknown old_patient_id: %s" % old_id)
        return self.id_map[old_id]

    def _demo_row(self, old_id):
        for _, row in self.demographics_rows:
            if row["old_patient_id"] == old_id:
                return row
        return {}

    def _import_appointments(self, rows):
        for _, row in rows:
            demo = self._demo_no(row["old_patient_id"])
            if self.dry_run:
                continue
            provider = row.get("provider_no") or "999998"
            dr = self._demo_row(row["old_patient_id"])
            name = "%s, %s" % (dr.get("last_name", ""), dr.get("first_name", ""))
            sql = (
                "INSERT INTO appointment (provider_no, appointment_date, start_time, end_time, "
                "demographic_no, name, notes, reason, status, type, creator) VALUES "
                "(%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s);"
                % (
                    sql_escape(provider), sql_escape(row["appointment_date"]),
                    sql_escape(normalize_time(row["start_time"])),
                    sql_escape(normalize_time(row["end_time"])),
                    sql_escape(demo), sql_escape(name),
                    sql_escape(tagged_text(row.get("notes", ""))),
                    sql_escape(row.get("reason", "")),
                    sql_escape(row.get("status") or "t"),
                    sql_escape(row.get("appointment_type") or "regular"),
                    sql_escape(provider),
                )
            )
            appt_no = self.db.insert_returning_id(sql)
            self._track("appointment", "appointment_no", appt_no, row["old_patient_id"])

    def _import_notes(self, rows):
        for _, row in rows:
            demo = self._demo_no(row["old_patient_id"])
            if self.dry_run:
                continue
            provider = row.get("provider_no") or "999998"
            note = tagged_text(row.get("note_text", ""), row.get("import_marker"))
            signed = row.get("signed") or "1"
            sql = (
                "INSERT INTO casemgmt_note (demographic_no, provider_no, note, signed, program_no) "
                "VALUES (%s, %s, %s, %s, 0);"
                % (sql_escape(demo), sql_escape(provider), sql_escape(note), sql_escape(signed))
            )
            note_id = self.db.insert_returning_id(sql)
            self._track("casemgmt_note", "note_id", note_id, row["old_patient_id"])

    def _import_allergies(self, rows):
        for _, row in rows:
            demo = self._demo_no(row["old_patient_id"])
            if self.dry_run:
                continue
            provider = row.get("provider_no") or "999998"
            desc = tagged_text(row.get("description", ""))
            archived = row.get("archived") or "0"
            sql = (
                "INSERT INTO allergies (demographic_no, DESCRIPTION, reaction, TYPECODE, archived, providerNo) "
                "VALUES (%s, %s, %s, %s, %s, %s);"
                % (
                    sql_escape(demo), sql_escape(desc),
                    sql_escape(row.get("reaction", "")),
                    sql_escape(row.get("type_code") or "AA"),
                    sql_escape(archived), sql_escape(provider),
                )
            )
            allergy_id = self.db.insert_returning_id(sql)
            self._track("allergies", "allergyid", allergy_id, row["old_patient_id"])

    def _import_medications(self, rows):
        now = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
        for _, row in rows:
            demo = self._demo_no(row["old_patient_id"])
            if self.dry_run:
                continue
            provider = row.get("provider_no") or "999998"
            rx_text = tagged_text(row.get("drug_name", ""))
            sql_rx = (
                "INSERT INTO prescription (provider_no, demographic_no, date_prescribed, textView, "
                "rx_comments, lastUpdateDate) VALUES (%s, %s, %s, %s, %s, %s);"
                % (
                    sql_escape(provider), sql_escape(demo),
                    sql_escape(row.get("start_date")), sql_escape(rx_text),
                    sql_escape(IMPORT_MARKER), sql_escape(now),
                )
            )
            script_no = self.db.insert_returning_id(sql_rx)
            self._track("prescription", "script_no", script_no, row["old_patient_id"])
            end = row.get("end_date") or row.get("start_date")
            sql_drug = (
                "INSERT INTO drugs (provider_no, demographic_no, rx_date, end_date, BN, GN, script_no, "
                "GCN_SEQNO, archived, nosubs, prn, position, lastUpdateDate, dispenseInternal, comment, special) "
                "VALUES (%s, %s, %s, %s, %s, %s, %s, 0, 0, 0, 0, 1, %s, 0, %s, %s);"
                % (
                    sql_escape(provider), sql_escape(demo),
                    sql_escape(row.get("start_date")), sql_escape(end),
                    sql_escape(row.get("drug_name")), sql_escape(row.get("generic_name", "")),
                    sql_escape(script_no), sql_escape(now),
                    sql_escape(IMPORT_MARKER), sql_escape(row.get("instructions", "")),
                )
            )
            drug_id = self.db.insert_returning_id(sql_drug)
            self._track("drugs", "drugid", drug_id, row["old_patient_id"])

    def _import_documents(self, rows):
        now = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
        pdf_dir = os.path.join(self.bundle_dir, "pdfs")
        safe_batch = self.batch_id.replace("/", "_").replace(" ", "_")
        os.makedirs(self.doc_dir, exist_ok=True)
        for _, row in rows:
            demo = self._demo_no(row["old_patient_id"])
            src = os.path.join(pdf_dir, row["file_name"])
            dest_name = "OPENOSP_IMPORT_%s_%s" % (safe_batch, row["file_name"])
            dest = os.path.join(self.doc_dir, dest_name)
            if self.dry_run:
                continue
            if not os.path.isfile(src):
                raise RuntimeError("PDF missing: %s" % src)
            shutil.copy2(src, dest)
            provider = row.get("provider_no") or "999998"
            desc = row.get("document_description") or row["file_name"]
            if IMPORT_MARKER not in desc:
                desc = "%s %s" % (IMPORT_MARKER, desc)
            obs = row.get("observation_date") or datetime.now().strftime("%Y-%m-%d")
            ctype = row.get("content_type") or "application/pdf"
            dtype = row.get("document_type") or "others"
            dclass = row.get("document_class") or ""
            sql_doc = (
                "INSERT INTO document (doctype, docClass, docSubClass, docdesc, docfilename, doccreator, "
                "responsible, status, contenttype, public1, observationdate, updatedatetime, contentdatetime, "
                "number_of_pages, restrictToProgram, abnormal) VALUES "
                "(%s, %s, '', %s, %s, %s, %s, 'A', %s, 0, %s, %s, %s, 1, 0, 0);"
                % (
                    sql_escape(dtype), sql_escape(dclass), sql_escape(desc),
                    sql_escape(dest_name), sql_escape(provider), sql_escape(provider),
                    sql_escape(ctype), sql_escape(obs), sql_escape(now), sql_escape(now),
                )
            )
            doc_no = self.db.insert_returning_id(sql_doc)
            self._track("document", "document_no", doc_no, row["old_patient_id"])
            self.db.exec_script(
                "INSERT INTO ctl_document (module, module_id, document_no, status) VALUES "
                "('demographic', %s, %s, 'A');" % (sql_escape(demo), sql_escape(doc_no))
            )
            self._track("ctl_document", "document_no", doc_no, "link")
            plr = self.db.insert_returning_id(
                "INSERT INTO providerLabRouting (provider_no, lab_no, status, comment, lab_type) "
                "VALUES (%s, %s, 'N', %s, 'DOC');"
                % (sql_escape(provider), sql_escape(doc_no), sql_escape(IMPORT_MARKER + " " + self.batch_id))
            )
            self._track("providerLabRouting", "id", plr, row["old_patient_id"])
            patlr = self.db.insert_returning_id(
                "INSERT INTO patientLabRouting (demographic_no, lab_no, lab_type, created, dateModified) "
                "VALUES (%s, %s, 'DOC', %s, %s);"
                % (sql_escape(demo), sql_escape(doc_no), sql_escape(now), sql_escape(now))
            )
            self._track("patientLabRouting", "id", patlr, row["old_patient_id"])
            self.manifest["files"].append({
                "source": src, "dest": dest, "document_no": doc_no,
                "old_patient_id": row["old_patient_id"],
            })

    def _import_labs(self, rows):
        now = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
        groups = {}
        for _, row in rows:
            key = (row["old_patient_id"], row.get("collection_date", ""))
            groups.setdefault(key, []).append(row)
        for (old_id, coll_date), group_rows in groups.items():
            demo = self._demo_no(old_id)
            if self.dry_run:
                continue
            demo_row = self._demo_row(old_id)
            svc = coll_date.replace("-", "") if coll_date else ""
            sql_hdr = (
                "INSERT INTO labPatientPhysicianInfo (patient_first_name, patient_last_name, "
                "service_date, lab_status, comment1, lastUpdateDate) VALUES "
                "(%s, %s, %s, 'A', %s, %s);"
                % (
                    sql_escape(demo_row.get("first_name", "")),
                    sql_escape(demo_row.get("last_name", "")),
                    sql_escape(svc), sql_escape(IMPORT_MARKER + " " + self.batch_id),
                    sql_escape(now),
                )
            )
            lab_pi = self.db.insert_returning_id(sql_hdr)
            self._track("labPatientPhysicianInfo", "id", lab_pi, old_id)
            patlr = self.db.insert_returning_id(
                "INSERT INTO patientLabRouting (demographic_no, lab_no, lab_type, created, dateModified) "
                "VALUES (%s, %s, 'MDS', %s, %s);"
                % (sql_escape(demo), sql_escape(lab_pi), sql_escape(now), sql_escape(now))
            )
            self._track("patientLabRouting", "id", patlr, "lab")
            for row in group_rows:
                panel = row.get("panel_name", "")
                line_type = "H" if panel and not row.get("test_name") else "T"
                sql_res = (
                    "INSERT INTO labTestResults (labPatientPhysicianInfo_id, line_type, title, test_name, "
                    "result, units, minimum, maximum, abn, description) VALUES "
                    "(%s, %s, %s, %s, %s, %s, %s, %s, %s, %s);"
                    % (
                        sql_escape(lab_pi), sql_escape(line_type),
                        sql_escape(panel or IMPORT_MARKER),
                        sql_escape(row.get("test_name", "")),
                        sql_escape(row.get("result_value", "")),
                        sql_escape(row.get("units", "")),
                        sql_escape(row.get("reference_range", "").split("-")[0] if "-" in row.get("reference_range", "") else ""),
                        sql_escape(row.get("reference_range", "").split("-")[-1] if "-" in row.get("reference_range", "") else ""),
                        sql_escape(row.get("abnormal_flag", "")),
                        sql_escape(tagged_text(row.get("notes", ""))),
                    )
                )
                res_id = self.db.insert_returning_id(sql_res)
                self._track("labTestResults", "id", res_id, old_id)

    def _write_manifest(self):
        os.makedirs(MANIFESTS_DIR, exist_ok=True)
        path = os.path.join(MANIFESTS_DIR, "%s.json" % self.batch_id)
        with open(path, "w", encoding="utf-8") as fh:
            json.dump(self.manifest, fh, indent=2)
        print("Manifest saved: %s" % path)

    def _write_id_mapping(self):
        path = os.path.join(self.bundle_dir, "id_mapping.csv")
        fieldnames = ["old_patient_id", "oscar_demographic_no", "chart_no", "imported_at", "import_batch_id", "notes"]
        out_rows = []
        _, demos = read_csv(os.path.join(self.bundle_dir, "demographics.csv"))
        demo_by_old = {r["old_patient_id"]: r for _, r in demos}
        for old_id, demo_no in self.manifest["id_mapping"].items():
            drow = demo_by_old.get(old_id, {})
            out_rows.append({
                "old_patient_id": old_id,
                "oscar_demographic_no": demo_no,
                "chart_no": drow.get("chart_no", ""),
                "imported_at": self.manifest["imported_at"],
                "import_batch_id": self.batch_id,
                "notes": IMPORT_MARKER,
            })
        write_csv(path, fieldnames, out_rows)
        print("Updated id_mapping.csv in bundle directory.")


def purge_batch(db, batch_id, dry_run, force):
    manifest_path = os.path.join(MANIFESTS_DIR, "%s.json" % batch_id)
    if not os.path.isfile(manifest_path):
        raise RuntimeError(
            "Manifest not found: %s\nCannot purge safely without a manifest from a prior import." % manifest_path
        )
    with open(manifest_path, "r", encoding="utf-8") as fh:
        manifest = json.load(fh)

    counts = {}
    for rec in manifest.get("records", []):
        t = rec["table_name"]
        counts[t] = counts.get(t, 0) + 1

    print("\n" + "=" * 72)
    print("PURGE PREVIEW — batch: %s" % batch_id)
    print("Mode: %s" % ("DRY-RUN" if dry_run else "LIVE DELETE"))
    print("Manifest: %s" % manifest_path)
    print("Imported at: %s" % manifest.get("imported_at", "?"))
    print("\nTracked records to delete:")
    for t in PURGE_TABLE_ORDER:
        if t in counts:
            print("  %s: %s" % (t, counts[t]))
    print("  openosp_import_tracking: %s rows for this batch" % sum(counts.values()))
    print("\nPDF files to remove: %s" % len(manifest.get("files", [])))
    for f in manifest.get("files", [])[:10]:
        print("  %s" % f.get("dest"))
    print("=" * 72)

    if dry_run:
        return

    confirm_action("purge", batch_id, force)

    db.exec_script("START TRANSACTION;")
    try:
        for table in PURGE_TABLE_ORDER:
            sql = (
                "DELETE t FROM `%s` t "
                "INNER JOIN openosp_import_tracking tr "
                "ON tr.table_name=%s AND tr.pk_value=CAST(t.`%s` AS CHAR) "
                "AND tr.import_batch_id=%s;"
                % (table, sql_escape(table), _pk_column(table), sql_escape(batch_id))
            )
            db.exec_script(sql)
        db.exec_script(
            "DELETE FROM openosp_import_tracking WHERE import_batch_id=%s;"
            % sql_escape(batch_id)
        )
        db.exec_script("COMMIT;")
    except Exception:
        try:
            db.exec_script("ROLLBACK;")
        except RuntimeError:
            pass
        raise

    for f in manifest.get("files", []):
        dest = f.get("dest")
        if dest and os.path.isfile(dest):
            os.remove(dest)
            print("Removed file: %s" % dest)

    print("\nSUCCESS: purged batch %s" % batch_id)


def _pk_column(table):
    mapping = {
        "demographic": "demographic_no",
        "appointment": "appointment_no",
        "casemgmt_note": "note_id",
        "allergies": "allergyid",
        "prescription": "script_no",
        "drugs": "drugid",
        "document": "document_no",
        "ctl_document": "document_no",
        "providerLabRouting": "id",
        "patientLabRouting": "id",
        "labPatientPhysicianInfo": "id",
        "labTestResults": "id",
    }
    return mapping.get(table, "id")


def main():
    parser = argparse.ArgumentParser(
        description="Import or purge OpenOSP CSV bundles (requires prior validation)."
    )
    parser.add_argument("bundle_dir", nargs="?", default=os.path.join(SCRIPT_DIR, "example-bundle"))
    parser.add_argument("--import", dest="do_import", action="store_true")
    parser.add_argument("--purge", dest="do_purge", action="store_true")
    parser.add_argument("--dry-run", action="store_true")
    parser.add_argument("--force", action="store_true", help="Skip confirmation prompt")
    parser.add_argument("--batch-id", help="Import batch id (default: from CSV or timestamp)")
    parser.add_argument("--skip-validation", action="store_true", help="Not recommended")
    parser.add_argument("--db-container", default=os.environ.get("OPENOSP_DB_CONTAINER"))
    parser.add_argument("--database", default=os.environ.get("MYSQL_DATABASE", "oscar"))
    args = parser.parse_args()

    if not args.do_import and not args.do_purge:
        parser.error("Specify --import or --purge")
    if args.do_import and args.do_purge:
        parser.error("Use --import or --purge separately")

    bundle_dir = os.path.abspath(args.bundle_dir)
    env = load_local_env()
    password = os.environ.get("MYSQL_ROOT_PASSWORD") or env.get("MYSQL_ROOT_PASSWORD")
    if not password:
        print("ERROR: MYSQL_ROOT_PASSWORD not set (local.env or environment).", file=sys.stderr)
        return 1

    container = resolve_db_container(args.db_container)
    if not container:
        print("ERROR: MariaDB container not found.", file=sys.stderr)
        return 1

    try:
        assert_container_running(container)
    except RuntimeError as exc:
        print("ERROR: %s" % exc, file=sys.stderr)
        return 1

    db = MysqlClient(container, password, args.database)

    if args.do_purge:
        if not args.batch_id:
            parser.error("--purge requires --batch-id from a prior import manifest")
        try:
            purge_batch(db, args.batch_id, args.dry_run, args.force)
        except RuntimeError as exc:
            print("ERROR: %s" % exc, file=sys.stderr)
            return 1
        return 0

    # Import path
    if not args.skip_validation:
        try:
            run_validator(bundle_dir)
        except RuntimeError as exc:
            print("ERROR: %s" % exc, file=sys.stderr)
            return 1

    try:
        batch_id = resolve_batch_id(bundle_dir, args.batch_id)
        data = load_bundle(bundle_dir)
        summary = plan_import(bundle_dir, data, batch_id)
        summary.print_report("IMPORT PREVIEW", args.dry_run)

        if args.dry_run:
            print("\nDry-run complete. Re-run with --import (no --dry-run) to apply.")
            return 0

        validate_db_schema(db)
        confirm_action("import", batch_id, args.force)

        doc_dir = os.environ.get(
            "OPENOSP_DOCUMENT_DIR",
            os.path.join(PROJECT_ROOT, "volumes", "OscarDocument", "oscar", "document"),
        )
        importer = BundleImporter(
            db, bundle_dir, batch_id, doc_dir, dry_run=False,
            demographics_rows=data["demographics"],
        )
        # Pre-load id map requirement: import demographics first in transaction
        importer.import_all(data)
        print("\nSUCCESS: imported batch %s" % batch_id)
        print("Verify patients in Oscar, then keep manifest for purge: import/manifests/%s.json" % batch_id)
        return 0
    except RuntimeError as exc:
        print("ERROR: %s" % exc, file=sys.stderr)
        return 1


if __name__ == "__main__":
    sys.exit(main())
