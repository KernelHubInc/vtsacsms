from datetime import date

import pytest

from app.config import settings
from app.optical import data_matches, digit, inspect_texts
from app.providers import decide
from app.schemas import Status


def passport_text():
    first = "P<PHLSYNTHETIC<<TEST<PERSON".ljust(44, "<")
    number, birth, expiry, optional = "TST000001", "900101", "310101", "<" * 14
    second = (
        number
        + digit(number)
        + "PHL"
        + birth
        + digit(birth)
        + "M"
        + expiry
        + digit(expiry)
        + optional
        + digit(optional)
    )
    return first + "\n" + second + digit(second[:10] + second[13:20] + second[21:43])


PHILSYS = """REPUBLIKA NG PILIPINAS
PHILIPPINE IDENTIFICATION
1234-0000-0000-0000
Apelyido/Last Name
SYNTHETIC
Mga Pangalan/Given Names
TEST
Middle Name
PERSON
Date of Birth
JANUARY 1, 1990
"""
LICENSE = """REPUBLIC OF THE PHILIPPINES
LAND TRANSPORTATION OFFICE
DRIVER'S LICENSE
Last Name, First Name, Middle Name
SYNTHETIC, TEST PERSON
Date of Birth: 1990/01/01
License No.: T00-00-000001
Expiration Date: 2031/01/01
"""


@pytest.mark.parametrize(
    "kind,text", [("philsys", PHILSYS), ("drivers_license", LICENSE), ("passport", passport_text())]
)
def test_supported_optical_profiles_require_readable_fields_and_match_personal(kind, text):
    fields, check = inspect_texts(kind, {"front": text, "back": "SYNTHETIC REVERSE"})
    assert check == "passed"
    assert fields["full_name"] == "TEST PERSON SYNTHETIC"
    assert fields["birth_date"] == "1990-01-01"
    assert data_matches(fields, {key: value for key, value in fields.items() if value}) == "passed"
    assert data_matches(fields, {"full_name": "ANOTHER PERSON"}) == "failed"
    assert data_matches(fields, {"nationality": "XX"}) != "passed"


def test_mrz_corruption_unknown_templates_missing_back_and_missing_data_do_not_pass():
    assert inspect_texts("passport", {"front": passport_text()[:-1] + "8"})[1] == "failed"
    assert (
        inspect_texts("passport", {"front": "Full name: TEST PERSON\nDocument number: 123"})[1]
        == "unavailable"
    )
    assert inspect_texts("philsys", {"front": PHILSYS})[1] == "unavailable"
    assert (
        inspect_texts(
            "drivers_license",
            {"front": LICENSE.replace("2031/01/01", "unreadable"), "back": "reverse"},
        )[1]
        == "unavailable"
    )
    assert inspect_texts("unknown", {"front": PHILSYS, "back": "reverse"})[1] == "unavailable"
    assert data_matches({}, {}) == "unavailable"
    assert data_matches({"full_name": "---"}, {"full_name": "!!!"}) == "unavailable"


def test_optical_policy_does_not_silently_replace_issuer_assurance(monkeypatch):
    class Today(date):
        @classmethod
        def today(cls):
            return cls(2026, 9, 24)

    monkeypatch.setattr("app.providers.date", Today)
    settings().mode = "self_hosted"
    checks = {
        key: "passed"
        for key in ("ocr", "optical_document", "document_data", "face_match", "liveness")
    }
    checks["document"] = "unavailable"
    assert decide(checks, {}, "optical_v1") == (Status.APPROVED, "OPTICAL_VERIFIED")
    assert decide(checks, {}, "issuer_v1")[0] == Status.NEEDS_REVIEW
    assert decide(checks, {}, "unknown")[0] == Status.NEEDS_REVIEW
    for key in ("ocr", "optical_document", "document_data", "face_match", "liveness"):
        for outcome in ("failed", "unavailable", "mock"):
            assert decide({**checks, key: outcome}, {}, "optical_v1")[0] == Status.NEEDS_REVIEW
        assert (
            decide({k: v for k, v in checks.items() if k != key}, {}, "optical_v1")[0]
            == Status.NEEDS_REVIEW
        )
    assert (
        decide(checks, {"expiration_date": "2026-09-23"}, "optical_v1")[0] == Status.ACTION_REQUIRED
    )
