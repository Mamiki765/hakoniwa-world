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
REQUIRED_IDS = {
    "B-18",
    "DISASTER-01",
    "MONSTER-01",
    "MONSTER-02",
    "MONSTER-03",
    "RELEASE-01",
}
EXPECTED_STATUSES = {
    "MONSTER-01": "Decided",
    "MONSTER-02": "Open",
    "MONSTER-03": "Open",
    "RELEASE-01": "Open",
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

    for decision_id in sorted(REQUIRED_IDS - set(ids)):
        errors.append(f"missing required Decision ID: {decision_id}")

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
            r"^- Required before: ([^\r\n]+)$", body, re.MULTILINE
        )
        if status == "Open" and len(required_before) != 1:
            errors.append(
                f"{decision_id} is Open and must have exactly one Required before line; "
                f"found {len(required_before)}"
            )

        record_lines = re.findall(
            r"^- Decision record: ([^\r\n]+)$", body, re.MULTILINE
        )
        if len(record_lines) > 1:
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

    stale_roadmap = re.compile(r"\bPR\s*#?\s*(?:20|22)\b", re.IGNORECASE)
    for directory in (ROOT / "docs", ROOT / "product" / "docs"):
        for markdown in directory.rglob("*.md"):
            for line_number, line in enumerate(
                markdown.read_text(encoding="utf-8").splitlines(), start=1
            ):
                if stale_roadmap.search(line):
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
