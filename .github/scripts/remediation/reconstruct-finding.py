#!/usr/bin/env python3
"""Build a minimal finding JSON from an issue body when Monitor artifact is missing."""
from __future__ import annotations

import json
import os
import re
import sys


def main() -> int:
    fid = os.environ["FINDING_ID"]
    out_dir = os.environ.get("OUT_DIR", "/tmp/finding")
    body = os.environ.get("BODY") or ""
    sev = "unknown"
    m = re.search(r"\|\s*Severity\s*\|\s*([^|]+)\|", body)
    if m:
        sev = m.group(1).strip()
    doc = {
        "finding_id": fid,
        "severity": sev,
        "status": "FAIL",
        "check_name": "from-issue",
        "description": f"Remediation authorized from issue #{os.environ['ISSUE_NUMBER']}",
        "evidence": "See GitHub issue body",
        "root_cause_or_uncertainty": "See issue",
        "proposed_fix": "See issue proposed fix; implement only approved scope",
        "expected_files_or_modules": "See approved_scope input on workflow",
        "potential_risks": "Scope creep; untested changes",
        "tests_required": "Run Backend/Frontend checks relevant to the finding",
        "repository": os.environ["REPO"],
        "issue_number": os.environ["ISSUE_NUMBER"],
        "authorized_by_dispatch_actor": os.environ["ACTOR"],
    }
    os.makedirs(out_dir, exist_ok=True)
    path = os.path.join(out_dir, f"{fid}.json")
    with open(path, "w", encoding="utf-8") as fh:
        fh.write(json.dumps(doc, indent=2) + "\n")
    print(path)
    return 0


if __name__ == "__main__":
    sys.exit(main())
