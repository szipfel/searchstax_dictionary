# SearchStax Dictionary

Drupal 11 custom module, also compatible with Drupal 10.6. Generates a downloadable
dictionary for SearchStax **Dictionary-Driven Auto-Suggest**. No SearchStax or
Search API module dependency and no remote API calls.

## Verified upload contract

Verified against vendor documentation on 2026-10-07:

- [SearchStax's Drupal Auto-Suggest guide](https://support.searchstax.com/hc/en-us/articles/39446769309709-Switch-from-Search-API-Autocomplete-to-SearchStax-Auto-Suggest)
  specifies a plain `.txt` file with one title per line.
- [Acquia's SearchStax autocomplete guide](https://docs.acquia.com/acquia-cloud-platform/configuring-search-term-autocomplete-searchstax)
  confirms line-delimited `.txt` terms for Dictionary-Driven mode.
- [SearchStax service limits](https://support.searchstax.com/hc/en-us/articles/40049503450253-Service-Limits)
  specify a 10 MB Auto-Suggest upload limit.

The exporter writes UTF-8, LF line endings, a final newline, and no BOM, header,
weights, CSV quoting, or synonym rules. UTF-8/LF/no-BOM are this module's output
choices; the cited upload guide does not explicitly prescribe encoding or BOM.
It conservatively enforces 10,000,000 bytes because the vendor does not distinguish
decimal MB from MiB. Oversized exports fail visibly without truncation.
The [current Auto-Suggest guide](https://support.searchstax.com/hc/en-us/articles/40075200119437-Auto-Suggest)
also documents a total limit of **35,000 manually added/uploaded suggestions**.
Exports exceeding 35,000 unique normalized labels are rejected and cleaned up;
they are never silently truncated or split. Exactly 35,000 entries are allowed
when the file also satisfies the byte limit. Existing suggestions in SearchStax
count toward its total capacity; this offline exporter cannot inspect that list.

The guide describes duplicate suppression but does not explicitly guarantee
append-versus-replace semantics. Before relying on successive uploads, test two
small distinct files in a nonproduction SearchStax app and verify whether both
sets remain. Splitting cannot bypass the total suggestion limit. If uploads
append, removed Drupal titles need a separate removal/replacement workflow in
SearchStax. Actual upload behavior remains unverified.

## Install and use

1. Copy `searchstax_dictionary` to `web/modules/custom/` (or your site's custom
   module directory).
2. Enable it: `drush en searchstax_dictionary -y`.
3. Grant **Configure and export SearchStax dictionaries** only to trusted admins.
4. Open `/admin/config/search/searchstax-dictionary`.
5. Select content types and/or taxonomy vocabularies. **Save configuration** stores
   selections; **Generate Dictionary** saves them and runs a batch.
6. Use **Download dictionary (.txt)** when the batch completes. This separate link
   keeps the completed batch page usable and permits retrying interrupted downloads.
7. Upload the file under SearchStax Auto-Suggest → Dictionary-Driven, choose it,
   save the draft, and publish.

Run Drupal cron regularly to remove expired export data.

## Data and security behavior

- Selected published node titles and published taxonomy term names only.
- Entity queries use access checking; entity and label-field view checks apply
  to both the requesting administrator and an anonymous account. Publication alone
  is not treated as evidence that a title is public. Site-specific access modules
  should be tested with their real grants.
- Default translations only; no automatic mixing of alternate translations.
  Default entity languages can differ, so curate sources for the SearchStax app's
  language. Translation selection is outside this version's scope.
- Unicode whitespace and control characters become spaces; empty labels are
  dropped. Accents, punctuation, emoji, and case are preserved. Plain-text titles
  are not HTML-decoded or stripped.
- Exact normalized labels are deduplicated across nodes and terms. Case variants
  and canonically equivalent Unicode sequences remain distinct. Output order is
  deterministic by SHA-256 hash, not alphabetical.
- Form API protects generation with its normal POST/CSRF checks. Download requires
  authentication, the module permission, a random token, and matching owner UID.
  It returns an attachment with `no-store` and `nosniff` headers.
- Export rows are stored in the database, never in public files. In-progress jobs
  expire after 24 hours; completed downloads after one hour. Cron cleans up at most
  100 expired jobs per run; uninstall removes the module tables.
- The export is a snapshot of access and publication during generation, not a
  live search index. Regenerate after publication or access changes and review
  the dictionary before uploading it to a public suggestion service.

## Scalability

Batch API loads at most 100 entities per iteration, using ID keyset pagination
and a fixed upper ID per source type. Entity caches are reset after each chunk.
Deduplication uses an indexed database table, so neither the batch sandbox nor
PHP memory contains the entire dictionary. Downloads read at most 500 rows per
query. Only counters and a token are serialized in batch state.

The batch is not a transactionally consistent snapshot of an actively edited site.
New entities above the captured upper ID are deferred to the next export.
Large exports use temporary database space proportional to unique labels.

## Tests

`tests/src/Unit/TermNormalizerTest.php` covers UTF-8 normalization, punctuation,
emoji, empty labels, and rejection of invalid encoding. With Drupal core-dev:

```sh
vendor/bin/phpunit -c web/core/phpunit.xml.dist web/modules/custom/searchstax_dictionary/tests/src/Unit
```

See `VALIDATION.md` for checks actually run and remaining verification.

The local integration scripts require the module enabled and user 1. Run only
on a development site: `drush php:script
web/modules/custom/searchstax_dictionary/tests/manual/limits.php` checks both
limit boundaries with database fixtures that are rolled back. The separate
`integration.php` script also needs the `article`, `page`, and `tags` bundles.
