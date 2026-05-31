#!/usr/bin/env python3
"""Generate minimal valid PDF files for OpenOSP sample document seeding (stdlib only)."""

from __future__ import print_function

import sys
from pathlib import Path


def _escape_pdf_text(text):
    return text.replace("\\", "\\\\").replace("(", "\\(").replace(")", "\\)")


def build_pdf(title, body_lines):
    title_esc = _escape_pdf_text(title)
    stream_parts = ["BT /F1 16 Tf 72 750 Td (%s) Tj" % title_esc]
    y = 720
    for line in body_lines:
        y -= 18
        stream_parts.append("72 %d Td (%s) Tj" % (y, _escape_pdf_text(line)))
    stream_parts.append("ET")
    stream = "\n".join(stream_parts).encode("latin-1", errors="replace")
    stream_len = len(stream)

    objects = []
    objects.append(b"1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n")
    objects.append(b"2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n")
    objects.append(
        b"3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] "
        b"/Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>\nendobj\n"
    )
    objects.append(
        ("4 0 obj\n<< /Length %d >>\nstream\n" % stream_len).encode("ascii")
        + stream
        + b"\nendstream\nendobj\n"
    )
    objects.append(
        b"5 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n"
    )

    pdf = bytearray(b"%PDF-1.4\n")
    offsets = [0]
    for obj in objects:
        offsets.append(len(pdf))
        pdf.extend(obj)

    xref_start = len(pdf)
    pdf.extend(("xref\n0 %d\n" % (len(objects) + 1)).encode("ascii"))
    pdf.extend(b"0000000000 65535 f \n")
    for off in offsets[1:]:
        pdf.extend(("%010d 00000 n \n" % off).encode("ascii"))
    pdf.extend(
        (
            "trailer\n<< /Size %d /Root 1 0 R >>\nstartxref\n%d\n%%EOF\n"
            % (len(objects) + 1, xref_start)
        ).encode("ascii")
    )
    return bytes(pdf)


SAMPLES = [
    (
        "OPENOSP_SAMPLE_DATA_cbc_blood_test.pdf",
        "OPENOSP SAMPLE - CBC Blood Test",
        [
            "Patient: TEST_ALICE SAMPLE_JONES",
            "Marker: OPENOSP_SAMPLE_DATA",
            "Result: WBC 6.2 x10^9/L (ref 4.0-11.0)",
            "Result: Hgb 138 g/L (ref 120-160)",
            "Result: Platelets 245 x10^9/L",
            "Fictional report for local development only.",
        ],
    ),
    (
        "OPENOSP_SAMPLE_DATA_chest_xray_report.pdf",
        "OPENOSP SAMPLE - Chest X-Ray Report",
        [
            "Patient: TEST_BOB SAMPLE_SMITH",
            "Marker: OPENOSP_SAMPLE_DATA",
            "Findings: Lungs clear. No acute cardiopulmonary disease.",
            "Impression: Normal chest radiograph.",
            "Fictional report for local development only.",
        ],
    ),
    (
        "OPENOSP_SAMPLE_DATA_ultrasound_report.pdf",
        "OPENOSP SAMPLE - Ultrasound Report",
        [
            "Patient: TEST_CAROL SAMPLE_LEE",
            "Marker: OPENOSP_SAMPLE_DATA",
            "Study: Abdominal ultrasound",
            "Findings: Liver, gallbladder, kidneys unremarkable.",
            "Fictional report for local development only.",
        ],
    ),
    (
        "OPENOSP_SAMPLE_DATA_referral_letter.pdf",
        "OPENOSP SAMPLE - Referral Letter",
        [
            "Patient: TEST_DANA SAMPLE_KIM",
            "Marker: OPENOSP_SAMPLE_DATA",
            "Re: Referral to General Surgery for assessment",
            "Dear Colleague, Please see this fictional patient for dev testing.",
            "Fictional document for local development only.",
        ],
    ),
]


def main():
    out_dir = Path(sys.argv[1] if len(sys.argv) > 1 else "generated-pdfs")
    out_dir.mkdir(parents=True, exist_ok=True)
    for filename, title, lines in SAMPLES:
        path = out_dir / filename
        path.write_bytes(build_pdf(title, lines))
        print("Wrote %s" % path)


if __name__ == "__main__":
    main()
