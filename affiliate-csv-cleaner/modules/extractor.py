"""
[DOC-P36] Field extraction: brand, scale and year from product titles.

Die cast product titles pack structured data into a predictable shape
("1967 Shelby GT500 1/18 Scale Model by AUTOart") — this module pulls
that structure out into real columns so the WordPress side can filter
and display by brand/scale/year without ever parsing titles again.

All three patterns are regexes read from the config, NOT hardcoded,
because different networks format titles differently — a new network
means a new JSON file, not a code change.
"""

import re

from modules.utils import format_row_warning, safe_str


def _compile(config, key, fallback):
    """
    [DOC-P37] Compile one extraction pattern from the config.

    Invalid user-supplied regex falls back to the built-in default
    with no crash: a typo in a config should degrade extraction, not
    kill a whole cleaning run.

    Args:
        config (dict): loaded config.
        key (str): pattern name inside config["extraction"].
        fallback (str): default pattern when missing/invalid.

    Returns:
        Pattern: compiled regex.
    """
    pattern = config.get("extraction", {}).get(key, fallback)
    try:
        return re.compile(pattern)
    except re.error:
        return re.compile(fallback)


def extract_brand(title, brand_re):
    """
    [DOC-P38] Extract the brand from a "... by BrandName" title ending.

    Anchored to the END of the title because that is where this feed
    consistently puts attribution — matching "by" mid-title would
    false-positive on strings like "Inspired by the 1960s". The
    captured brand keeps its original capitalisation (brands are
    proper nouns; "autoart" would be wrong).

    Args:
        title (str): cleaned product title.
        brand_re (Pattern): compiled brand pattern with one capture group.

    Returns:
        str: brand name, or "" when the title has no "by ..." ending.
    """
    match = brand_re.search(safe_str(title))
    return match.group(1).strip() if match else ""


def extract_scale(title, scale_re):
    """
    [DOC-P39] Extract the model scale (1/18, 1/24, 1:43, ...).

    The default pattern accepts both "/" and ":" separators because
    manufacturers write the same scale both ways, and normalises the
    output to the "1/18" form so the WordPress scale field never holds
    two spellings of the same value (which would split archive
    filters into duplicate buckets).

    Args:
        title (str): cleaned product title.
        scale_re (Pattern): compiled scale pattern; group 1 = denominator.

    Returns:
        str: normalised scale like "1/24", or "".
    """
    match = scale_re.search(safe_str(title))
    if not match:
        return ""
    return "1/" + match.group(1)


def extract_year(title, year_re):
    """
    [DOC-P40] Extract a 4-digit model year from the START of the title.

    Anchored to the start because that is where car model years live
    ("1967 Shelby GT500 ..."); an unanchored match would wrongly grab
    "2024" from "... 2024 Edition" packaging notes or a scale like
    "1/400" digit runs. The 1900–2030 range is enforced by the pattern
    itself so junk numbers ("9999") never leak into the column.

    Args:
        title (str): cleaned product title.
        year_re (Pattern): compiled year pattern with one capture group.

    Returns:
        str: the year, or "".
    """
    match = year_re.match(safe_str(title))
    return match.group(1) if match else ""


def extract_fields(rows, config, warnings=None):
    """
    [DOC-P41] Add brand / scale / year columns to every row.

    Runs AFTER cleaning so the patterns see trimmed, encoding-repaired
    titles. Extraction is additive-only: the original post_title is
    never modified, because the title as the merchant wrote it is what
    shoppers search for. Skippable via steps.extract_fields for feeds
    whose titles carry no structure.

    Each row is fenced individually: a title that somehow breaks
    extraction gets the row logged and skipped, not the run killed.

    Args:
        rows (list): cleaned row dicts.
        config (dict): loaded config.
        warnings (list or None): shared warning collector; skipped
            rows are appended as formatted lines when provided.

    Returns:
        list: rows with brand/scale/year keys added.
    """
    patterns_on = config["steps"].get("extract_fields", True)

    brand_re = _compile(config, "brand_pattern", r"\bby\s+([A-Z][\w&.\- ]*?)\s*$")
    scale_re = _compile(config, "scale_pattern", r"\b1[/:](18|24|32|43|64|100|400)\b")
    year_re = _compile(config, "year_pattern", r"^(19\d{2}|20[0-2]\d|2030)\b")

    kept = []
    for row in rows:
        try:
            title = safe_str(row.get("post_title", ""))
            row["brand"] = extract_brand(title, brand_re) if patterns_on else ""
            row["scale"] = extract_scale(title, scale_re) if patterns_on else ""
            row["year"] = extract_year(title, year_re) if patterns_on else ""
            kept.append(row)
        except Exception as err:  # noqa: BLE001 — skip the row, keep the run alive.
            if warnings is not None:
                warnings.append(format_row_warning(row, "field extraction", err))

    return kept
