"""
[DOC-P49] SEO enrichment: descriptions, meta fields, image names/alts.

Affiliate product pages live or die on not looking like every other
site importing the same feed — thin, duplicated content is the classic
affiliate-site SEO failure. This module weaves the product title into
a generated description (opening + closing sentences) and produces the
meta/image fields (seo_title, seo_description, image_filename,
image_alt, gallery alts) that the WordPress side and Rank Math expect.

Sentence templates are configurable per network so cloned niche sites
can vary their phrasing — 30 sites all opening every description with
the identical sentence would itself be a duplicate-content footprint.
"""

import re

from modules.utils import format_row_warning, safe_int, safe_str

# Default sentence templates; {title}, {brand}, {scale} are substituted.
# Overridable via config["seo"]["opening_template"/"closing_template"].
_DEFAULT_OPENING = "Discover the {title}, a detailed die cast replica for serious collectors."
_DEFAULT_CLOSING = "Add the {title} to your collection today."

# Filler used to bring seo_description up into the 150-160 char window.
# Lengths deliberately taper down to a 9-character minimum: the fill
# loop in [DOC-P53] picks the first filler that still FITS the budget,
# so as long as the shortest option is ~10 chars there is always a
# piece small enough to bridge the final gap into the window (a
# uniform list of long sentences left descriptions stuck at ~146
# chars — a bug caught by this tool's own test run).
_META_FILLERS = [
    "A detailed die cast replica for display or collection.",
    "Crafted with authentic detailing.",
    "Great gift for die cast fans.",
    "Fast shipping available.",
    "Order online today.",
    "Collector approved.",
    "In stock now.",
    "Shop now.",
]


def _slugify(text, max_length):
    """
    [DOC-P50] Turn a title into a URL/filename-safe slug.

    Lowercase, non-alphanumerics collapsed to single hyphens, trimmed
    to max_length WITHOUT cutting mid-word (a truncated half-word in a
    filename looks broken and loses its keyword value). Mirrors what
    WordPress's sanitize_title() produces so the filenames this tool
    generates match the ones the Phase 5 importer would generate from
    the same title — no surprises between the two halves of the
    pipeline.

    Args:
        text (str): source text (product title).
        max_length (int): hard cap on slug length.

    Returns:
        str: the slug (may be "" for junk-only input).
    """
    slug = re.sub(r"[^a-z0-9]+", "-", safe_str(text).lower()).strip("-")
    if len(slug) <= max_length:
        return slug

    truncated = slug[:max_length]
    if "-" in truncated:
        truncated = truncated.rsplit("-", 1)[0]
    return truncated.strip("-")


def make_image_filename(title, used_filenames, max_length=60):
    """
    [DOC-P51] Generate a unique, SEO-friendly image filename.

    The slug comes from the product title (the image IS the product,
    so the title is the correct keyword source). Uniqueness across the
    whole file is enforced with -2/-3 suffixes because two products
    with the same truncated slug would otherwise be assigned the same
    filename and one image would overwrite the other after download.

    Extensions are intentionally NOT added here — the real extension
    is only known at download time, and guessing ".jpg" for a .png
    would produce broken files.

    Args:
        title (str): product title.
        used_filenames (set): filenames already handed out this run
            (mutated in place).
        max_length (int): maximum filename length.

    Returns:
        str: unique filename slug.
    """
    base = _slugify(title, max_length) or "product"
    candidate = base
    counter = 2

    while candidate in used_filenames:
        suffix = "-%d" % counter
        candidate = base[: max_length - len(suffix)].rstrip("-") + suffix
        counter += 1

    used_filenames.add(candidate)
    return candidate


def make_seo_title(title, max_length=60):
    """
    [DOC-P52] Build the seo_title: the clean title, capped at 60 chars.

    60 characters is the practical width of a Google result title —
    anything longer gets ellipsised in the SERP, so the cap keeps the
    whole title visible. Truncation happens at a word boundary because
    a chopped half-word in a SERP title reads as broken. Multiple
    internal spaces are collapsed; the title is otherwise left alone
    (it was already cleaned) — "optimization" here means fitting the
    display window, not rewriting the merchant's product name.

    Args:
        title (str): cleaned product title.
        max_length (int): character budget.

    Returns:
        str: SERP-ready title.
    """
    clean = " ".join(safe_str(title).split())
    if len(clean) <= max_length:
        return clean

    truncated = clean[:max_length]
    if " " in truncated:
        truncated = truncated.rsplit(" ", 1)[0]
    return truncated.rstrip(" ,-")


def make_seo_description(title, brand, scale, max_length=160, min_length=150):
    """
    [DOC-P53] Build a 150-160 character meta description.

    Assembled from the real product facts first (title, brand, scale —
    the things a searcher actually typed), then padded into the
    150-160 window with short generic sentences, and finally
    word-boundary-truncated if a long title overshoots. The window
    matters: under ~150 Google often substitutes its own snippet;
    over ~160 it truncates mid-sentence. Best effort — a title longer
    than the whole budget cannot be both complete and under 160, in
    which case fitting the budget wins.

    Brand and scale are only appended when the title does not already
    contain them — die cast titles routinely END with "... by AUTOart"
    or embed "1/18", and blindly appending produced "by AUTOart by
    AUTOart" (caught by this tool's own test run). The fill loop scans
    for the first still-unused filler that FITS the remaining budget
    (not a single sequential pass), so tapering filler lengths can
    always bridge the last few characters into the window.

    Args:
        title (str): cleaned product title.
        brand (str): extracted brand ("" allowed).
        scale (str): extracted scale ("" allowed).
        max_length (int): upper bound (Google truncation point).
        min_length (int): lower bound to aim for.

    Returns:
        str: meta description within (or as close as possible to) the window.
    """
    title = safe_str(title)
    brand = safe_str(brand)
    scale = safe_str(scale)
    parts = ["Shop the %s" % title]
    if brand and brand.lower() not in title.lower():
        parts.append("by %s" % brand)
    if scale and scale not in title and scale.replace("/", ":") not in title:
        parts.append("in %s scale" % scale)
    description = " ".join(parts).rstrip(".") + "."

    unused = list(_META_FILLERS)
    while len(description) < min_length:
        fitting = next(
            (filler for filler in unused
             if len(description) + 1 + len(filler) <= max_length),
            None,
        )
        if fitting is None:
            break
        description += " " + fitting
        unused.remove(fitting)

    if len(description) > max_length:
        truncated = description[:max_length]
        if " " in truncated:
            truncated = truncated.rsplit(" ", 1)[0]
        description = truncated.rstrip(" ,.") + "."

    return description


def enrich_description(features_text, title, opening_template, closing_template):
    """
    [DOC-P54] Generate a prose description with the title woven in.

    Produces a NEW "description" column (opening sentence + the
    cleaned feature lines + closing sentence) rather than modifying
    the bullet-style "features" column, so the WordPress import can
    map features -> ACF features field and description -> post_content
    independently. The title appears in both the opening and closing
    sentence — natural placement at both ends of the copy, which is
    the classic on-page signal for what the page is about.

    Double-injection guard: if the templates ever produce a sentence
    the features text already starts/ends with (e.g. after re-running
    the tool on an already-cleaned file), the sentence is not added
    twice.

    Args:
        features_text (str): cleaned, newline-separated features.
        title (str): cleaned product title.
        opening_template (str): template with {title} placeholder.
        closing_template (str): template with {title} placeholder.

    Returns:
        str: enriched prose description.
    """
    opening = opening_template.format(title=title)
    closing = closing_template.format(title=title)

    body = " ".join(line.rstrip(".") + "." for line in safe_str(features_text).split("\n") if line)

    pieces = []
    if not body.startswith(opening):
        pieces.append(opening)
    if body:
        pieces.append(body)
    if not body.endswith(closing):
        pieces.append(closing)

    return " ".join(pieces)


def make_gallery_alts(row, title, max_gallery):
    """
    [DOC-P55] Generate alt text for each populated gallery slot.

    Alt text is "{title} - view N": the title carries the keywords,
    the view number keeps six images from having six IDENTICAL alts
    (which screen readers announce as six copies of the same image —
    unhelpful — and search engines discount as stuffing). Slots with
    no image get an empty alt, never orphan text describing an image
    that does not exist.

    Args:
        row (dict): row that already has gallery_image_N columns.
        title (str): cleaned product title.
        max_gallery (int): number of gallery slots.
    """
    for index in range(1, max_gallery + 1):
        if row.get("gallery_image_%d" % index):
            row["gallery_alt_%d" % index] = "%s - view %d" % (title, index)
        else:
            row["gallery_alt_%d" % index] = ""


def _template_or_default(template, default):
    """
    [DOC-P68] Validate a config sentence template, fall back on junk.

    A hand-edited config with a typo'd placeholder ("{titel}", a stray
    "{}") would make .format() raise on EVERY row — the whole file
    would be "skipped rows" and the output empty. One dry run against
    a dummy title catches that up front, so a broken template degrades
    to the default phrasing instead of degrading to nothing.

    Args:
        template: template value from the config (any type).
        default (str): known-good fallback template.

    Returns:
        str: a template guaranteed to survive .format(title=...).
    """
    template = safe_str(template)
    if not template:
        return default
    try:
        template.format(title="probe")
        return template
    except (KeyError, IndexError, ValueError):
        return default


def enrich_rows(rows, config, warnings=None):
    """
    [DOC-P56] Run all SEO enrichment over the rows.

    Runs LAST in the pipeline on purpose: seo_description consumes the
    extracted brand/scale, and the gallery alts consume the final
    gallery line-up, so everything upstream must already be settled.
    Rows without a title are left untouched and not counted as
    enriched — there is nothing meaningful to generate from an empty
    title, and inventing content would just create thin junk pages.

    Skippable via steps.seo_enrich (image_filename/image_alt are part
    of this step: they exist to serve the image SEO).

    Each row is fenced individually: a row that breaks enrichment is
    logged and skipped, not the whole run.

    Args:
        rows (list): rows after cleaning, extraction and image work.
        config (dict): loaded config.
        warnings (list or None): shared warning collector; skipped
            rows are appended as formatted lines when provided.

    Returns:
        tuple: (rows, number of rows enriched).
    """
    seo_config = config.get("seo", {})
    opening = _template_or_default(seo_config.get("opening_template"), _DEFAULT_OPENING)
    closing = _template_or_default(seo_config.get("closing_template"), _DEFAULT_CLOSING)
    max_gallery = safe_int(config.get("max_gallery_images", 6), 6)
    description_field = config.get("description_field", "features")
    enabled = config["steps"].get("seo_enrich", True)

    used_filenames = set()
    enriched_count = 0

    kept = []
    for row in rows:
        try:
            title = safe_str(row.get("post_title", ""))

            if not enabled or not title:
                for column in ("description", "seo_title", "seo_description",
                               "image_filename", "image_alt"):
                    row.setdefault(column, "")
                make_gallery_alts(row, title, 0)
                for index in range(1, max_gallery + 1):
                    row.setdefault("gallery_alt_%d" % index, "")
                kept.append(row)
                continue

            row["description"] = enrich_description(
                row.get(description_field, ""), title, opening, closing
            )
            row["seo_title"] = make_seo_title(title)
            row["seo_description"] = make_seo_description(
                title, row.get("brand", ""), row.get("scale", "")
            )
            row["image_filename"] = make_image_filename(title, used_filenames)
            row["image_alt"] = title
            make_gallery_alts(row, title, max_gallery)
            enriched_count += 1
            kept.append(row)
        except Exception as err:  # noqa: BLE001 — skip the row, keep the run alive.
            if warnings is not None:
                warnings.append(format_row_warning(row, "SEO enrichment", err))

    return kept, enriched_count
