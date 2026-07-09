"""
[DOC-P42] Image handling: gallery extraction, main-image fallback,
tracking-parameter stripping.

Merchant feeds bury extra product photos inside the HTML description
instead of giving them their own columns. This module digs those URLs
out of the PRESERVED raw HTML (saved as _raw_description before the
cleaner stripped the tags — see [DOC-P05]) and lines them up as
gallery_image_1..N columns that map directly onto the WordPress
importer's gallery field.
"""

import re
from html.parser import HTMLParser
from urllib.parse import parse_qsl, urlencode, urlparse, urlunparse

from modules.utils import format_row_warning, safe_int, safe_str

# Bare image URLs sometimes sit in description TEXT rather than in an
# <img> tag; this catches those too. Extension list mirrors what the
# WordPress importer accepts for sideloading.
_BARE_IMAGE_URL_RE = re.compile(
    r"https?://[^\s\"'<>]+\.(?:jpe?g|png|gif|webp|avif)(?:\?[^\s\"'<>]*)?",
    re.IGNORECASE,
)


class _ImageSrcParser(HTMLParser):
    """
    [DOC-P43] Collect src URLs from <img> tags in malformed HTML.

    Same reasoning as the cleaner's text extractor ([DOC-P27]): the
    stdlib parser shrugs off the broken markup merchants ship, where a
    regex-only approach would silently miss quoted-attribute edge
    cases. Order of appearance is preserved because merchants usually
    put the best photos first, and gallery_image_1 should be the best.
    """

    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.urls = []

    def handle_starttag(self, tag, attrs):
        """
        [DOC-P44] Record the src of every <img> tag.

        Only http(s) URLs are kept — feeds occasionally embed data:
        URIs or relative paths that WordPress could never sideload.
        """
        if tag != "img":
            return
        for name, value in attrs:
            if name == "src" and value and value.startswith(("http://", "https://")):
                self.urls.append(value)


def extract_image_urls(raw_html, max_images):
    """
    [DOC-P45] Pull up to max_images unique image URLs from raw HTML.

    Two passes: <img src> tags first (the reliable signal), then bare
    image URLs in the text (the fallback signal), deduplicated while
    preserving order. Capped at max_images because the output columns
    are fixed (gallery_image_1..6 by default) — a fixed column set is
    what lets the WordPress importer's saved mapping profile work
    unchanged on every cleaned file.

    Args:
        raw_html (str): the preserved raw description HTML.
        max_images (int): cap on returned URLs.

    Returns:
        list: unique image URLs, best-first, length <= max_images.
    """
    raw_html = safe_str(raw_html)
    if not raw_html:
        return []

    urls = []
    try:
        parser = _ImageSrcParser()
        parser.feed(raw_html)
        parser.close()
        urls.extend(parser.urls)
    except Exception:  # noqa: BLE001 — a parse failure just means fewer sources.
        pass

    urls.extend(match.group(0) for match in _BARE_IMAGE_URL_RE.finditer(raw_html))

    unique = list(dict.fromkeys(urls))
    return unique[:max_images]


def strip_tracking_params(url, tracking_params):
    """
    [DOC-P46] Remove known tracking parameters from an image URL.

    Removes only parameters on the configured blocklist (utm_*,
    clickref, affid, ...) instead of stripping the whole query string,
    because some CDNs use query parameters for real image variants
    (?width=800) — nuking those would break the image. Anything not on
    the blocklist is assumed functional and kept. The blocklist lives
    in the config so new networks' tracking junk can be added without
    a code change.

    Args:
        url (str): image URL.
        tracking_params (list): parameter names (lowercase) to remove;
            names ending in "*" match by prefix (e.g. "utm_*").

    Returns:
        str: the URL with tracking parameters removed.
    """
    url = safe_str(url)
    if not url or "?" not in url:
        return url

    parsed = urlparse(url)
    prefixes = tuple(param[:-1] for param in tracking_params if param.endswith("*"))
    exact = {param for param in tracking_params if not param.endswith("*")}

    kept = []
    for name, value in parse_qsl(parsed.query, keep_blank_values=True):
        lowered = name.lower()
        if lowered in exact or (prefixes and lowered.startswith(prefixes)):
            continue
        kept.append((name, value))

    return urlunparse(parsed._replace(query=urlencode(kept)))


def choose_main_image(row):
    """
    [DOC-P47] Pick the main image: aw_image_url, else merchant_image_url.

    AWIN's own CDN copy (aw_image_url, mapped to main_image) is
    preferred because it is served from AWIN's infrastructure and
    survives merchant site redesigns; the merchant's original URL is
    the fallback so a row missing the AWIN copy still gets an image
    instead of a blank product card. The detection report counts a
    row as "missing image" only when BOTH are empty, matching this
    exact logic.

    Args:
        row (dict): mapped row (already has main_image / merchant_image_url).

    Returns:
        str: the chosen image URL, or "".
    """
    return safe_str(row.get("main_image")).strip() or safe_str(row.get("merchant_image_url")).strip()


def process_images(rows, config, warnings=None):
    """
    [DOC-P48] Run all image work over the rows.

    Per row: resolve the main image (with fallback), extract the
    gallery from the raw description HTML, strip tracking parameters
    from everything, and write gallery_image_1..N. Empty gallery slots
    are written as "" (not omitted) so every row has an identical
    column set — see [DOC-P45] for why that consistency matters.

    Skippable via steps.extract_images; the main-image fallback still
    runs even then, because a blank main_image column is never the
    right outcome when a fallback URL exists.

    Each row is fenced individually: a row whose URLs or raw HTML
    break image processing is logged and skipped, not the whole run.

    Args:
        rows (list): cleaned row dicts (with _raw_description).
        config (dict): loaded config.
        warnings (list or None): shared warning collector; skipped
            rows are appended as formatted lines when provided.

    Returns:
        list: rows with main_image resolved and gallery columns added.
    """
    extract_on = config["steps"].get("extract_images", True)
    max_images = safe_int(config.get("max_gallery_images", 6), 6)
    tracking = [safe_str(param).lower() for param in config.get("tracking_params", []) or []]

    kept = []
    for row in rows:
        try:
            main = choose_main_image(row)
            row["main_image"] = strip_tracking_params(main, tracking) if main else ""

            gallery = []
            if extract_on:
                gallery = extract_image_urls(row.get("_raw_description", ""), max_images)
                gallery = [strip_tracking_params(url, tracking) for url in gallery]

            for index in range(1, max_images + 1):
                row["gallery_image_%d" % index] = gallery[index - 1] if index <= len(gallery) else ""
            kept.append(row)
        except Exception as err:  # noqa: BLE001 — skip the row, keep the run alive.
            if warnings is not None:
                warnings.append(format_row_warning(row, "image processing", err))

    return kept
