# sage-cachetags — Fastly query-param allowlist (vcl_recv)
# ─────────────────────────────────────────────────────────
# Drop-in replacement for the usual "Cleanup and normalize request URL" block.
#
# The plugin syncs the cache-significant query params (WooCommerce attribute
# filters, FacetWP facets, search, sort, pagination) to an Edge Dictionary named
# `cachetags_query_allowlist` (one item, key `params`, comma-separated). This keeps
# only those in the cache key and strips everything else — utm_*, gclid, fbclid,
# bot/session noise — collapsing the variants into one cached object.
#
# Setup
#   1. Create the Edge Dictionary `cachetags_query_allowlist` on the service.
#   2. Paste this into vcl_recv, replacing the tracker deny-list normalize block.
#   3. `wp cachetags fastly-allowlist preview`  # review the list
#      `wp cachetags fastly-allowlist sync`     # push it to the dictionary
#
# Safety notes
#   • The allowlist is authoritative ONLY for cacheable GETs with no functional
#     params. Add-to-cart / wc-ajax / admin requests keep their full URL so the
#     downstream `return(pass)` logic still detects them — and so analytics params
#     reach the origin on those requests.
#   • Until the dictionary is synced (empty value), it falls back to the tracker
#     deny-list below, so behaviour never regresses before the first sync.
#   • An allowlist that omits a meaningful param silently collapses real variants
#     into one cached page. Always `preview` before `sync`, and add anything the
#     plugin can't introspect via the `cachetags/fastly-allowed-query-params` filter.

declare local var.cachetags_allow STRING;
# Keyed by host: a multisite network on one Fastly service stores one allowlist
# per host (the plugin syncs per site), so they don't clobber each other.
set var.cachetags_allow = table.lookup(cachetags_query_allowlist, req.http.host, "");

if (var.cachetags_allow != ""
    && req.method == "GET"
    && req.url.path !~ "^/(wp|wp-admin|wp-json)/"
    && req.url !~ "(add-to-cart|remove_item|undo_item|wc-ajax|show-reset-form)=") {
  # Allowlist: keep only the cache-significant params, drop the rest.
  set req.url = querystring.filter_except(
    req.url,
    regsuball(var.cachetags_allow, ",", querystring.filtersep())
  );
} else {
  # Fallback (dictionary not synced yet) and non-cacheable requests: strip only
  # known trackers — never the functional params the pass logic below relies on.
  set req.url = querystring.filter(req.url,
      "utm_source"   + querystring.filtersep() +
      "utm_id"       + querystring.filtersep() +
      "utm_term"     + querystring.filtersep() +
      "utm_medium"   + querystring.filtersep() +
      "utm_campaign" + querystring.filtersep() +
      "utm_content"  + querystring.filtersep() +
      "campaign_id"  + querystring.filtersep() +
      "gad_source"   + querystring.filtersep() +
      "wbraid"       + querystring.filtersep() +
      "_gl"          + querystring.filtersep() +
      "dclid"        + querystring.filtersep() +
      "fbclid"       + querystring.filtersep() +
      "gclid");
}

# Drop empty params (?filter_color=) — classic empty-facet / bot noise — then
# sort so ?a=1&b=2 and ?b=2&a=1 share one cached object.
# (To also collapse ?paged=1 ≡ no param, add a regsub for paged/page/_paged=1.)
set req.url = querystring.clean(req.url);
set req.url = querystring.sort(req.url);
