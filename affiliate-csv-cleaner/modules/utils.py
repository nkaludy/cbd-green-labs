"""
[DOC-P61] Shared hardening helpers: safe type coercion and the
row-warning machinery used by every pipeline stage.

Born from a production crash: an Apple-Numbers-exported CSV smuggled a
LIST into a row dict (csv.DictReader piles a ragged row's extra cells
into a list under the None key), and the detector died calling
.strip() on it. The lesson generalises — feeds come from networks we
don't control, so no module may assume a cell value is a string. This
module is the single choke point for that assumption: every raw cell
read goes through safe_str(), and every per-row failure goes through
format_row_warning() so warnings.log lines look identical no matter
which stage produced them.
"""

import os


def safe_str(value):
    """
    [DOC-P62] Coerce ANY cell value to a text string, never raising.

    Handles every shape csv.DictReader can actually produce, plus the
    shapes a future non-CSV source might:
      - None        -> ""   (DictReader's restval for short rows)
      - str         -> unchanged
      - list/tuple  -> items coerced recursively, empties dropped,
                       joined with ", " (DictReader's restkey overflow:
                       a ragged row's extra cells arrive as a list)
      - dict        -> its values, treated like the list case
      - int/float/
        bool/other  -> str(value)

    Even a hostile object whose own __str__ raises coerces to "" —
    one unconvertible cell is never worth a crashed 17,000-row run.

    Args:
        value: anything a row dict might hold.

    Returns:
        str: the value as text ("" when there is no sane conversion).
    """
    if value is None:
        return ""
    if isinstance(value, str):
        return value
    try:
        if isinstance(value, (list, tuple)):
            parts = (safe_str(item).strip() for item in value)
            return ", ".join(part for part in parts if part)
        if isinstance(value, dict):
            parts = (safe_str(item).strip() for item in value.values())
            return ", ".join(part for part in parts if part)
        return str(value)
    except Exception:  # noqa: BLE001 — coercion must never be the thing that crashes.
        return ""


def safe_int(value, default):
    """
    [DOC-P63] int() that degrades to a default instead of crashing.

    For config values like max_gallery_images: a hand-edited JSON that
    says "six" or 6.0 should fall back to the documented default, not
    kill the run before it starts.

    Args:
        value: candidate value from a config.
        default (int): value to use when conversion fails.

    Returns:
        int: the converted value or the default.
    """
    try:
        return int(value)
    except (TypeError, ValueError):
        try:
            return int(float(value))
        except (TypeError, ValueError):
            return default


def row_preview(row, limit=100):
    """
    [DOC-P64] Compact one-line preview of a row's raw data.

    Used in warnings.log so a skipped row can be found in the source
    file by eye. Private pipeline keys (_row_num, _raw_description)
    are excluded — they are plumbing, not the user's data. Values are
    joined in row order and hard-capped at `limit` characters so one
    row with a 5KB description can't turn the log into a data dump.

    Args:
        row (dict): the row being reported.
        limit (int): maximum preview length.

    Returns:
        str: preview text (may be "" for an empty row).
    """
    if not isinstance(row, dict):
        return safe_str(row)[:limit]
    parts = []
    for key, value in row.items():
        if isinstance(key, str) and key.startswith("_"):
            continue
        text = safe_str(value).strip()
        if text:
            parts.append(text)
    return ", ".join(parts)[:limit]


def format_row_warning(row, stage, error):
    """
    [DOC-P65] Build one warnings.log line for a skipped/troubled row.

    Format (fixed — the README documents it and people grep for it):
        Row 247: cleaning failed — ValueError: ... | Raw data: <=100 chars

    The row number is the PHYSICAL line in the source CSV (tagged as
    _row_num at read time), so "Row 247" is what you jump to in a text
    editor — not a zero-based list index that is off by one or worse
    after the Numbers junk-line skip.

    Args:
        row (dict): the row that caused the problem.
        stage (str): human name of the pipeline stage ("cleaning", ...).
        error (Exception): the caught exception.

    Returns:
        str: the formatted warning line.
    """
    row_num = row.get("_row_num", "?") if isinstance(row, dict) else "?"
    return "Row %s: %s failed — %s: %s | Raw data: %s" % (
        row_num, stage, type(error).__name__, safe_str(error), row_preview(row)
    )


def write_warnings_log(warnings, output_dir):
    """
    [DOC-P66] Write warnings.log next to the cleaned CSV.

    One line per warning, overwritten on every run (a stale log from
    yesterday's feed describing today's row numbers would send someone
    investigating phantom problems). Callers only invoke this when
    there ARE warnings, so an absent warnings.log always means a clean
    run — that absence is information, which is why an empty log is
    never written.

    Args:
        warnings (list): formatted warning lines.
        output_dir (str): directory of the cleaned CSV.

    Returns:
        str: the path written.
    """
    log_path = os.path.join(output_dir, "warnings.log")
    with open(log_path, "w", encoding="utf-8") as handle:
        handle.write("\n".join(warnings) + "\n")
    return log_path
