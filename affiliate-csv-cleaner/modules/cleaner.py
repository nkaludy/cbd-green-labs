"""
[DOC-P26] Cleaning: dedupe, description repair, encoding, whitespace.

Every destructive-but-fixing operation lives here, and each one is
individually togglable via the config's "steps" section — a network
whose feed is already deduplicated upstream can switch that step off
without touching code, which is the whole point of the per-network
config system.

Order inside clean_rows() matters and is documented there.
"""

import html
import re
from html.parser import HTMLParser

from modules import detector
from modules.utils import format_row_warning, safe_str

# [DOC-P59] Recognising stripped-tag debris.
#
# AWIN's exporter deletes < > " = from description HTML before it ever
# reaches the CSV, so instead of "<div class=\"alignleft\">" the cell
# contains "div alignleft" glued straight into the product text, and
# "</div></li><li>" arrives as "/div/lili". There is no HTML left to
# parse — the repair is lexical, done in scrub_tag_debris() as a staged
# pipeline whose pieces are defined here. The runs sit exactly where
# the original <li>/<div> boundaries were, so replacing them with line
# breaks recovers the intended one-feature-per-line structure.
#
# The load-bearing trick shared by both run patterns is the trailing
# (?=[^a-z]|$) lookahead: a run must end where lowercase text does NOT
# continue, so any partial match that would bite into a real word
# ("/li" inside a URL path, "span" inside "spanish") fails there and
# backtracks out. Quantifiers are bounded (not *) to keep that
# backtracking cheap on Python 3.9, which has no possessive
# quantifiers.

# Tag-name vocabulary seen in this feed's debris (longest-first within
# each shared prefix, since glued tokens defeat word boundaries).
_DEBRIS_TAGS = (
    r"div|span|ul|ol|li|br|h[1-6]|tbody|thead|table|tr|td|th|strong|em"
    r"|font|center|iframe|img|video|section|p|b|i|u|a"
)

# Attribute debris. Every alternative here must be UNAMBIGUOUS junk —
# a pattern that can also match an English word is a data-eating bug,
# not a cleaner. Hence: "class..." and "id..." are pinned to the
# concrete values this feed ships (a generic class[a-z]* would eat the
# word "classic" out of "classic 1957 Chevy", and id[a-z]* would eat
# "identical"); style tokens require the CSS property GLUED to "style"
# ("stylecolor", "stylefont-family" — the de-quoting is exactly what
# makes them unambiguous); dimension attributes require their digits
# ("width320", never bare "width"); "alt" only counts when followed by
# more attribute junk. Style values allow multi-word, mixed-case runs
# ("Arial Helvetica sans-serif") plus run-on declarations
# ("; font-size large;"), all with bounded repetition.
_DEBRIS_ATTRS = (
    r"\s{0,3}(?:align(?:left|right|center|justify)"
    r"|style(?:color|font|width|height|margin|padding|text|background|size|display)[a-z-]{0,16}"
    r"\s*[#.]?[A-Za-z0-9%.-]{1,32}(?:\s+[A-Za-z0-9%.-]{1,32}){0,4}\s*(?:!important\s*)?;?"
    r"(?:\s*[a-zA-Z-]{2,24}(?:\s+#?[A-Za-z0-9%.-]{1,32}){1,5}\s*(?:!important\s*)?;){0,8}"
    r"|(?:border|cellspacing|cellpadding|width|height|colspan|rowspan)\d{1,4}"
    r"|class(?:video|align|product|item)[a-z0-9_-]{0,30}"
    r"|id(?:product|desc)[a-z0-9_-]{0,30}"
    r"|src\S{4,300}"
    r"|allowfullscreen"
    r"|alt(?=\s{0,2}(?:width|height|src|/))"
    r"|nbsp"
    r"|/(?![a-z0-9]))"
)

# Closing-anchored run: starts with the remains of a closing tag
# ("/div"); the slash anchor means it can never begin mid-word.
_DEBRIS_RUN_RE = re.compile(
    r"/(?:%(tags)s)(?:%(attrs)s|/?(?:%(tags)s)){0,80}(?=[^a-z]|$)"
    % {"tags": _DEBRIS_TAGS, "attrs": _DEBRIS_ATTRS}
)

# Opening-anchored run: no slash to anchor on ("div alignleftulli...",
# "centerspan stylecolor ffffff;...", "idproductdescription
# stylefont-family ..."). Two safety rails replace the missing slash:
# it must start at a boundary (including glued to the end of a word,
# for "ofspan stylecolor..." — original "of<span style=...>"), and it
# must contain at least one attribute-debris token. Bare tag-name
# words alone ("a", "li", "span") never qualify, which is what keeps
# ordinary prose safe even with the relaxed left boundary.
_OPEN_RUN_RE = re.compile(
    r"(?:^|(?<=[\s.;,:!?])|(?<=[a-z0-9]))(?:(?:%(tags)s)(?:/?(?:%(tags)s)){0,20})?"
    r"(?:%(attrs)s){1,10}"
    r"(?:%(attrs)s|/?(?:%(tags)s)){0,80}(?=[^a-z]|$)"
    % {"tags": _DEBRIS_TAGS, "attrs": _DEBRIS_ATTRS}
)

# List separators glued into lowercase text: "were able/lilito take"
# was "were able</li><li>to take". The run patterns' end-of-run
# lookahead rightly refuses to end a match against lowercase text,
# but a "</li><li>" seam is ALWAYS followed by the next item's text —
# lowercase or not — so this dedicated pattern replaces just the seam
# (two or more glued list tokens after a slash) with a line break and
# lets the text that follows stand.
_LIST_GLUE_RE = re.compile(r"/(?:li|ul|ol)(?:/?(?:li|ul|ol)){1,6}")

# Glued <span> leftovers that the run patterns can't safely claim:
# nested spans collapse to repeats ("Furiousspanspanspanspan") — no
# English word repeats "span" — and an opening span glued to digits
# ("span118 scale" was "<span>1/18 scale"). Both are deleted, not
# turned into line breaks, because they sat inside sentences.
_SPAN_REPEAT_RE = re.compile(r"(?:span){2,}")
_SPAN_DIGIT_RE = re.compile(r"(?:^|(?<=\s))span(?=\d)")

# Stripped <style> element: "style .video-container position
# relative;..." — a CSS block that runs to the end of the cell
# (merchants append video-embed styling after the product copy).
# Everything from the marker onward is removed; the ".selector"
# immediately after "style" is what distinguishes this from the word
# "style" in prose.
_STYLE_BLOCK_RE = re.compile(r"style\s*\.[a-z-].*$", re.DOTALL)

# Stripped HTML comment: "<!-- STSEALHTMLEND -->" arrives as
# "!-- STSEALHTMLEND --".
_COMMENT_RE = re.compile(r"!--.{0,80}?--")

# Inline closing tag(s) glued INTO a following word ("Case IH
# /spandie cast" was "Case IH </span>die cast"): replaced with a
# single space rather than a line break, because inline tags sat
# inside sentences. The (...)+ matters: nested spans arrive as
# "Movie/span/spandie", and matching one "/span" at a time would
# consume the second and splice the first back against "die" —
# re-creating the exact pattern just cleaned (a bug caught while
# testing against the full feed). Only span/font — a longer
# vocabulary here would eat real words like "/emergency" ("/em").
_INLINE_CLOSE_RE = re.compile(r"\s?(?:/(?:span|font))+(?=[a-z0-9])")

# Leading list markup glued to the first word of a line:
# "<ul><li>Brand new..." arrives as "ulliBrand new..." (sometimes
# "ullispanBrand", sometimes with a stray space: "ulli Brand").
# MULTILINE, because these show up both at the very start of the text
# and at the start of the lines the run-substitutions create — which
# is also why scrub_tag_debris() applies this AFTER the run passes.
# Only lowercase tag names and only when an uppercase letter or digit
# follows (past at most two spaces): conditions no English sentence
# opener meets.
_LEAD_OPEN_RE = re.compile(
    r"^(?:ul|ol|li|div|span|p|strong|em|tbody|tr|td){1,4}\s{0,2}(?=[A-Z0-9])",
    re.MULTILINE,
)

# A raw CSS block ("​.video-container { position: relative; ... }")
# appended after the product copy. This variant of the feed KEPT the
# braces/colons, so it can sit inside otherwise clean semicolon-format
# rows — which is why it is cut in clean_description() BEFORE format
# routing (the semicolon splitter would otherwise shred the CSS into
# fake feature lines). Requires a ".class"/"#id" selector before the
# brace so prose containing a stray "{" is never touched. Runs to end
# of text, which is where these blocks always sit.
_CSS_BLOCK_RE = re.compile(r"[.#][a-z][a-z0-9_-]{1,30}\s*\{.*$", re.DOTALL)

# TinyMCE media-embed placeholders ("mceItemMediaService_youtube:
# {"id":"...","width":440,...}") — editor-internal JSON that leaked
# into the feed. The blob is whitespace-free through its JSON body,
# so token + \S* takes out exactly the placeholder and nothing after
# the next space. Cut alongside the CSS blocks, before format routing,
# because JSON is invisible to all three format detectors.
_MCE_EMBED_RE = re.compile(r"mceItem\w+\S*")

# A whole line consisting of nothing but debris tokens (what's left
# when a run is split across the start/end of the text).
_DEBRIS_ONLY_LINE_RE = re.compile(
    r"^(?:[/\s]*(?:%(tags)s|%(attrs)s))+[/\s;]*$"
    % {"tags": _DEBRIS_TAGS, "attrs": _DEBRIS_ATTRS}
)

# Lines that are embed/layout junk rather than product copy: once the
# runs are broken into lines, any line still carrying these markers
# came from a stripped <table>/<iframe> block, not from a sentence a
# human wrote — drop the whole line instead of trying to salvage it.
_JUNK_LINE_RE = re.compile(
    r"video-container|iframe|allowfullscreen|cellspacing|cellpadding"
    r"|\bsrc\S|aspect ratio|overflow hidden|[{}]"
)

# Direct replacements for mojibake sequences that survive the
# round-trip repair in fix_encoding() (usually because the text was
# double-encoded or partially repaired upstream). Extend as new feeds
# reveal new artifacts.
_MOJIBAKE_MAP = {
    "â€™": "'",
    "â€˜": "'",
    "â€œ": '"',
    "â€\x9d": '"',
    "â€“": "–",
    "â€”": "—",
    "â€¦": "…",
    "Ã©": "é",
    "Ã¨": "è",
    "Ã¼": "ü",
    "Ã¶": "ö",
    "Ã¤": "ä",
    "Â®": "®",
    "Â©": "©",
    "Â ": " ",
}


class _TextExtractor(HTMLParser):
    """
    [DOC-P27] HTML-to-text conversion built on the stdlib HTMLParser.

    A real parser (not a regex) because merchant HTML is exactly the
    kind of malformed soup that breaks regex stripping: unclosed tags,
    attributes containing ">", nested lists. HTMLParser tolerates all
    of it.

    Block-level boundaries (<p>, <br>, <li>, headings) are converted
    to newlines so "<li>Opening doors</li><li>Rubber tires</li>"
    becomes two lines, not one glued-together word salad. One line per
    feature is the convention the theme's ACF "features" textarea and
    the Phase 5 importer already use, so cleaned HTML descriptions
    drop straight into that field.

    Content inside <style> and <script> elements is discarded, not
    extracted: to HTMLParser their contents are "data" like any other
    text, but a CSS rule or JS snippet is never product copy — without
    this, merchant video-embed styling ends up printed on the product
    page as if it were a feature list.
    """

    _BLOCK_TAGS = {"p", "br", "li", "div", "ul", "ol", "h1", "h2", "h3", "h4", "tr"}
    _SKIP_TAGS = {"style", "script"}

    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.parts = []
        self._skipping = 0

    def handle_starttag(self, tag, attrs):
        """[DOC-P28] Emit a line break for block-level opening tags."""
        if tag in self._SKIP_TAGS:
            self._skipping += 1
        if tag in self._BLOCK_TAGS:
            self.parts.append("\n")

    def handle_endtag(self, tag):
        """[DOC-P29] Emit a line break for block-level closing tags."""
        if tag in self._SKIP_TAGS and self._skipping > 0:
            self._skipping -= 1
        if tag in self._BLOCK_TAGS:
            self.parts.append("\n")

    def handle_data(self, data):
        """[DOC-P30] Collect text content (unless inside style/script)."""
        if not self._skipping:
            self.parts.append(data)

    def get_text(self):
        """
        [DOC-P31] Return the collected text, tidied.

        Collapses runs of whitespace inside lines and drops empty
        lines, so the output is clean one-item-per-line text however
        chaotic the source markup was.

        Returns:
            str: newline-separated text content.
        """
        text = "".join(self.parts)
        lines = [" ".join(line.split()) for line in text.split("\n")]
        return "\n".join(line for line in lines if line)


def strip_html(html_text):
    """
    [DOC-P32] Convert an HTML fragment to clean plain text.

    Thin wrapper so callers never touch the parser class directly.
    Feed errors (grossly invalid markup) fall back to returning the
    original text rather than crashing a 5,000-row run over one bad
    cell.

    Args:
        html_text (str): HTML fragment from a feed cell.

    Returns:
        str: plain text, one block-level item per line.
    """
    html_text = safe_str(html_text)
    try:
        parser = _TextExtractor()
        parser.feed(html_text)
        parser.close()
        return parser.get_text()
    except Exception:  # noqa: BLE001 — any parse failure means "keep original".
        return html_text


def fix_encoding(text):
    """
    [DOC-P33] Repair UTF-8 text that was mangled through latin-1.

    Two-stage repair:
      1. The round-trip: re-encode as cp1252 and decode as UTF-8,
         which mathematically reverses the classic mojibake mistake
         (UTF-8 bytes displayed as Windows-1252 characters). cp1252 —
         NOT latin-1 — is essential: the giveaway characters in real
         mojibake ("€" in "â€™", "™" in "â€¦") only exist in cp1252,
         so a latin-1 round-trip throws on exactly the text it is
         meant to fix (a bug caught by this tool's own test run).
         Only attempted when the text shows mojibake fingerprints —
         running it on healthy text containing a real "â" would
         corrupt it, which is why this is fingerprint-gated.
      2. The replacement table, for fragments the round-trip cannot
         fix (double-encoded or partially repaired text).

    Args:
        text (str): possibly mangled text.

    Returns:
        str: repaired text (unchanged if healthy).
    """
    text = safe_str(text)
    if not text or not detector.looks_mojibake(text):
        return text

    for codec in ("cp1252", "latin-1"):
        try:
            repaired = text.encode(codec).decode("utf-8")
            if not detector.looks_mojibake(repaired):
                return repaired
            text = repaired
            break
        except (UnicodeEncodeError, UnicodeDecodeError):
            continue

    for bad, good in _MOJIBAKE_MAP.items():
        text = text.replace(bad, good)
    return text


def scrub_tag_debris(text):
    """
    [DOC-P60] Clean a description whose tags were stripped upstream.

    A staged pipeline (all pieces defined at [DOC-P59]), ordered so
    each stage only ever sees what the previous ones left behind:

      1. stripped comments removed ("!-- STSEALHTMLEND --");
      2. stripped <style>-to-end CSS blocks removed (they always
         trail the product copy in this feed);
      3. inline closing tags glued into words deleted ("/spandie" ->
         "die"), BEFORE run replacement so they can't become bogus
         line breaks mid-sentence;
      4. closing-anchored and opening-anchored debris runs replaced
         with line breaks — the runs sit exactly where the original
         <li>/<div> boundaries were;
      5. line-level cleanup: leftover debris-only lines and
         table/iframe embed junk lines dropped entirely.

    Stage 5 is the safety net for whatever variant stages 1-4 didn't
    anticipate: a mangled line that still smells of markup is worth
    less than no line at all, because these bullets feed product pages.

    What this deliberately does NOT attempt: restoring characters the
    upstream exporter destroyed inside the text itself (e.g. "1/24"
    arriving as "124", or missing spaces where a <br> used to be).
    That information is simply gone from the feed; inventing it would
    be guessing.

    Args:
        text (str): description cell containing stripped-tag debris.

    Returns:
        str: cleaned, newline-separated feature text.
    """
    text = _COMMENT_RE.sub(" ", safe_str(text))
    text = _STYLE_BLOCK_RE.sub("\n", text)
    text = _INLINE_CLOSE_RE.sub(" ", text)
    text = _SPAN_REPEAT_RE.sub(" ", text)
    text = _SPAN_DIGIT_RE.sub("", text)
    text = _DEBRIS_RUN_RE.sub("\n", text)
    text = _OPEN_RUN_RE.sub("\n", text)
    text = _LIST_GLUE_RE.sub("\n", text)
    text = _LEAD_OPEN_RE.sub("", text)

    lines = []
    for line in text.split("\n"):
        line = line.strip().lstrip("; ").strip()
        if not line or _DEBRIS_ONLY_LINE_RE.match(line) or _JUNK_LINE_RE.search(line):
            continue
        lines.append(" ".join(line.split()))

    return "\n".join(lines)


def clean_description(text):
    """
    [DOC-P34] Normalize a description cell into bullet lines.

    HTML entities are decoded FIRST (&amp; -> &, &rsquo; -> ’,
    &nbsp; -> space), before any format detection, because entities
    end in semicolons — an undecoded "Bburago&rsquo;s" would make the
    semicolon-list check shred a prose sentence at the entity.

    The three real-world formats this feed mixes, handled explicitly:
      a) HTML soup            -> strip to text ([DOC-P32]); block tags
                                 already become one line per item.
      b) stripped-tag debris  -> scrub runs to line breaks ([DOC-P60]);
                                 the format AWIN actually ships, where
                                 the exporter already removed < > " =
                                 and left tag names glued in the text.
      c) semicolon list       -> split on ";" into one line per item.
    Anything else (plain prose) passes through untouched — inventing
    bullets out of a sentence would damage good data.

    The output convention (one feature per line) matches the ACF
    "features" textarea and the Phase 5 importer, so this column is
    import-ready without further transformation.

    Args:
        text (str): raw description cell.

    Returns:
        str: cleaned, newline-separated feature text.
    """
    text = html.unescape(safe_str(text)).replace("\xa0", " ")

    # Cut appended raw CSS blocks and editor-embed JSON before any
    # format routing — see _CSS_BLOCK_RE / _MCE_EMBED_RE for why these
    # can't wait for the debris scrubber.
    text = _CSS_BLOCK_RE.sub("", text)
    text = _MCE_EMBED_RE.sub(" ", text).strip()

    if detector.has_html(text):
        # Half-mangled cells exist in the wild: real tags mixed with
        # stripped-tag debris in the same value. Stripping the real
        # tags first and then scrubbing whatever debris remains
        # handles both without having to classify the mess up front.
        text = strip_html(text)
        if detector.has_tag_debris(text):
            text = scrub_tag_debris(text)
        return text

    if detector.has_tag_debris(text):
        return scrub_tag_debris(text)

    if detector.is_semicolon_list(text):
        segments = [segment.strip() for segment in text.split(";")]
        return "\n".join(segment for segment in segments if segment)

    return text.strip()


def _is_empty_row(row):
    """
    [DOC-P67] Is every real cell in this row blank?

    Private pipeline keys (_row_num, _raw_description) are excluded:
    a row consisting of nothing but plumbing is still an empty row.
    The isinstance guard matters because a ragged row's overflow key
    is None, not a string.

    Args:
        row (dict): mapped row dict.

    Returns:
        bool: True when the row has no real content.
    """
    return not any(
        safe_str(value).strip()
        for key, value in row.items()
        if not (isinstance(key, str) and key.startswith("_"))
    )


def clean_rows(rows, config, warnings=None):
    """
    [DOC-P35] Run all enabled cleaning steps over the mapped rows.

    Per-row step order (each togglable in config["steps"]):
      1. remove_empty_rows  — first, so later steps never waste work
                              on rows that are going away anyway.
      2. trim_whitespace    — before dedupe, so "SKU-1 " and "SKU-1"
                              are recognised as the same SKU.
      3. fix_encoding       — before description cleaning, so the
                              HTML parser sees repaired text.
      4. clean_descriptions — the multi-format repair ([DOC-P34]).
    Steps 1-4 run inside ONE per-row try/except (rather than the old
    one-pass-per-step layout) so a row that blows up mid-cleaning can
    be logged and skipped as a unit — never crashing the other 17,000
    rows in the file. The error counter feeds the final summary's
    "rows skipped" line.

      5. remove_duplicates  — a separate pass, LAST, on fully
                              normalised values, keeping the first
                              occurrence (feed order usually puts the
                              canonical product before its variants).

    Deliberate exceptions: "_raw_description" is trimmed but NEVER
    encoding-fixed or HTML-cleaned, because the image extractor needs
    the original markup intact ([DOC-P05]); "_row_num" is never
    touched at all — it must stay an integer for warnings.log.

    Args:
        rows (list): mapped row dicts.
        config (dict): loaded config.
        warnings (list or None): shared warning collector; skipped
            rows are appended as formatted lines when provided.

    Returns:
        tuple: (cleaned rows, {"duplicates_removed": n, "empty_removed": n}).
    """
    steps = config["steps"]
    description_field = config.get("description_field", "features")
    duplicate_field = config.get("duplicate_field", "sku")
    stats = {"duplicates_removed": 0, "empty_removed": 0}

    cleaned = []
    for row in rows:
        try:
            if steps.get("remove_empty_rows", True) and _is_empty_row(row):
                stats["empty_removed"] += 1
                continue

            if steps.get("trim_whitespace", True):
                for key in list(row.keys()):
                    if key == "_row_num":
                        continue
                    row[key] = safe_str(row[key]).strip()

            if steps.get("fix_encoding", True):
                for key in list(row.keys()):
                    if isinstance(key, str) and not key.startswith("_"):
                        row[key] = fix_encoding(row[key])

            if steps.get("clean_descriptions", True):
                row[description_field] = clean_description(row.get(description_field, ""))

            cleaned.append(row)
        except Exception as err:  # noqa: BLE001 — skip the row, keep the run alive.
            if warnings is not None:
                warnings.append(format_row_warning(row, "cleaning", err))
    rows = cleaned

    if steps.get("remove_duplicates", True):
        seen = set()
        kept = []
        for row in rows:
            key = safe_str(row.get(duplicate_field, "")).strip()
            if key and key in seen:
                stats["duplicates_removed"] += 1
                continue
            if key:
                seen.add(key)
            kept.append(row)
        rows = kept

    return rows, stats
