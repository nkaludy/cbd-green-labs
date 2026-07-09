"""
[DOC-P70] Hardening tests: safe_str coercion, row-level skip+log,
warnings.log, and a malformed-CSV end-to-end run.

Run from the affiliate-csv-cleaner folder (or anywhere):
    python3 test/test_hardening.py

Stdlib unittest only — same zero-install rule as the tool itself
([DOC-P01]). The malformed values used here (None, lists, integers)
are exactly what csv.DictReader produces for short/ragged rows, plus
the shapes a future non-CSV source could feed in; the forced-failure
test exists because the point of the row fence is what happens when a
row DOES raise, and well-hardened code needs a deliberately broken
stage to prove that path.
"""

import csv
import os
import subprocess
import sys
import tempfile
import unittest

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from modules import cleaner, detector, extractor, image_processor, seo_enricher
from modules.utils import format_row_warning, safe_int, safe_str, write_warnings_log

_TOOL_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

# Minimal config in the mapped-row vocabulary, used by the module-level
# tests (the end-to-end test uses the real AWIN config file).
_CONFIG = {
    "column_mapping": {
        "product_name": "post_title",
        "merchant_product_id": "sku",
        "description": "features",
        "search_price": "price",
        "aw_image_url": "main_image",
        "merchant_image_url": "merchant_image_url",
    },
    "steps": {
        "remove_empty_rows": True,
        "trim_whitespace": True,
        "fix_encoding": True,
        "clean_descriptions": True,
        "remove_duplicates": True,
        "extract_fields": True,
        "extract_images": True,
        "seo_enrich": True,
    },
}


class _HostileStr:
    """An object whose own string conversion raises."""

    def __str__(self):
        raise RuntimeError("boom")


def _poisoned_rows():
    """Mapped-shape rows carrying every non-string type at once."""
    return [
        {"_row_num": 2, "sku": "DC-1", "post_title": "Ford GT by Motormax",
         "features": "Opening doors; Rubber tires", "price": "24.99",
         "main_image": "https://x.example/a.jpg", "merchant_image_url": ""},
        # None value, list value, integer in a text field:
        {"_row_num": 3, "sku": None, "post_title": ["1967 Shelby", "GT500"],
         "features": 12345, "price": 89.0,
         "main_image": None, "merchant_image_url": None},
        # Hostile object and a ragged-row overflow key (None key):
        {"_row_num": 4, "sku": "DC-2", "post_title": _HostileStr(),
         "features": None, "price": None,
         "main_image": "", "merchant_image_url": "",
         None: ["overflow-a", "overflow-b"]},
    ]


class SafeStrTests(unittest.TestCase):
    def test_coercions(self):
        self.assertEqual(safe_str(None), "")
        self.assertEqual(safe_str("text"), "text")
        self.assertEqual(safe_str(42), "42")
        self.assertEqual(safe_str(3.5), "3.5")
        self.assertEqual(safe_str(["a", None, " b "]), "a, b")
        self.assertEqual(safe_str({"k1": "v1", "k2": None}), "v1")
        self.assertEqual(safe_str(_HostileStr()), "")
        self.assertEqual(safe_str([1, [2, 3]]), "1, 2, 3")

    def test_safe_int(self):
        self.assertEqual(safe_int(6, 0), 6)
        self.assertEqual(safe_int("6", 0), 6)
        self.assertEqual(safe_int(6.0, 0), 6)
        self.assertEqual(safe_int("6.0", 0), 6)
        self.assertEqual(safe_int("six", 9), 9)
        self.assertEqual(safe_int(None, 9), 9)


class PipelineToleranceTests(unittest.TestCase):
    """The full module pipeline must digest poisoned rows, not crash."""

    def test_detector_scan_survives_poison(self):
        warnings = []
        raw = [
            {"product_name": "OK", "merchant_product_id": "A", "description": "x",
             "search_price": "1.00", "aw_image_url": "u", "merchant_image_url": ""},
            {"product_name": None, "merchant_product_id": None, "description": None,
             "search_price": None, "aw_image_url": None, "merchant_image_url": None,
             None: ["extra1", "extra2"], "_row_num": 3},
            {"product_name": 7, "merchant_product_id": ["L1", "L2"], "description": 0.5,
             "search_price": "", "aw_image_url": "", "merchant_image_url": ""},
        ]
        stats = detector.scan(raw, list(raw[0].keys()), _CONFIG, warnings)
        self.assertEqual(stats["total_rows"], 3)
        self.assertEqual(warnings, [])  # salvaged, not skipped

    def test_full_pipeline_survives_poison(self):
        warnings = []
        rows = _poisoned_rows()
        rows, _stats = cleaner.clean_rows(rows, _CONFIG, warnings)
        rows = extractor.extract_fields(rows, _CONFIG, warnings)
        rows = image_processor.process_images(rows, _CONFIG, warnings)
        rows, _count = seo_enricher.enrich_rows(rows, _CONFIG, warnings)
        self.assertEqual(len(rows), 3)
        self.assertEqual(warnings, [])
        # The list title was salvaged into a joined string:
        self.assertEqual(rows[1]["post_title"], "1967 Shelby, GT500")
        # The integer text field became a string:
        self.assertEqual(rows[1]["features"], "12345")
        # The hostile object degraded to "" instead of crashing:
        self.assertEqual(rows[2]["post_title"], "")


class RowSkipTests(unittest.TestCase):
    """When a row DOES raise, it must be skipped and logged, alone."""

    def test_failing_row_skipped_and_logged(self):
        original = cleaner.clean_description

        def exploding(text):
            if "DETONATE" in safe_str(text):
                raise ValueError("synthetic failure for test")
            return original(text)

        cleaner.clean_description = exploding
        try:
            warnings = []
            rows = [
                {"_row_num": 2, "sku": "A", "post_title": "Good one",
                 "features": "fine", "price": "1"},
                {"_row_num": 247, "sku": "B", "post_title": "Bad one",
                 "features": "DETONATE", "price": "2"},
                {"_row_num": 4, "sku": "C", "post_title": "Also good",
                 "features": "fine too", "price": "3"},
            ]
            cleaned, _stats = cleaner.clean_rows(rows, _CONFIG, warnings)
        finally:
            cleaner.clean_description = original

        self.assertEqual([row["sku"] for row in cleaned], ["A", "C"])
        self.assertEqual(len(warnings), 1)
        self.assertTrue(warnings[0].startswith("Row 247: cleaning failed"))
        self.assertIn("ValueError", warnings[0])
        self.assertIn("| Raw data: ", warnings[0])
        preview = warnings[0].split("| Raw data: ", 1)[1]
        self.assertLessEqual(len(preview), 100)

    def test_warning_format_and_log_file(self):
        row = {"_row_num": 247, "sku": "X", "features": "y" * 500}
        line = format_row_warning(row, "cleaning", ValueError("bad cell"))
        self.assertTrue(line.startswith("Row 247: cleaning failed — ValueError: bad cell"))

        with tempfile.TemporaryDirectory() as tmp:
            path = write_warnings_log([line], tmp)
            self.assertEqual(os.path.basename(path), "warnings.log")
            with open(path, "r", encoding="utf-8") as handle:
                self.assertEqual(handle.read().strip(), line)


class TemplateFallbackTests(unittest.TestCase):
    def test_broken_seo_template_degrades_to_default(self):
        config = dict(_CONFIG)
        config["seo"] = {"opening_template": "Broken {titel} placeholder"}
        rows = [{"_row_num": 2, "sku": "A", "post_title": "Ford GT",
                 "features": "fine", "price": "1"}]
        rows, count = seo_enricher.enrich_rows(rows, config, [])
        self.assertEqual(count, 1)
        self.assertTrue(rows[0]["description"].startswith("Discover the Ford GT"))


class EndToEndMalformedCsvTests(unittest.TestCase):
    """The CLI must finish cleanly on a structurally damaged file."""

    def test_ragged_and_short_rows(self):
        header = "aw_deep_link,product_name,aw_product_id,merchant_product_id," \
                 "merchant_image_url,description,merchant_category,search_price," \
                 "aw_image_url,merchant_deep_link"
        lines = [
            "Table 1",  # Numbers junk line, on top of everything else
            header,
            # normal row
            "https://a.example/1,Ford GT Model,AW1,DC1,https://m.example/1.jpg,"
            "Opening doors; Rubber tires,Cars,24.99,https://cdn.example/1.jpg,https://m.example/p1",
            # ragged row: four EXTRA cells -> DictReader restkey list
            "https://a.example/2,Chevy Bel Air,AW2,DC2,https://m.example/2.jpg,"
            "Chrome bumpers,Cars,32.50,https://cdn.example/2.jpg,https://m.example/p2,"
            "extra1,extra2,extra3,extra4",
            # short row: two cells -> every other value is None
            "https://a.example/3,Just a title",
            # numeric-only text fields
            "https://a.example/4,12345,67,89,0,111,222,3.14,555,666",
        ]
        with tempfile.TemporaryDirectory() as tmp:
            csv_path = os.path.join(tmp, "malformed.csv")
            with open(csv_path, "w", encoding="utf-8") as handle:
                handle.write("\n".join(lines) + "\n")

            result = subprocess.run(
                [sys.executable, os.path.join(_TOOL_DIR, "clean_csv.py"),
                 "--input", csv_path,
                 "--config", os.path.join(_TOOL_DIR, "config", "awin_default.json"),
                 "--yes"],
                capture_output=True, text=True, timeout=60,
            )
            self.assertEqual(result.returncode, 0,
                             "CLI crashed:\n%s\n%s" % (result.stdout, result.stderr))
            self.assertNotIn("Traceback", result.stderr)

            output_path = os.path.join(tmp, "malformed_cleaned.csv")
            self.assertTrue(os.path.isfile(output_path))
            with open(output_path, "r", encoding="utf-8", newline="") as handle:
                # 4 data records: every damaged row was salvaged. CSV
                # records, not physical lines — cleaned feature cells
                # legitimately contain quoted newlines.
                records = list(csv.DictReader(handle))
                self.assertEqual(len(records), 4)

    def test_header_only_file_exits_cleanly(self):
        with tempfile.TemporaryDirectory() as tmp:
            csv_path = os.path.join(tmp, "empty.csv")
            with open(csv_path, "w", encoding="utf-8") as handle:
                handle.write("aw_deep_link,product_name\n")
            result = subprocess.run(
                [sys.executable, os.path.join(_TOOL_DIR, "clean_csv.py"),
                 "--input", csv_path,
                 "--config", os.path.join(_TOOL_DIR, "config", "awin_default.json"),
                 "--yes"],
                capture_output=True, text=True, timeout=60,
            )
            self.assertEqual(result.returncode, 0)
            self.assertIn("no data rows", result.stdout)


if __name__ == "__main__":
    unittest.main(verbosity=2)
