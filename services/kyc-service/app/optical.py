"""Conservative PH document text checks. No issuer signature or authenticity claim."""

import re
from datetime import date, datetime

from app.providers import Check
from app.self_hosted import normalized


def field(text: str, label: str) -> str | None:
    # Only labeled text is eligible: an arbitrary number/name elsewhere is not a field.
    found = re.search(rf"(?:^|\n)[^\n]*?(?:{label})\s*[:#]?\s*(?:\n\s*)?([^\n]+)", text, re.I)
    return found.group(1).strip()[:180] if found else None


def iso_date(value: str | None) -> str | None:
    if value:
        for pattern in ("%Y-%m-%d", "%Y/%m/%d", "%B %d, %Y", "%B %d %Y", "%d %b %Y", "%d %B %Y"):
            try:
                return datetime.strptime(value.strip().upper(), pattern).date().isoformat()
            except ValueError:
                pass
    return None


def digit(value: str) -> str:
    alphabet = "0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ"
    return str(
        sum(
            (0 if char == "<" else alphabet.index(char)) * (7, 3, 1)[i % 3]
            for i, char in enumerate(value)
        )
        % 10
    )


def mrz_date(value: str, *, birth: bool) -> str | None:
    if not re.fullmatch(r"[0-9]{6}", value):
        return None
    year = 2000 + int(value[:2])
    if birth and year > date.today().year:
        year -= 100
    try:
        result = date(year, int(value[2:4]), int(value[4:]))
        return result.isoformat()
    except ValueError:
        return None


def passport(text: str) -> tuple[dict[str, str | None], Check]:
    lines = [re.sub(r"\s", "", line.upper()) for line in text.splitlines()]
    pairs = [
        (a, b)
        for a, b in zip(lines, lines[1:], strict=False)
        if re.fullmatch(r"P[A-Z<]PHL[A-Z<]{39}", a) and re.fullmatch(r"[A-Z0-9<]{44}", b)
    ]
    if len(pairs) != 1:
        return {}, "unavailable"
    first, second = pairs[0]
    checked = (
        (second[:9], second[9]),
        (second[13:19], second[19]),
        (second[21:27], second[27]),
        (second[28:42], second[42]),
        (second[:10] + second[13:20] + second[21:43], second[43]),
    )
    if any(digit(value) != check for value, check in checked):
        return {}, "failed"
    surname, separator, given = first[5:].partition("<<")
    if not separator or not surname.strip("<") or not given.strip("<"):
        return {}, "unavailable"
    fields = {
        "full_name": " ".join((given + " " + surname).replace("<", " ").split()),
        "birth_date": mrz_date(second[13:19], birth=True),
        "expiration_date": mrz_date(second[21:27], birth=False),
        "document_number": second[:9].rstrip("<"),
        "issuing_country": "PH",
        "nationality": "PH" if second[10:13] == "PHL" else None,
    }
    return fields, "passed" if all(fields.values()) else "unavailable"


def inspect_texts(kind: str, texts: dict[str, str]) -> tuple[dict[str, str | None], Check]:
    front = texts.get("front", "")
    if kind == "passport":
        return passport(front)
    if kind not in {"philsys", "drivers_license"} or not texts.get("back", "").strip():
        return {}, "unavailable"
    headers = (r"REPUBLI(KA|C)", r"PILIPINAS|PHILIPPINES")
    if not all(re.search(pattern, front, re.I) for pattern in headers):
        return {}, "unavailable"
    fields: dict[str, str | None] = {"issuing_country": "PH"}
    fields["birth_date"] = iso_date(field(front, r"DATE OF BIRTH"))
    if kind == "philsys":
        if not re.search(
            r"PHILIPPINE IDENTIFICATION|PAMBANSANG PAGKAKAKILANLAN|PHILSYS", front, re.I
        ):
            return {}, "unavailable"
        names = [field(front, label) for label in (r"GIVEN NAMES?", r"MIDDLE NAME", r"LAST NAME")]
        fields["full_name"] = (
            " ".join(value for value in names if value) if names[0] and names[2] else None
        )
        numbers = re.findall(r"\b[0-9]{4}[- ][0-9]{4}[- ][0-9]{4}[- ][0-9]{4}\b", front)
        fields["document_number"] = numbers[0] if len(numbers) == 1 else None
    else:
        if not re.search(r"LAND TRANSPORTATION OFFICE", front, re.I) or not re.search(
            r"DRIVER.?S LICENSE", front, re.I
        ):
            return {}, "unavailable"
        name = field(front, r"LAST NAME,?\s*FIRST NAME,?\s*MIDDLE NAME")
        if name and "," in name:
            surname, given = name.split(",", 1)
            name = f"{given.strip()} {surname.strip()}"
        fields["full_name"] = name
        number = field(front, r"LICENSE NO\.?|LICENSE NUMBER")
        match = re.match(r"[A-Z][0-9]{2}-[0-9]{2}-[0-9]{6}\b", number or "", re.I)
        fields["document_number"] = match.group() if match else None
        fields["expiration_date"] = iso_date(field(front, r"EXPIRATION DATE"))
    return fields, "passed" if all(fields.values()) else "unavailable"


def data_matches(extracted: dict[str, str | None], personal: dict) -> Check:
    required = {key: value for key, value in personal.items() if value}
    if not required or any(not extracted.get(key) for key in required):
        return "unavailable"
    if any(
        not normalized(str(value)) or not normalized(str(extracted[key]))
        for key, value in required.items()
    ):
        return "unavailable"
    return (
        "passed"
        if all(
            normalized(str(value)) == normalized(str(extracted[key]))
            for key, value in required.items()
        )
        else "failed"
    )
