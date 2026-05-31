"""Shared helpers for OpenOSP import validate + import scripts."""

from __future__ import print_function

import csv
import os
import re
import subprocess

SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
PROJECT_ROOT = os.path.dirname(SCRIPT_DIR)

IMPORT_MARKER = "OPENOSP_IMPORT"
DEFAULT_SCHEMA = os.path.join(SCRIPT_DIR, "schema", "expected_columns.json")
MANIFESTS_DIR = os.path.join(SCRIPT_DIR, "manifests")

DATE_RE = re.compile(r"^\d{4}-\d{2}-\d{2}$")


def read_csv(path):
    with open(path, "r", encoding="utf-8-sig", newline="") as fh:
        reader = csv.DictReader(fh)
        if not reader.fieldnames:
            return [], []
        rows = []
        for i, row in enumerate(reader, start=2):
            rows.append((i, {k: (v.strip() if v else "") for k, v in row.items()}))
        return list(reader.fieldnames), rows


def write_csv(path, fieldnames, rows):
    with open(path, "w", encoding="utf-8", newline="") as fh:
        writer = csv.DictWriter(fh, fieldnames=fieldnames, extrasaction="ignore")
        writer.writeheader()
        for row in rows:
            writer.writerow(row)


def load_local_env(project_root=None):
    project_root = project_root or PROJECT_ROOT
    env = {}
    path = os.path.join(project_root, "local.env")
    if not os.path.isfile(path):
        return env
    with open(path, "r", encoding="utf-8") as fh:
        for line in fh:
            line = line.strip()
            if not line or line.startswith("#") or "=" not in line:
                continue
            key, val = line.split("=", 1)
            env[key.strip()] = val.strip().strip('"').strip("'")
    return env


def sql_escape(value):
    if value is None:
        return "NULL"
    return "'" + str(value).replace("\\", "\\\\").replace("'", "''") + "'"


def parse_dob_parts(dob):
    if not dob or not DATE_RE.match(dob):
        return "", "", ""
    y, m, d = dob.split("-")
    return y, m, d


def normalize_time(value):
    if not value:
        return "00:00:00"
    if value.count(":") == 1:
        return value + ":00"
    return value


def resolve_db_container(explicit=None):
    if explicit:
        return explicit
    candidates = ["open-osp-db-1", "open-osp_db_1"]
    for name in candidates:
        if _docker_inspect(name):
            return name
    try:
        out = subprocess.check_output(
            ["docker", "compose", "ps", "-q", "db"],
            cwd=PROJECT_ROOT,
            stderr=subprocess.DEVNULL,
            text=True,
        ).strip()
        if out:
            cid = out.splitlines()[0]
            name = subprocess.check_output(
                ["docker", "inspect", "-f", "{{.Name}}", cid], text=True
            ).strip().lstrip("/")
            if name:
                return name
    except (subprocess.CalledProcessError, FileNotFoundError):
        pass
    return None


def _docker_inspect(name):
    try:
        subprocess.check_call(
            ["docker", "inspect", name],
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
        )
        return True
    except (subprocess.CalledProcessError, FileNotFoundError):
        return False


def assert_container_running(container):
    try:
        state = subprocess.check_output(
            ["docker", "inspect", "-f", "{{.State.Running}}", container], text=True
        ).strip()
    except subprocess.CalledProcessError as exc:
        raise RuntimeError("Container not found: %s" % container) from exc
    if state != "true":
        raise RuntimeError("Container %s is not running" % container)


class MysqlClient(object):
    def __init__(self, container, password, database="oscar"):
        self.container = container
        self.password = password
        self.database = database

    def query(self, sql):
        cmd = [
            "docker", "exec", "-i", self.container,
            "mysql", "-uroot", "-p%s" % self.password, "-N", "-B",
            self.database, "-e", sql,
        ]
        try:
            out = subprocess.check_output(cmd, stderr=subprocess.STDOUT, text=True)
        except subprocess.CalledProcessError as exc:
            raise RuntimeError("MySQL error:\n%s\nSQL: %s" % (exc.output, sql)) from exc
        return out.strip()

    def query_scalar(self, sql):
        out = self.query(sql)
        if not out:
            return None
        return out.splitlines()[0].split("\t")[0]

    def exec_script(self, sql):
        cmd = [
            "docker", "exec", "-i", self.container,
            "mysql", "-uroot", "-p%s" % self.password, self.database,
        ]
        try:
            subprocess.run(
                cmd, input=sql, text=True, check=True,
                stdout=subprocess.PIPE, stderr=subprocess.PIPE,
            )
        except subprocess.CalledProcessError as exc:
            raise RuntimeError("MySQL script error:\n%s" % exc.stderr) from exc

    def exec_file(self, path):
        with open(path, "r", encoding="utf-8") as fh:
            sql = fh.read()
        self.exec_script(sql)

    def table_exists(self, table):
        n = self.query_scalar(
            "SELECT COUNT(*) FROM information_schema.TABLES "
            "WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s"
            % (sql_escape(self.database), sql_escape(table))
        )
        return n == "1"

    def column_exists(self, table, column):
        n = self.query_scalar(
            "SELECT COUNT(*) FROM information_schema.COLUMNS "
            "WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s AND COLUMN_NAME=%s"
            % (sql_escape(self.database), sql_escape(table), sql_escape(column))
        )
        return n == "1"

    def insert_returning_id(self, sql_insert):
        self.exec_script(sql_insert)
        return self.query_scalar("SELECT LAST_INSERT_ID();")

    def track(self, batch_id, table, pk_column, pk_value, extra_ref=None):
        sql = (
            "INSERT INTO openosp_import_tracking "
            "(import_batch_id, table_name, pk_column, pk_value, extra_ref) VALUES (%s, %s, %s, %s, %s);"
            % (
                sql_escape(batch_id), sql_escape(table), sql_escape(pk_column),
                sql_escape(str(pk_value)), sql_escape(extra_ref or ""),
            )
        )
        self.exec_script(sql)
