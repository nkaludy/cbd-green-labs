"""
[DOC-P10] Color-coded terminal output.

Every message the tool prints goes through this module, so the look of
the output (colors, symbols, layout) is defined in exactly one place.
Colors use raw ANSI escape codes rather than a third-party library
(colorama/rich) to keep the tool zero-install — see [DOC-P01].
"""

import os
import sys

# ANSI escape codes. These are the 8-color basics that every terminal
# emulator from the last few decades understands — deliberately not
# 256-color codes, which older CI logs render as garbage.
_RESET = "\033[0m"
_BOLD = "\033[1m"
_RED = "\033[31m"
_GREEN = "\033[32m"
_YELLOW = "\033[33m"
_CYAN = "\033[36m"


def _supports_color():
    """
    [DOC-P11] Decide whether to emit ANSI colors at all.

    Colors are only used when stdout is a real terminal. When output
    is piped to a file or another program (e.g. `... > report.txt`),
    escape codes would show up as literal "\\033[32m" junk, so they
    are switched off. NO_COLOR is honoured because it is the accepted
    cross-tool convention (https://no-color.org) and costs one line.

    Returns:
        bool: True when it is safe to print ANSI codes.
    """
    if os.environ.get("NO_COLOR"):
        return False
    return hasattr(sys.stdout, "isatty") and sys.stdout.isatty()


_COLOR_ON = _supports_color()


def _paint(text, *codes):
    """
    [DOC-P12] Wrap text in ANSI codes (or return it untouched).

    Single choke point for colorization so the "is color enabled"
    decision never leaks into calling code — callers just say what
    they mean (ok/warn/error) and this decides how it looks.

    Args:
        text (str): the text to color.
        *codes (str): ANSI codes to apply.

    Returns:
        str: colored (or plain) text.
    """
    if not _COLOR_ON or not codes:
        return text
    return "".join(codes) + text + _RESET


def print_header(title, subtitle=""):
    """
    [DOC-P13] Print the banner shown at the start of a run.

    A visible banner matters more than it looks: when someone runs the
    tool a dozen times while tuning a config, the banner is the visual
    divider that keeps consecutive runs from blurring together in the
    scrollback.

    Args:
        title (str): main banner text.
        subtitle (str): optional second line (usually the filename).
    """
    line = "=" * 58
    print(_paint(line, _CYAN))
    print(_paint("  " + title, _CYAN, _BOLD))
    if subtitle:
        print(_paint("  " + subtitle, _CYAN))
    print(_paint(line, _CYAN))


def info(message):
    """
    [DOC-P14] Neutral progress message (no color).

    Args:
        message (str): text to print.
    """
    print("  " + message)


def ok(message):
    """
    [DOC-P15] Green success line, prefixed with a check mark.

    Args:
        message (str): text to print.
    """
    print(_paint("  ✓ " + message, _GREEN))


def warn(message):
    """
    [DOC-P16] Yellow warning line — something to look at, not fatal.

    Args:
        message (str): text to print.
    """
    print(_paint("  ! " + message, _YELLOW))


def error(message):
    """
    [DOC-P17] Red error line, sent to stderr.

    stderr (not stdout) so that piping the report to a file still
    shows fatal problems on screen, and exit-code-checking wrappers
    see errors where they expect them.

    Args:
        message (str): text to print.
    """
    print(_paint("  ✗ " + message, _RED), file=sys.stderr)


def print_detection_report(stats):
    """
    [DOC-P18] Print the pre-clean detection report.

    Each finding is colored by severity so the eye lands on problems:
    green = fine, yellow = will be fixed automatically, red = data is
    missing and CANNOT be fixed by this tool (missing titles/images
    have to be fixed at the source or accepted). This report is what
    the user reads before the [DOC-P06] confirmation prompt, so it is
    the whole basis of their go/no-go decision — hence counts, not
    vague words.

    Args:
        stats (dict): counters produced by detector.scan().
    """
    print()
    print(_paint("  DETECTION REPORT", _BOLD))
    print("  " + "-" * 40)

    info("Total rows:                 %d" % stats["total_rows"])

    def _line(label, count, fixable_note):
        text = "%s %d" % (label.ljust(27), count)
        if count == 0:
            print(_paint("  ✓ " + text, _GREEN))
        elif fixable_note:
            print(_paint("  ! " + text + "  (" + fixable_note + ")", _YELLOW))
        else:
            print(_paint("  ✗ " + text + "  (cannot be auto-fixed)", _RED))

    _line("Duplicate SKUs:", stats["duplicate_skus"], "will remove, keep first")
    _line("HTML descriptions:", stats["html_descriptions"], "will strip to text")
    _line("Semicolon descriptions:", stats["semicolon_descriptions"], "will split to bullets")
    _line("Encoding issues:", stats["encoding_issues"], "will repair")
    _line("Empty rows:", stats["empty_rows"], "will remove")
    _line("Missing images:", stats["missing_images"], "")
    _line("Missing titles:", stats["missing_titles"], "")
    _line("Missing prices:", stats["missing_prices"], "")


def print_final_summary(rows_in, rows_out, duplicates_removed, empty_removed,
                        enriched, output_path, seconds, rows_skipped=0,
                        warnings_log=None):
    """
    [DOC-P19] Print the end-of-run summary.

    The in/out/removed numbers are printed together because their
    arithmetic is the fastest sanity check there is: if
    in - duplicates - empty - skipped != out, something swallowed rows
    and the user sees it immediately instead of discovering a short
    import in WordPress a week later.

    Error skips are yellow when non-zero (rows were LOST — worth a
    look in warnings.log) and the log location is only printed when a
    log was actually written: pointing at a file that does not exist
    would send someone hunting for phantom problems.

    Args:
        rows_in (int): rows read from the input file.
        rows_out (int): rows written to the output file.
        duplicates_removed (int): rows dropped as duplicate SKUs.
        empty_removed (int): rows dropped as empty.
        enriched (int): rows that received SEO enrichment.
        output_path (str): where the cleaned file was written.
        seconds (float): wall-clock duration of the run.
        rows_skipped (int): rows dropped because a stage errored.
        warnings_log (str or None): path of warnings.log, when written.
    """
    print()
    print(_paint("  FINAL SUMMARY", _BOLD))
    print("  " + "-" * 40)
    info("Rows in:            %d" % rows_in)
    info("Rows out:           %d" % rows_out)
    info("Duplicates removed: %d" % duplicates_removed)
    info("Empty rows removed: %d" % empty_removed)
    if rows_skipped:
        warn("Rows skipped with errors: %d" % rows_skipped)
    else:
        info("Rows skipped with errors: 0")
    info("Rows SEO-enriched:  %d" % enriched)
    info("Time taken:         %.2f seconds" % seconds)
    if warnings_log:
        warn("Warnings log: %s" % warnings_log)
    ok("Saved: %s" % output_path)
