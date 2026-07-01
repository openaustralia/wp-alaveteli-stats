# Changelog

All notable changes to the Alaveteli Stats plugin are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project aims to follow [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0] - 2026-06-29

Initial release. Shows live statistics from an Alaveteli site (such as Right to
Know) on a WordPress page, refreshed automatically in the background.

### Added

- `[alaveteli_stat]` shortcode to drop a single statistic into any page or post.
  Supports a required `key` attribute, an optional `format` attribute (thousands
  separators, on by default) and an optional `fallback` attribute shown when a
  statistic is unavailable.
- A WP-Cron event that fetches the site's `/version.json` every two hours and
  caches the result. The statistics are also fetched once on activation, and the
  schedule is cleared on deactivation.
- A settings screen (Settings, Alaveteli Stats) to configure the Alaveteli site
  URL. It lists the statistic keys the configured site currently provides.
- Resilient fallback behaviour: if a fetch fails, the last successfully fetched
  values keep showing so the page never goes blank, and transient failures clear
  themselves on the next run.
- An admin notice that explains persistent fetch failures (the URL requested, the
  error and a suggested fix) so they can be corrected.
- Works with any Alaveteli instance, not just Right to Know.
- PHPUnit tests covering the store and render logic, run against a real WordPress
  instance via `@wordpress/env`.
- `uninstall.php` to clean up the plugin's stored data on uninstall.
