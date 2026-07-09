#!/usr/bin/env python3
"""
[DOC-P01] Affiliate CSV Cleaner — main entry point.

Prepares raw affiliate-network CSV exports (AWIN die cast feed by
default) for import into WordPress via the Affiliate Product Importer
plugin (Phase 5). The pipeline: detect issues -> confirm -> clean ->
extract brand/scale/year -> pull gallery images out of HTML -> SEO
enrichment -> write [name]_cleaned.csv.

This is a standalone pre-processing tool that runs BEFORE anything
touches WordPress, and on purpose: fixing a feed in Python is fast and
repeatable, while fixing thousands of bad posts after a WordPress
import is neither. It uses only the Python standard library so it runs
on the stock macOS python3 with zero pip installs — important because
this repo gets cloned per niche site and every extra setup step is a
step someone will skip.

Usage:
    python3 clean_csv.py --input file.csv --config config/awin_default.json
    python3 clean_csv.py --input file.csv --detect-only

Doc tags [DOC-Pxx] use the P prefix so Python docs never collide with
the WordPress PHP [DOC-xxx] sequence used elsewhere in this repo.
"""

import argparse
import csv
import json
import os
import sys
import time

# Make "modules" importable no matter which directory the user runs
# the script from (beginners run it from anywhere).
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from modules import cleaner, detector, extractor, image_processor, reporter, seo_enricher, utils

# Merchant descriptions with embedded galleries can exceed the csv
# module's default 128KB field cap, which aborts the WHOLE read with
# a csv.Error mid-file. 10MB is far beyond any real product cell but
# still small enough that a corrupt file can't eat all memory.
csv.field_size_limit(10 * 1024 * 1024)


def parse_args():
    """
    [DOC-P02] Define and parse the command-line interface.

    Only two flags are required knowledge for day-to-day use (--input
    and --detect-only); --config defaults to the AWIN config so the
    common case is one short command. --yes exists for scripted /
    non-interactive runs (CI, cron) where the confirmation prompt in
    [DOC-P06] would otherwise hang forever waiting for a keyboard.

    Returns:
        argparse.Namespace: the parsed arguments.
    """
    parser = argparse.ArgumentParser(
        description="Clean an affiliate network CSV export for WordPress import.",
    )
    parser.add_argument("--input", required=True, help="Path to the CSV file to clean.")
    parser.add_argument(
        "--config",
        default=os.path.join("config", "awin_default.json"),
        help="Path to a JSON config (default: config/awin_default.json).",
    )
    parser.add_argument(
        "--detect-only",
        action="store_true",
        help="Scan the file and print the detection report, then exit without cleaning.",
    )
    parser.add_argument(
        "--yes",
        action="store_true",
        help="Skip the confirmation prompt (for scripted runs).",
    )
    return parser.parse_args()


def load_config(config_path):
    """
    [DOC-P03] Load and sanity-check the JSON config.

    The path is tried as given first (relative to the current
    directory), then relative to this script's own folder — so
    "--config config/awin_default.json" works whether the user is
    inside affiliate-csv-cleaner/ or at the repo root. Failing loudly
    with a plain-English message (not a traceback) is deliberate:
    the target user is a site builder, not a Python developer.

    Args:
        config_path (str): path supplied on the command line.

    Returns:
        dict: the parsed config.
    """
    candidates = [
        config_path,
        os.path.join(os.path.dirname(os.path.abspath(__file__)), config_path),
    ]

    for candidate in candidates:
        if os.path.isfile(candidate):
            try:
                with open(candidate, "r", encoding="utf-8") as handle:
                    config = json.load(handle)
            except json.JSONDecodeError as err:
                reporter.error("Config file %s is not valid JSON: %s" % (candidate, err))
                sys.exit(1)

            for required_key in ("column_mapping", "steps"):
                # Present AND a dict: a "steps": [] typo would
                # otherwise surface 200 lines later as a baffling
                # AttributeError instead of a named config problem.
                if not isinstance(config.get(required_key), dict):
                    reporter.error(
                        'Config %s is missing the required "%s" section (or it is not a JSON object).'
                        % (candidate, required_key)
                    )
                    sys.exit(1)
            return config

    # A missing config is almost always a typo'd filename, so the fix
    # is fastest when the error names what IS available.
    reporter.error("Config file not found: %s" % config_path)
    config_dir = os.path.join(os.path.dirname(os.path.abspath(__file__)), "config")
    if os.path.isdir(config_dir):
        available = sorted(name for name in os.listdir(config_dir) if name.endswith(".json"))
        if available:
            reporter.info("Available configs: " + ", ".join("config/" + name for name in available))
    sys.exit(1)


def read_csv_rows(input_path):
    """
    [DOC-P04] Read the input CSV into a list of dicts.

    Encoding chain: utf-8-sig -> cp1252 -> latin-1. utf-8-sig IS
    UTF-8, plus tolerance for the BOM Excel prepends to saved CSVs
    (the same gotcha the PHP importer guards against — see [DOC-021]
    in the plugin); without it the first header becomes
    "\\ufeffaw_deep_link" and every mapping silently misses. cp1252 is
    tried second because it is what Excel-on-Windows actually writes
    (and decodes smart quotes CORRECTLY, where latin-1 turns them into
    control characters). latin-1 is the backstop that cannot fail
    (every byte is valid latin-1) — the mojibake it may produce is
    exactly what the encoding-fix cleaning step repairs downstream.

    Every row is tagged with "_row_num": the PHYSICAL line number in
    the source file where the row ends (header and any skipped junk
    line included), so warnings.log points at a line you can jump to
    in a text editor. Leading underscore = pipeline plumbing, excluded
    from the output CSV like every other private key.

    Apple Numbers is the BOM gotcha's sibling: exporting a sheet to
    CSV prepends a junk title line ("Table 1") ABOVE the real header
    row. DictReader then takes "Table 1" as the only header and shoves
    every row's real fields into a list under the None key, which
    crashes the detector. A first line with no commas followed by a
    second line WITH commas can't be a valid header for this data, so
    that first line is skipped. A genuinely single-column CSV is safe:
    its second line has no commas either, so nothing is skipped.

    The whole file is loaded into memory: affiliate feeds for a single
    niche are thousands of rows, not millions, and in-memory lists keep
    every later step simple and re-orderable.

    Args:
        input_path (str): path to the CSV file.

    Returns:
        tuple: (list of row dicts, list of header names).
    """
    if not os.path.isfile(input_path):
        reporter.error("Input file not found: %s" % input_path)
        sys.exit(1)

    for encoding in ("utf-8-sig", "cp1252", "latin-1"):
        try:
            with open(input_path, "r", encoding=encoding, newline="") as handle:
                # Skip a Numbers-style junk title line (see docstring).
                # tell() is captured before the second readline so the
                # handle can be rewound to the real header row.
                first_line = handle.readline()
                after_first = handle.tell()
                second_line = handle.readline()
                skipped_junk = "," not in first_line and "," in second_line
                handle.seek(after_first if skipped_junk else 0)

                reader = csv.DictReader(handle)
                headers = reader.fieldnames or []
                rows = []
                for row in reader:
                    row = dict(row)
                    # reader.line_num counts physical lines from where
                    # the reader started; add the junk line back so
                    # _row_num matches what a text editor shows.
                    row["_row_num"] = reader.line_num + (1 if skipped_junk else 0)
                    rows.append(row)
        except UnicodeDecodeError:
            continue
        except csv.Error as err:
            # Structural damage (NUL bytes, a cell beyond the 10MB
            # cap) aborts the read as a unit — there is no safe way
            # to resynchronise a broken CSV stream mid-file.
            reporter.error("CSV structure error in %s: %s" % (input_path, err))
            sys.exit(1)

        if not headers:
            reporter.error("%s is empty — no header row found." % input_path)
            sys.exit(1)
        return rows, headers

    reporter.error(
        "Could not read %s with any supported encoding (UTF-8, Windows-1252, Latin-1)."
        % input_path
    )
    sys.exit(1)


def apply_column_mapping(rows, config, warnings=None):
    """
    [DOC-P05] Rename source CSV columns to their WordPress field names.

    Runs immediately after detection so every later module works with
    ONE vocabulary (sku, post_title, features, ...) — the same names
    the Phase 5 importer plugin and the ACF fields use — instead of
    each module needing to know AWIN's column names. Unmapped source
    columns are dropped on purpose: feeds carry dozens of columns
    (commission group, stock quantity, ...) that would otherwise
    clutter the cleaned file and confuse the person doing the import.

    The raw description HTML is preserved in a private "_raw_description"
    key before any cleaning, because the image extractor ([DOC-P45])
    must read <img> tags from the ORIGINAL HTML — after the cleaner
    strips tags, those URLs no longer exist anywhere else.

    Multiple sources may map to the SAME target as fallbacks: the
    first source (in config order) with a non-empty value wins. This
    exists because AWIN itself is inconsistent — some exports carry
    the price in "search_price", others in "price" — and one config
    must survive both without shipping a file of blank prices.

    Args:
        rows (list): raw row dicts keyed by source headers.
        config (dict): loaded config with "column_mapping".
        warnings (list or None): shared warning collector; a row that
            cannot be mapped is appended as a formatted line.

    Returns:
        list: row dicts keyed by target field names.
    """
    mapping = config["column_mapping"]
    description_sources = [
        source for source, target in mapping.items()
        if target == config.get("description_field", "features")
    ]

    mapped_rows = []
    for row in rows:
        try:
            mapped = {"_row_num": row.get("_row_num", 0)}
            for source, target in mapping.items():
                value = utils.safe_str(row.get(source)).strip()
                # First non-empty source wins (fallback semantics —
                # see docstring); an empty later source never blanks
                # a value an earlier source already provided.
                if not mapped.get(target):
                    mapped[target] = value
            for source in description_sources:
                raw = utils.safe_str(row.get(source))
                if raw:
                    mapped["_raw_description"] = raw
                    break
            else:
                if description_sources:
                    mapped["_raw_description"] = ""
            mapped_rows.append(mapped)
        except Exception as err:  # noqa: BLE001 — skip the row, keep the run alive.
            if warnings is not None:
                warnings.append(utils.format_row_warning(row, "column mapping", err))

    return mapped_rows


def check_column_mapping(headers, config):
    """
    [DOC-P69] Warn about mapped source columns the CSV doesn't have.

    A mapping whose source column is absent silently yields an empty
    field on every row — exactly how a price-column rename once
    shipped 50 products with blank prices. Missing sources are named
    loudly BEFORE the detection report (so "Missing prices: 17,742"
    reads as the mapping problem it is), then skipped gracefully:
    the remaining mapped columns are still worth cleaning, so this
    warns and continues rather than aborting.

    Warnings are per TARGET, not per source: with fallback mappings
    ([DOC-P05]) a target is only in trouble when NONE of its sources
    exist — warning about one absent fallback while its sibling is
    present would train people to ignore the warning.

    Args:
        headers (list): the CSV's actual header names.
        config (dict): loaded config with "column_mapping".
    """
    header_set = set(headers)
    sources_by_target = {}
    for source, target in config["column_mapping"].items():
        sources_by_target.setdefault(target, []).append(source)

    for target, sources in sources_by_target.items():
        if not any(source in header_set for source in sources):
            names = ", ".join('"%s"' % source for source in sources)
            reporter.warn(
                'Config maps %s -> "%s", but the CSV has none of those headers — '
                'that field will be empty.' % (names, target)
            )


def confirm_proceed(auto_yes):
    """
    [DOC-P06] Ask the user to confirm before cleaning starts.

    Detection always runs first and prints its report; this prompt is
    the moment to bail out if the numbers look wrong (e.g. 90% missing
    SKUs usually means the wrong config/mapping, and proceeding would
    produce garbage). Anything other than y/yes aborts — defaulting to
    "no" is the safe direction for a tool that writes files.

    Args:
        auto_yes (bool): True when --yes was passed (skip the prompt).

    Returns:
        bool: True to proceed.
    """
    if auto_yes:
        return True

    try:
        answer = input("\nProceed with cleaning? [y/N] ").strip().lower()
    except EOFError:
        # No stdin available (piped/cron run without --yes): abort
        # rather than guess.
        return False

    return answer in ("y", "yes")


def build_output_fieldnames(config):
    """
    [DOC-P07] Fixed column order for the cleaned CSV.

    Mapped fields first (in config order), then the extracted and
    generated columns in a stable, documented order. A FIXED order —
    rather than "whatever dict order fell out of processing" — means
    two runs of the tool always produce diff-able files and the
    WordPress importer's saved mapping profile (Phase 5, Mapping
    Profiles tab) keeps matching run after run.

    Args:
        config (dict): loaded config.

    Returns:
        list: ordered output column names.
    """
    fieldnames = list(dict.fromkeys(config["column_mapping"].values()))

    max_gallery = utils.safe_int(config.get("max_gallery_images", 6), 6)
    generated = ["brand", "scale", "year", "description", "seo_title", "seo_description",
                 "image_filename", "image_alt"]
    generated += ["gallery_image_%d" % i for i in range(1, max_gallery + 1)]
    generated += ["gallery_alt_%d" % i for i in range(1, max_gallery + 1)]

    for name in generated:
        if name not in fieldnames:
            fieldnames.append(name)

    return fieldnames


def write_output(rows, input_path, config):
    """
    [DOC-P08] Write the cleaned rows to [original_filename]_cleaned.csv.

    Saved next to the input file (not into the tool's folder) so the
    cleaned file lands where the person is already working, and the
    original is never overwritten — you can always re-run against the
    untouched source. Internal keys (leading underscore, e.g.
    _raw_description) are excluded: they are pipeline plumbing, not
    product data.

    Args:
        rows (list): cleaned row dicts.
        input_path (str): the original input path.
        config (dict): loaded config.

    Returns:
        str: the path written.
    """
    base, ext = os.path.splitext(input_path)
    output_path = base + "_cleaned" + (ext or ".csv")
    fieldnames = build_output_fieldnames(config)

    with open(output_path, "w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=fieldnames, extrasaction="ignore")
        writer.writeheader()
        for row in rows:
            writer.writerow({key: value for key, value in row.items() if not key.startswith("_")})

    return output_path


def main():
    """
    [DOC-P09] Orchestrate the full pipeline.

    Step order is deliberate and load-bearing:
      1. detect + confirm   (always first — the user approves the plan)
      2. map columns        (one vocabulary for everything after)
      3. clean              (dedupe/encoding/HTML must precede extraction
                             so patterns match repaired text)
      4. extract fields     (brand/scale/year from the cleaned title)
      5. extract images     (from the PRESERVED raw HTML; runs before SEO
                             so gallery_alt_N is only generated for
                             gallery images that actually exist)
      6. SEO enrichment     (last, because seo_description wants the
                             extracted brand/scale and the alt texts want
                             the final image line-up)
      7. write + summary
    """
    args = parse_args()
    config = load_config(args.config)
    start_time = time.time()

    rows, headers = read_csv_rows(args.input)
    reporter.print_header("Affiliate CSV Cleaner", os.path.basename(args.input))

    # Header-only files exit cleanly here: not an error (the export
    # may legitimately have matched zero products), but nothing to do.
    if not rows:
        reporter.warn("The file has a header row but no data rows — nothing to clean.")
        return

    check_column_mapping(headers, config)

    # One warnings list rides through every stage; each entry is a
    # pre-formatted warnings.log line ([DOC-P65]) naming the stage
    # that skipped the row.
    warnings = []

    stats = detector.scan(rows, headers, config, warnings)
    reporter.print_detection_report(stats)

    if args.detect_only:
        reporter.info("Detect-only mode: no changes written.")
        return

    if not confirm_proceed(args.yes):
        reporter.warn("Aborted — nothing was written.")
        return

    rows_in = len(rows)
    rows = apply_column_mapping(rows, config, warnings)

    rows, clean_stats = cleaner.clean_rows(rows, config, warnings)
    rows = extractor.extract_fields(rows, config, warnings)
    rows = image_processor.process_images(rows, config, warnings)
    rows, enriched_count = seo_enricher.enrich_rows(rows, config, warnings)

    output_path = write_output(rows, args.input, config)

    # Anything that left the pipeline without being an intended
    # removal (duplicate/empty) was an error skip — deriving the
    # count from the row arithmetic can't drift from reality.
    rows_skipped = (
        rows_in - len(rows)
        - clean_stats["duplicates_removed"]
        - clean_stats["empty_removed"]
    )

    warnings_log = None
    if warnings:
        try:
            warnings_log = utils.write_warnings_log(
                warnings, os.path.dirname(os.path.abspath(output_path))
            )
        except OSError as err:
            # The log location being unwritable must not hide the
            # warnings themselves — fall back to printing them.
            reporter.error("Could not write warnings.log: %s" % err)
            for line in warnings:
                reporter.warn(line)

    reporter.print_final_summary(
        rows_in=rows_in,
        rows_out=len(rows),
        duplicates_removed=clean_stats["duplicates_removed"],
        empty_removed=clean_stats["empty_removed"],
        enriched=enriched_count,
        output_path=output_path,
        seconds=time.time() - start_time,
        rows_skipped=rows_skipped,
        warnings_log=warnings_log,
    )


if __name__ == "__main__":
    main()
