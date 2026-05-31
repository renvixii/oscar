#!/usr/bin/env python3
"""
OpenOSP import bundle validator (preparation only).

Reads CSV files and optional PDFs from a bundle directory.
Reports formatting problems, duplicates, bad dates, and missing files.

DOES NOT connect to MariaDB.
DOES NOT insert, update, or delete any Oscar data.
"""

from __future__ import print_function

import argparse
import csv
import json
import os
import re
import sys
from collections import Counter, defaultdict
from datetime import datetime

SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
DEFAULT_SCHEMA = os.path.join(SCRIPT_DIR, "schema", "expected_columns.json")

DATE_RE = re.compile(r"^\d{4}-\d{2}-\d{2}$")
TIME_RE = re.compile(r"^\d{1,2}:\d{2}(:\d{2})?$")
SEX_OK = {"M", "F", "m", "f", "U", "u", "O", "o", "T", "t"}

CHILD_FILES = [
    "appointments.csv",
    "consultation_notes.csv",
    "allergies.csv",
    "medications.csv",
    "diagnoses.csv",
    "lab_results.csv",
    "documents.csv",
]


class Report(object):
    def __init__(self):
        self.errors = []
        self.warnings = []
        self.info = []

    def error(self, msg):
        self.errors.append(msg)

    def warn(self, msg):
        self.warnings.append(msg)

    def note(self, msg):
        self.info.append(msg)

    @property
    def ok(self):
        return len(self.errors) == 0


def load_schema(path):
    with open(path, "r", encoding="utf-8") as fh:
        return json.load(fh)


def read_csv(path):
    with open(path, "r", encoding="utf-8-sig", newline="") as fh:
        reader = csv.DictReader(fh)
        if not reader.fieldnames:
            return [], []
        rows = []
        for i, row in enumerate(reader, start=2):
            rows.append((i, {k: (v.strip() if v else "") for k, v in row.items()}))
        return list(reader.fieldnames), rows


def validate_date(value, label, row_num, report):
    if not value:
        return False
    if not DATE_RE.match(value):
        report.error("%s row %s: invalid date '%s' (use YYYY-MM-DD)" % (label, row_num, value))
        return False
    try:
        datetime.strptime(value, "%Y-%m-%d")
    except ValueError:
        report.error("%s row %s: invalid calendar date '%s'" % (label, row_num, value))
        return False
    return True


def validate_time(value, label, row_num, report):
    if not value:
        return False
    if not TIME_RE.match(value):
        report.error("%s row %s: invalid time '%s' (use HH:MM or HH:MM:SS)" % (label, row_num, value))
        return False
    return True


def check_headers(filename, headers, spec, report):
    missing = [c for c in spec.get("required_columns", []) if c not in headers]
    if missing:
        report.error("%s: missing required columns: %s" % (filename, ", ".join(missing)))
    unknown = [h for h in headers if h not in spec.get("required_columns", []) + spec.get("optional_columns", [])]
    if unknown:
        report.warn("%s: unexpected columns (may be ignored): %s" % (filename, ", ".join(unknown)))


def validate_demographics(bundle_dir, schema, report):
    path = os.path.join(bundle_dir, "demographics.csv")
    if not os.path.isfile(path):
        report.error("Required file missing: demographics.csv")
        return set()

    spec = schema["files"]["demographics.csv"]
    headers, rows = read_csv(path)
    check_headers("demographics.csv", headers, spec, report)

    ids = []
    dup_keys = Counter()
    patient_ids = set()

    for row_num, row in rows:
        old_id = row.get("old_patient_id", "")
        if not old_id:
            report.error("demographics.csv row %s: old_patient_id is required" % row_num)
            continue
        ids.append(old_id)
        patient_ids.add(old_id)

        key = (
            row.get("last_name", "").lower(),
            row.get("first_name", "").lower(),
            row.get("date_of_birth", ""),
        )
        dup_keys[key] += 1

        for col in spec["required_columns"]:
            if col == "old_patient_id":
                continue
            if not row.get(col, ""):
                report.error("demographics.csv row %s: missing required field '%s'" % (row_num, col))

        if row.get("date_of_birth"):
            validate_date(row["date_of_birth"], "demographics.csv", row_num, report)

        sex = row.get("sex", "")
        if sex and sex not in SEX_OK:
            report.warn("demographics.csv row %s: unusual sex value '%s'" % (row_num, sex))

        if row.get("hin", "") and len(row["hin"]) < 8:
            report.warn("demographics.csv row %s: HIN looks short for testing (%s)" % (row_num, row["hin"]))

    id_counts = Counter(ids)
    for old_id, count in id_counts.items():
        if count > 1:
            report.error("demographics.csv: duplicate old_patient_id '%s' (%s rows)" % (old_id, count))

    for key, count in dup_keys.items():
        if count > 1 and all(key):
            report.warn(
                "demographics.csv: possible duplicate patient (name + DOB): %s %s born %s (%s rows)"
                % (key[1], key[0], key[2], count)
            )

    report.note("demographics.csv: %s patient row(s)" % len(rows))
    return patient_ids


def validate_child_file(bundle_dir, filename, schema, patient_ids, report, date_cols=None, time_cols=None):
    path = os.path.join(bundle_dir, filename)
    if not os.path.isfile(path):
        return

    spec = schema["files"][filename]
    headers, rows = read_csv(path)
    check_headers(filename, headers, spec, report)

    for row_num, row in rows:
        old_id = row.get("old_patient_id", "")
        if not old_id:
            report.error("%s row %s: old_patient_id is required" % (filename, row_num))
            continue
        if old_id not in patient_ids:
            report.error("%s row %s: old_patient_id '%s' not found in demographics.csv" % (filename, row_num, old_id))

        for col in spec["required_columns"]:
            if col == "old_patient_id":
                continue
            if not row.get(col, ""):
                report.error("%s row %s: missing required field '%s'" % (filename, row_num, col))

        if date_cols:
            for col in date_cols:
                if row.get(col):
                    validate_date(row[col], "%s" % filename, row_num, report)

        if time_cols:
            for col in time_cols:
                if row.get(col):
                    validate_time(row[col], "%s" % filename, row_num, report)

    report.note("%s: %s row(s)" % (filename, len(rows)))


def validate_documents(bundle_dir, schema, patient_ids, report):
    path = os.path.join(bundle_dir, "documents.csv")
    if not os.path.isfile(path):
        return

    pdf_dir = os.path.join(bundle_dir, "pdfs")
    spec = schema["files"]["documents.csv"]
    headers, rows = read_csv(path)
    check_headers("documents.csv", headers, spec, report)

    missing_pdfs = []
    found_pdfs = []

    for row_num, row in rows:
        old_id = row.get("old_patient_id", "")
        if old_id and old_id not in patient_ids:
            report.error("documents.csv row %s: unknown old_patient_id '%s'" % (row_num, old_id))

        file_name = row.get("file_name", "")
        if not file_name:
            report.error("documents.csv row %s: file_name is required" % row_num)
            continue

        if ".." in file_name or file_name.startswith("/") or "\\" in file_name:
            report.error("documents.csv row %s: unsafe file_name '%s'" % (row_num, file_name))

        pdf_path = os.path.join(pdf_dir, file_name)
        if os.path.isfile(pdf_path):
            found_pdfs.append(file_name)
        else:
            missing_pdfs.append((row_num, file_name))
            report.error("documents.csv row %s: PDF not found at pdfs/%s" % (row_num, file_name))

        if row.get("observation_date"):
            validate_date(row["observation_date"], "documents.csv", row_num, report)

        desc = row.get("document_description", "")
        if desc and "IMPORT_PREP" not in desc and "SAMPLE" not in desc.upper() and "TRAIN" not in desc.upper():
            report.warn(
                "documents.csv row %s: description has no training marker; use clear sample labels in dev"
                % row_num
            )

    report.note("documents.csv: %s document row(s); %s PDF(s) found; %s missing"
                % (len(rows), len(found_pdfs), len(missing_pdfs)))


def print_preview(bundle_dir, patient_ids, report):
    demo_path = os.path.join(bundle_dir, "demographics.csv")
    if not os.path.isfile(demo_path):
        return
    _, rows = read_csv(demo_path)
    report.note("--- dry-run preview (no database changes) ---")
    for row_num, row in rows[:10]:
        report.note(
            "  patient %s: %s %s, DOB %s, chart %s"
            % (
                row.get("old_patient_id", "?"),
                row.get("first_name", ""),
                row.get("last_name", ""),
                row.get("date_of_birth", ""),
                row.get("chart_no", ""),
            )
        )
    if len(rows) > 10:
        report.note("  ... and %s more patient(s)" % (len(rows) - 10))

    docs = os.path.join(bundle_dir, "documents.csv")
    if os.path.isfile(docs):
        _, drows = read_csv(docs)
        for row_num, row in drows[:10]:
            report.note(
                "  document for %s: pdfs/%s -> %s"
                % (row.get("old_patient_id", "?"), row.get("file_name", "?"), row.get("document_description", ""))
            )


def main():
    parser = argparse.ArgumentParser(
        description="Validate an OpenOSP import preparation bundle (no database writes)."
    )
    parser.add_argument(
        "bundle_dir",
        nargs="?",
        default=os.path.join(SCRIPT_DIR, "example-bundle"),
        help="Directory containing CSV files and optional pdfs/ subfolder",
    )
    parser.add_argument(
        "--schema",
        default=DEFAULT_SCHEMA,
        help="Path to expected_columns.json",
    )
    parser.add_argument(
        "--dry-run",
        action="store_true",
        default=True,
        help="Preview only (default; no database operations)",
    )
    parser.add_argument(
        "--strict",
        action="store_true",
        help="Treat warnings as errors",
    )
    args = parser.parse_args()

    bundle_dir = os.path.abspath(args.bundle_dir)
    report = Report()

    print("=" * 72)
    print("OpenOSP import bundle validator")
    print("PREPARATION ONLY — does not insert into Oscar/MariaDB")
    print("=" * 72)
    print("Bundle directory: %s" % bundle_dir)
    print("Mode: dry-run / validate only")
    print("")

    if not os.path.isdir(bundle_dir):
        report.error("Bundle directory does not exist: %s" % bundle_dir)
        _finish(report, args.strict)
        return 1

    if not os.path.isfile(args.schema):
        report.error("Schema file not found: %s" % args.schema)
        _finish(report, args.strict)
        return 1

    schema = load_schema(args.schema)
    patient_ids = validate_demographics(bundle_dir, schema, report)

    validate_child_file(
        bundle_dir,
        "appointments.csv",
        schema,
        patient_ids,
        report,
        date_cols=["appointment_date"],
        time_cols=["start_time", "end_time"],
    )
    validate_child_file(
        bundle_dir,
        "consultation_notes.csv",
        schema,
        patient_ids,
        report,
        date_cols=["note_date"],
    )
    validate_child_file(bundle_dir, "allergies.csv", schema, patient_ids, report, date_cols=["start_date"])
    validate_child_file(
        bundle_dir,
        "medications.csv",
        schema,
        patient_ids,
        report,
        date_cols=["start_date", "end_date"],
    )
    validate_child_file(
        bundle_dir,
        "diagnoses.csv",
        schema,
        patient_ids,
        report,
        date_cols=["diagnosis_date"],
    )
    validate_child_file(
        bundle_dir,
        "lab_results.csv",
        schema,
        patient_ids,
        report,
        date_cols=["collection_date"],
    )
    validate_documents(bundle_dir, schema, patient_ids, report)

    # Optional id_mapping file — usually empty before import
    mapping_path = os.path.join(bundle_dir, "id_mapping.csv")
    if os.path.isfile(mapping_path):
        headers, rows = read_csv(mapping_path)
        check_headers("id_mapping.csv", headers, schema["files"]["id_mapping.csv"], report)
        for row_num, row in rows:
            if row.get("old_patient_id") and row["old_patient_id"] not in patient_ids:
                report.warn("id_mapping.csv row %s: old_patient_id not in demographics" % row_num)
        report.note("id_mapping.csv: %s row(s) (typically filled after import)" % len(rows))

    print_preview(bundle_dir, patient_ids, report)
    return _finish(report, args.strict)


def _finish(report, strict):
    if report.info:
        print("\n-- Summary --")
        for line in report.info:
            print("  %s" % line)

    if report.warnings:
        print("\n-- Warnings (%s) --" % len(report.warnings))
        for line in report.warnings:
            print("  WARN: %s" % line)

    if report.errors:
        print("\n-- Errors (%s) --" % len(report.errors))
        for line in report.errors:
            print("  ERROR: %s" % line)

    print("")
    if report.ok and not (strict and report.warnings):
        print("RESULT: PASS — bundle is ready for import.")
        print("Next: import/import-bundle.sh --dry-run --import  then  import/import-bundle.sh --import")
        return 0

    if strict and report.warnings:
        print("RESULT: FAIL — strict mode treats warnings as errors.")
        return 1

    print("RESULT: FAIL — fix errors and re-run validation.")
    return 1


if __name__ == "__main__":
    sys.exit(main())
