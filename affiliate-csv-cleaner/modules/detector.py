"""
[DOC-P20] Pre-clean detection: scan the raw CSV and count problems.

Detection is read-only and runs before anything else so the user can
see what the cleaner is ABOUT to do (and what it cannot fix) before a
single byte is written. The counts feed reporter.print_detection_report
and the go/no-go confirmation prompt.

The scanner works on the RAW rows with the feed's own column names —
mapping happens after confirmation — so all lookups go through the
config's column_mapping in reverse (find the source column whose
target is "sku", etc.).
"""

import html
import re

from modules.utils import format_row_warning, safe_str

# Matches anything that looks like an HTML tag. Intentionally loose:
# for DETECTION we only need "does this cell contain markup?", not a
# parse — false positives on a stray "<" are acceptable in a report.
_HTML_TAG_RE = re.compile(r"<[a-zA-Z/!][^>]*>")

# Matches the corpses of HTML tags whose angle brackets were stripped
# UPSTREAM (AWIN's exporter removes < > " = from descriptions), which
# leaves fragments like "/div/lilidiv alignleft" glued into the text.
# This is a distinct format from real HTML — there are no tags left to
# parse, only debris to scrub — so it gets its own detector.
#
# Detection is deliberately GENEROUS where the scrubber is surgical: a
# false positive here just routes a row through scrub_tag_debris(),
# whose patterns won't touch clean prose, while a false negative
# ships tag junk to a product page. The alternatives cover: closing
# tags with a hard boundary ("/div "), closing/opening spans glued
# straight into words or digits ("/spandie", "span118", nested
# "spanspan"), glued list-markup ("ulli", "lilidiv"), and de-quoted
# attribute fingerprints ("alignleft", "stylecolor", "stylefont-...").
_TAG_DEBRIS_RE = re.compile(
    r"/(?:div|span|ul|ol|li|table|strong|em|h[1-6]|td|tr)\b"
    r"|/span(?=[a-z0-9])"
    r"|/(?:li|ul|ol)(?:li|ul|ol)"
    r"|^(?:ul|ol)li"
    r"|(?:^|\s)span\d"
    r"|(?:span){2,}"
    r"|(?:^|\s)(?:ul)?li(?:li|div)"
    r"|align(?:left|right|center|justify)"
    r"|style(?:color|font|width|height|margin|padding|text|background|size|display)"
)

# Fingerprints of UTF-8 text that was decoded as latin-1/cp1252
# somewhere upstream ("â€™" instead of "'", "Ã©" instead of "é").
# These two byte-pair prefixes cover the overwhelming majority of
# real-world mojibake in merchant feeds.
_MOJIBAKE_RE = re.compile(r"â€|Ã[\x80-\xBF©¨¼¶¤]")


def _source_columns(config, target):
    """
    [DOC-P21] Find the raw CSV columns that map to a target field.

    Detection happens before column renaming, so "which column holds
    the SKU?" must be answered by inverting the config's mapping.
    Centralised here so the inversion logic exists once. Returns ALL
    sources for the target, in config order, because mappings support
    fallback sources ([DOC-P05]) — detection judging only the first
    source would report "Missing prices: 17,742" for a feed whose
    prices live happily in the second.

    Args:
        config (dict): loaded config with "column_mapping".
        target (str): target field name (e.g. "sku").

    Returns:
        list: source column names (empty when unmapped).
    """
    return [
        source for source, mapped_target in config["column_mapping"].items()
        if mapped_target == target
    ]


def _first_value(row, columns):
    """
    [DOC-P71] First non-empty cell among fallback source columns.

    Mirrors apply_column_mapping's fallback rule exactly, so the
    detection report and the mapped output always agree on which
    value a field ends up with.

    Args:
        row (dict): raw row dict.
        columns (list): source column names in config order.

    Returns:
        str: the first non-empty value, or "".
    """
    for column in columns:
        value = safe_str(row.get(column)).strip()
        if value:
            return value
    return ""


def has_html(text):
    """
    [DOC-P22] Does this text contain HTML markup?

    Used both here (to count HTML descriptions) and by the cleaner (to
    pick which of the two description-cleaning strategies applies to a
    row), so both always agree on what counts as "HTML".

    Args:
        text (str): cell value.

    Returns:
        bool: True when an HTML tag is present.
    """
    return bool(_HTML_TAG_RE.search(safe_str(text)))


def has_tag_debris(text):
    """
    [DOC-P58] Does this text contain stripped-tag debris?

    True for descriptions where an upstream exporter already removed
    the angle brackets from HTML, leaving tag names and attribute
    values embedded in the text ("/div/lilidiv alignleftRubber
    tires./div..."). Shared between detection (counted with HTML
    descriptions, since both are markup damage that will be cleaned)
    and the cleaner's routing in clean_description(), so both always
    agree on which rows are in this format.

    Args:
        text (str): cell value.

    Returns:
        bool: True when stripped-tag debris is present.
    """
    return bool(_TAG_DEBRIS_RE.search(safe_str(text)))


def is_semicolon_list(text):
    """
    [DOC-P23] Is this a clean semicolon-separated feature list?

    True only when the text has NO HTML and at least two non-empty
    semicolon-separated segments. The two-segment minimum stops a
    normal sentence that merely contains one semicolon from being
    shredded into fake "bullet points".

    Args:
        text (str): cell value.

    Returns:
        bool: True for semicolon-list descriptions.
    """
    text = safe_str(text)
    if not text or has_html(text):
        return False
    segments = [segment.strip() for segment in text.split(";")]
    return len([segment for segment in segments if segment]) >= 2


def looks_mojibake(text):
    """
    [DOC-P24] Does this text show UTF-8/latin-1 mojibake damage?

    Shared with the cleaner's encoding fixer so detection counts and
    actual fixes always refer to the same set of rows.

    Args:
        text (str): cell value.

    Returns:
        bool: True when mojibake fingerprints are present.
    """
    return bool(_MOJIBAKE_RE.search(safe_str(text)))


def scan(rows, headers, config, warnings=None):
    """
    [DOC-P25] Scan all raw rows and return the detection counters.

    One single pass over the file collects every statistic at once
    (rather than one pass per statistic) — with a multi-thousand-row
    feed this keeps detection effectively instant, which matters
    because it runs on EVERY invocation including --detect-only.

    "Missing image" counts a row only when BOTH image columns are
    empty, because the pipeline's fallback ([DOC-P47]) means a row
    with either one will end up with a usable main_image.

    All cell reads go through safe_str() ([DOC-P62]) and each row is
    individually fenced: a row detection can't read is logged to the
    warnings list and left out of the counters, never allowed to kill
    the scan — detection is advisory, so degraded counts beat no run.

    Args:
        rows (list): raw row dicts (source column names).
        headers (list): the CSV's header names (unused columns are
            fine; kept for future header-level checks).
        config (dict): loaded config.
        warnings (list or None): shared warning collector; row-level
            failures are appended as formatted lines when provided.

    Returns:
        dict: counters consumed by reporter.print_detection_report().
    """
    sku_cols = _source_columns(config, config.get("duplicate_field", "sku"))
    title_cols = _source_columns(config, "post_title")
    price_cols = _source_columns(config, "price")
    desc_cols = _source_columns(config, config.get("description_field", "features"))
    image_cols = _source_columns(config, "main_image")
    fallback_image_cols = _source_columns(config, "merchant_image_url")

    stats = {
        "total_rows": len(rows),
        "duplicate_skus": 0,
        "html_descriptions": 0,
        "semicolon_descriptions": 0,
        "encoding_issues": 0,
        "empty_rows": 0,
        "missing_images": 0,
        "missing_titles": 0,
        "missing_prices": 0,
    }

    seen_skus = set()

    for row in rows:
        try:
            # Private pipeline keys (_row_num) are excluded so a row
            # whose real cells are all blank still counts as empty.
            # Keys are matched with an isinstance guard because a
            # ragged row's overflow key is None, not a string.
            values = [
                safe_str(value).strip()
                for key, value in row.items()
                if not (isinstance(key, str) and key.startswith("_"))
            ]
            if not any(values):
                stats["empty_rows"] += 1
                continue

            sku = _first_value(row, sku_cols)
            if sku:
                if sku in seen_skus:
                    stats["duplicate_skus"] += 1
                seen_skus.add(sku)

            # Entities are decoded BEFORE the format checks for the same
            # reason the cleaner decodes them first ([DOC-P34]): "&rsquo;"
            # ends in a semicolon, so undecoded entities make prose
            # descriptions masquerade as semicolon lists.
            description = html.unescape(_first_value(row, desc_cols))
            if has_html(description) or has_tag_debris(description):
                # Both real markup and stripped-tag debris count as "HTML
                # descriptions" in the report: same damage, same promise
                # ("will strip to text"), different repair strategy.
                stats["html_descriptions"] += 1
            elif is_semicolon_list(description):
                stats["semicolon_descriptions"] += 1

            if any(looks_mojibake(value) for value in values):
                stats["encoding_issues"] += 1

            if not _first_value(row, image_cols) and not _first_value(row, fallback_image_cols):
                stats["missing_images"] += 1

            if title_cols and not _first_value(row, title_cols):
                stats["missing_titles"] += 1

            if price_cols and not _first_value(row, price_cols):
                stats["missing_prices"] += 1
        except Exception as err:  # noqa: BLE001 — one unreadable row must not kill the scan.
            if warnings is not None:
                warnings.append(format_row_warning(row, "detection", err))

    return stats
