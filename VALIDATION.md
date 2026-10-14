# Validation — 2026-10-07

Installed and enabled in the user-selected `import_demo` Lando site.
The actual site runs **Drupal 10.6.18**, with PHP 8.3 and MySQL 8.0;
its Lando recipe name is `drupal11`. Core was not upgraded.

## Passed

- PHP syntax checks on all runtime PHP, module and install files.
- Module installation, service container construction, schema creation, routes
  and dependency-injected configuration form.
- Integration exercise with 102 duplicate published article fixtures, an
  unpublished article, an unselected page, overlapping taxonomy labels, and
  published/unpublished terms. Fixture writes were rolled back afterward.
- Multiple batch iterations; selection and publication filtering; cross-source
  deduplication; Unicode whitespace, BOM and control normalization; literal
  punctuation preservation; invalid UTF-8 rejection.
- Streamed response body and attachment/no-store headers.
- Anonymous and other-user download denial, expiry denial and cron cleanup.
- Empty and oversized export rejection without partial downloads.
- Follow-up boundary checks: exactly 35,000 unique suggestions accepted;
  duplicate insertion does not consume capacity; 35,001 suggestions rejected
  with job/entry cleanup and download denial. Exactly 10,000,000 bytes accepted;
  larger output rejected independently of entry count. All test rows rolled back.
- Browser administration form, source selection, Generate Dictionary submission,
  completed batch redirect, download link and actual TXT download.

The browser run selected Article, Basic page and Tags and saved these selections.
The example export contains existing site content, not validation fixtures.

## Not yet verified

- Execution under Drupal 11 (the supplied site is 10.6.18).
- PHPUnit runner: core-dev/PHPUnit is not installed in the supplied project.
  The same normalization behavior was checked in the integration exercise.
- Actual upload into a SearchStax account. Format and maximum file size were
  verified against the vendor references linked in README.md.
- Append-versus-replace behavior for successive SearchStax uploads; split output
  remains disabled. Existing remote suggestions are not counted by this exporter.
- Site-specific access modules, multilingual configurations, and very large
  production datasets beyond the multi-chunk integration exercise.

The code supports both Drupal ^10.6 and ^11 using their shared APIs. Test in the
target Drupal 11 site and SearchStax app before production rollout.
