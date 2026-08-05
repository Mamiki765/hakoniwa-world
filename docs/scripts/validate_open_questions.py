#!/usr/bin/env python3
"""Validate the documentation contract for docs/open-questions.md."""

from __future__ import annotations

from collections import Counter
from pathlib import Path
import re
import sys


ROOT = Path(__file__).resolve().parents[2]
DOCUMENT = ROOT / "docs" / "open-questions.md"
ALLOWED_STATUSES = {"Decided", "Open", "Deferred"}
REQUIRED_SECTIONS = (
    "Current blocking gates",
    "Decided architecture",
    "Monster/combat gates",
    "Release gates",
    "Deferred post-MVP",
    "Historical initial MVP",
)
EXPECTED_STATUSES = {
    "A-02": "Decided",
    "A-03": "Decided",
    "A-04": "Decided",
    "A-05": "Decided",
    "A-06": "Decided",
    "A-07": "Decided",
    "A-08": "Decided",
    "A-09": "Decided",
    "A-10": "Decided",
    "A-11": "Decided",
    "AUTH-01": "Decided",
    "AUTH-02": "Decided",
    "AUTH-03": "Decided",
    "AUTH-04": "Decided",
    "AUTH-05": "Decided",
    "AUTH-06": "Deferred",
    "AUTH-07": "Deferred",
    "AUTH-08": "Deferred",
    "AUTH-09": "Deferred",
    "AWARD-01": "Open",
    "B-01": "Decided",
    "B-02": "Decided",
    "B-03": "Open",
    "B-05": "Open",
    "B-06": "Decided",
    "B-07": "Open",
    "B-08": "Deferred",
    "B-09": "Decided",
    "B-10": "Decided",
    "B-12": "Open",
    "B-13": "Open",
    "B-14": "Decided",
    "B-15": "Deferred",
    "B-16": "Decided",
    "B-17": "Decided",
    "B-18": "Decided",
    "B-19": "Decided",
    "C-01": "Decided",
    "C-02": "Deferred",
    "C-03": "Decided",
    "C-04": "Deferred",
    "C-05": "Decided",
    "C-06": "Decided",
    "C-07": "Decided",
    "C-08": "Decided",
    "CMD-01": "Decided",
    "CMD-02": "Decided",
    "D-01": "Decided",
    "D-02": "Decided",
    "D-03": "Decided",
    "D-04": "Decided",
    "D-05": "Decided",
    "D-06": "Deferred",
    "D-07": "Decided",
    "D-08": "Deferred",
    "DISASTER-01": "Decided",
    "E-01": "Deferred",
    "E-02": "Deferred",
    "E-03": "Decided",
    "E-04": "Deferred",
    "E-05": "Deferred",
    "E-06": "Deferred",
    "E-07": "Deferred",
    "E-08": "Deferred",
    "E-09": "Deferred",
    "MISSILE-01": "Decided",
    "MONSTER-01": "Decided",
    "MONSTER-02": "Decided",
    "MONSTER-03": "Decided",
    "MONSTER-04": "Decided",
    "POP-01": "Decided",
    "RELEASE-01": "Decided",
    "RES-01": "Decided",
    "T-01": "Decided",
    "T-02": "Open",
}


def main() -> int:
    text = DOCUMENT.read_text(encoding="utf-8")
    errors: list[str] = []

    for section in REQUIRED_SECTIONS:
        if f"## {section}" not in text:
            errors.append(f"missing required section: {section}")

    heading_pattern = re.compile(
        r"^### (?P<id>[A-Z]+-[0-9]+) (?P<title>[^\r\n]+)$", re.MULTILINE
    )
    headings = list(heading_pattern.finditer(text))
    all_level_three_headings = re.findall(r"^### .+$", text, re.MULTILINE)
    if len(all_level_three_headings) != len(headings):
        errors.append(
            "every level-three heading must use the '### <ID> <title>' contract"
        )
    ids = [match.group("id") for match in headings]
    counts = Counter(ids)

    for decision_id, count in sorted(counts.items()):
        if count != 1:
            errors.append(f"duplicate Decision ID {decision_id}: {count} occurrences")

    actual_ids = set(ids)
    expected_ids = set(EXPECTED_STATUSES)
    for decision_id in sorted(expected_ids - actual_ids):
        errors.append(f"missing expected Decision ID: {decision_id}")
    for decision_id in sorted(actual_ids - expected_ids):
        errors.append(f"unexpected Decision ID: {decision_id}")

    if "B-11" in counts:
        errors.append("superseded Decision ID B-11 must not remain")

    status_counts: Counter[str] = Counter()
    for index, heading in enumerate(headings):
        decision_id = heading.group("id")
        body_end = headings[index + 1].start() if index + 1 < len(headings) else len(text)
        body = text[heading.end() : body_end]

        statuses = re.findall(r"^- Status: ([^\r\n]+)$", body, re.MULTILINE)
        if len(statuses) != 1:
            errors.append(
                f"{decision_id} must have exactly one Status line; found {len(statuses)}"
            )
            continue

        status = statuses[0]
        status_counts[status] += 1
        if status not in ALLOWED_STATUSES:
            errors.append(f"{decision_id} has invalid Status: {status}")

        required_before = re.findall(
            r"^- Required before: (\S[^\r\n]*)$", body, re.MULTILINE
        )
        if status == "Open" and len(required_before) != 1:
            errors.append(
                f"{decision_id} is Open and must have exactly one Required before line; "
                f"found {len(required_before)}"
            )

        open_decisions = re.findall(
            r"^- Open decision: (\S[^\r\n]*)$", body, re.MULTILINE
        )
        if status == "Open" and len(open_decisions) != 1:
            errors.append(
                f"{decision_id} is Open and must have exactly one actionable "
                f"Open decision line; found {len(open_decisions)}"
            )

        activation_gates = re.findall(
            r"^- Activation gate: (\S[^\r\n]*)$", body, re.MULTILINE
        )
        if status == "Deferred" and len(activation_gates) != 1:
            errors.append(
                f"{decision_id} is Deferred and must have exactly one "
                f"Activation gate line; found {len(activation_gates)}"
            )

        boundaries = re.findall(r"^- Boundary: (\S[^\r\n]*)$", body, re.MULTILINE)
        if status == "Deferred" and len(boundaries) != 1:
            errors.append(
                f"{decision_id} is Deferred and must have exactly one Boundary line; "
                f"found {len(boundaries)}"
            )

        record_lines = re.findall(
            r"^- Decision record: ([^\r\n]+)$", body, re.MULTILINE
        )
        if status in {"Decided", "Open"} and len(record_lines) != 1:
            errors.append(
                f"{decision_id} is {status} and must have exactly one Decision record "
                f"line; found {len(record_lines)}"
            )
        elif len(record_lines) > 1:
            errors.append(
                f"{decision_id} must have at most one Decision record line; "
                f"found {len(record_lines)}"
            )
        for record_line in record_lines:
            paths = re.findall(r"`([^`]+)`", record_line)
            if not paths:
                errors.append(f"{decision_id} Decision record has no repository path")
            for path_text in paths:
                path = path_text.split("#", 1)[0]
                if not path.startswith(("docs/", "product/docs/")):
                    errors.append(
                        f"{decision_id} Decision record is not a documentation path: {path_text}"
                    )
                elif not (ROOT / path).is_file():
                    errors.append(
                        f"{decision_id} Decision record target does not exist: {path_text}"
                    )

        expected_status = EXPECTED_STATUSES.get(decision_id)
        if expected_status is not None and status != expected_status:
            errors.append(
                f"{decision_id} must remain {expected_status}; found {status}"
            )

    numbered_pr = re.compile(r"\bPR\s*#?\s*\d+\b", re.IGNORECASE)
    future_roadmap_assignment = re.compile(
        r"(?:"
        r"\bPR\s*#?\s*\d+\b[^\r\n]{0,40}"
        r"(?:\bmust\b|\bowns?\b|予定|でcanonical|でrebaseline|として扱う)"
        r"|"
        r"(?:canonical|rebaseline|release[- ]freeze)[^\r\n]{0,40}"
        r"\bPR\s*#?\s*\d+\b[^\r\n]{0,20}(?:前に|後に|完了後|予定)"
        r")",
        re.IGNORECASE,
    )
    gate_fields = (
        "- Rebaseline plan:",
        "- Required before:",
        "- Open decision:",
        "- Remaining public-release gate:",
    )
    roadmap_documents = (
        DOCUMENT,
        ROOT / "product" / "docs" / "resource-profile-audit-pr19.md",
    )
    for markdown in roadmap_documents:
        for line_number, line in enumerate(
            markdown.read_text(encoding="utf-8").splitlines(), start=1
        ):
            fixed_gate_field = line.startswith(gate_fields) and numbered_pr.search(line)
            future_roadmap_prose = future_roadmap_assignment.search(line)
            if fixed_gate_field or future_roadmap_prose:
                errors.append(
                    f"stale fixed future PR roadmap in "
                    f"{markdown.relative_to(ROOT)}:{line_number}"
                )

    if errors:
        for error in errors:
            print(f"ERROR: {error}", file=sys.stderr)
        return 1

    statuses = ", ".join(
        f"{status}={status_counts[status]}" for status in sorted(ALLOWED_STATUSES)
    )
    print(
        f"open-question contract valid: {len(ids)} unique IDs; {statuses}; "
        "stale fixed future PR roadmaps=0"
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
