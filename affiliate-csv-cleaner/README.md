# Affiliate CSV Cleaner

A command-line tool that takes a raw affiliate-network CSV export (AWIN
die cast feed by default) and turns it into a clean, SEO-enriched CSV
ready for the **Affiliate Product Importer** WordPress plugin in this
repo.

Feed exports are messy: duplicate SKUs, HTML soup in descriptions,
broken characters (`â€™` instead of `'`), product photos buried inside
HTML instead of in their own columns. Fixing that *before* import takes
seconds here; fixing it *after* import means editing thousands of
WordPress posts. Always clean first.

## Requirements

- Python 3 (already installed on macOS — no `pip install` needed; the
  tool uses only the standard library)

## Quick start

```bash
cd affiliate-csv-cleaner

# 1. Scan a feed first — see what's wrong without changing anything:
python3 clean_csv.py --input ~/Downloads/awin-feed.csv --detect-only

# 2. Clean it (uses the AWIN config by default):
python3 clean_csv.py --input ~/Downloads/awin-feed.csv --config config/awin_default.json
```

The tool always shows a **detection report** first and asks you to
confirm before it writes anything. The cleaned file is saved next to
your input file as `[original name]_cleaned.csv` — your original file
is never modified.

For scripted/automated runs, add `--yes` to skip the confirmation
prompt.

## Reading the detection report

- **Green ✓** — nothing wrong.
- **Yellow !** — problems found, but the cleaner will fix them
  automatically (duplicates, HTML, encoding, empty rows).
- **Red ✗** — data is *missing* (no title, no image, no price). The
  tool cannot invent missing data; fix these at the source or accept
  that those products will import incomplete.

If the numbers look wrong (e.g. almost everything counted as missing),
the usual cause is a config whose `column_mapping` doesn't match the
file — answer `N` at the prompt and check the config.

## What the cleaner does, in order

1. **Detect** — counts rows, duplicate SKUs, HTML/semicolon
   descriptions, encoding damage, missing images/titles/prices.
2. **Clean** — removes empty rows, trims whitespace, repairs encoding,
   decodes HTML entities (`&amp;`, `&rsquo;`, `&nbsp;`, ...),
   normalizes descriptions in all three formats AWIN ships (real HTML
   is stripped to text; *stripped-tag debris* — where AWIN already
   removed the angle brackets and left `/div/lilidiv alignleft...`
   glued into the text — is scrubbed back into bullet lines; semicolon
   lists become one feature per line), discards embedded CSS/iframe/
   editor junk, and removes duplicate SKUs (first occurrence wins).
3. **Extract** — pulls `brand` ("... by AUTOart"), `scale` (1/18,
   1:43, ...) and `year` (1900–2030 at the start of the title) out of
   product titles into their own columns.
4. **Images** — picks `main_image` (AWIN image URL, falling back to the
   merchant image URL), digs gallery photos out of the raw HTML
   description into `gallery_image_1..6`, and strips tracking
   parameters (`utm_*`, `clickref`, ...) from image URLs.
5. **SEO enrich** — builds a prose `description` that opens and closes
   with the product title, plus `seo_title` (≤60 chars),
   `seo_description` (150–160 chars using title/brand/scale),
   `image_filename` (unique, hyphenated, ≤60 chars), `image_alt`, and
   `gallery_alt_1..6`.
6. **Write** — saves the cleaned CSV and prints the final summary
   (rows in/out, duplicates removed, rows skipped with errors,
   enriched count, time taken).

## Error handling (production hardening)

The tool is built to survive bad data rather than die on it:

- **No cell type can crash it.** Every cell read goes through a safe
  converter — ragged rows (too many/too few columns), numbers in text
  fields, and Apple Numbers' junk `Table 1` title line are all
  salvaged automatically.
- **One bad row never kills the run.** If a row still manages to break
  a pipeline stage, it is skipped, counted in the summary
  (`Rows skipped with errors`), and logged to **`warnings.log`** next
  to the cleaned CSV, one line per row:
  `Row 247: cleaning failed — ValueError: ... | Raw data: <first 100 chars>`
  The row number is the physical line in your source file. No
  `warnings.log` = nothing went wrong.
- **Encodings**: UTF-8 (with or without Excel's BOM) → Windows-1252 →
  Latin-1 are tried in order; nothing readable is rejected.
- **Mapping mismatches warn, not crash**: if none of a field's mapped
  source columns exist in the CSV you get a clear warning up front and
  the run continues. `column_mapping` may list *multiple* source
  columns for the same WordPress field as fallbacks — the first
  non-empty one wins (AWIN ships prices as `search_price` or `price`
  depending on the export, so the default config maps both).
- **Empty or header-only files** exit cleanly with a message instead
  of a traceback.

## Output columns

The mapped WordPress fields first, then the generated ones:

```
affiliate_url, post_title, aw_product_id, sku, merchant_image_url,
main_image, features, category, price, merchant_url,
brand, scale, year,
description, seo_title, seo_description, image_filename, image_alt,
gallery_image_1..6, gallery_alt_1..6
```

`features` holds one bullet per line (matches the ACF *Features*
field); `description` is flowing prose intended for the post content.
Map them in the importer's **Mapping Profiles** tab once and save the
profile — the column set is identical on every run.

## Configuration

Everything network-specific lives in a JSON file — never in code.
`config/awin_default.json` is our AWIN die cast feed;
`config/example_custom.json` is a commented starting point for other
networks. To support a new network: copy the example, rename it, and
edit these sections.

| Section | What it does |
| --- | --- |
| `column_mapping` | Left side = the network's CSV headers, right side = WordPress field names. Unmapped source columns are dropped. Several sources may map to one field as fallbacks; first non-empty wins. |
| `steps` | Every cleaning step toggled `true`/`false` individually. |
| `extraction` | The brand/scale/year regex patterns, per network. |
| `seo` | The opening/closing sentence templates (`{title}` is replaced). Vary these between niche sites. |
| `tracking_params` | Query parameters stripped from image URLs. `utm_*` matches by prefix. |
| `duplicate_field` | Which mapped field identifies duplicates (default `sku`). |
| `max_gallery_images` | How many gallery columns to produce (default 6). |

## Testing

A 5-row test feed covering every scenario (clean semicolons, HTML with
buried images, duplicate SKU, brand/scale/year title, missing image
fallback) lives at `test/sample_awin.csv`:

```bash
python3 clean_csv.py --input test/sample_awin.csv --detect-only
python3 clean_csv.py --input test/sample_awin.csv --yes
```

The hardening test suite (safe type coercion, row skipping,
`warnings.log`, malformed-CSV end-to-end) runs with:

```bash
python3 test/test_hardening.py
```

## Troubleshooting

- **"Config file not found"** — run from the `affiliate-csv-cleaner`
  folder, or pass the full path to the config.
- **Weird characters remain** — add the offending sequence to
  `_MOJIBAKE_MAP` in `modules/cleaner.py` (each entry is one bad→good
  replacement).
- **Brand/scale/year came out empty** — the titles in your feed don't
  match the default patterns; adjust the `extraction` regexes in your
  config (they are standard Python regular expressions).
