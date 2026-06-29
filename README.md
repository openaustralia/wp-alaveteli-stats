# wp-alaveteli-stats

A WordPress plugin that shows live statistics from an Alaveteli site (such as
[Right to Know](https://www.righttoknow.org.au)) on a WordPress page.

Every Alaveteli instance publishes a small JSON file of statistics at
`/version.json`. This plugin reads that file in the background every couple of
hours, caches the result, and lets you drop any single statistic into a page
or post with a shortcode. It works with any Alaveteli site, not just Right to
Know.

## Installation

1. Copy this directory into `wp-content/plugins/wp-alaveteli-stats`.
2. Activate **Alaveteli Stats** from the Plugins screen.
3. Go to **Settings, Alaveteli Stats** and enter the site URL, for example
   `https://www.righttoknow.org.au`.

On activation the plugin fetches the statistics once and schedules a refresh
every two hours.

## Usage

Add a statistic to any page or post with the `[alaveteli_stat]` shortcode,
naming a key shown on the settings page:

```
[alaveteli_stat key="visible_request_count"]
```

Attributes:

- `key` (required): the statistic to show, e.g. `visible_request_count`,
  `confirmed_user_count`, `visible_public_body_count`.
- `format` (optional, default `true`): set to `false` to show the raw number
  without thousands separators.
- `fallback` (optional): text shown when the statistic is unavailable, for
  example because the key does not exist or no data has been fetched yet.

The available keys vary between Alaveteli versions. The settings page lists the
keys currently provided by the configured site.

## How it works

- A WP-Cron event runs every two hours, fetching `/version.json` and caching
  the result.
- If a fetch fails, the last successfully fetched values keep showing, so the
  page never goes blank. Momentary failures are retried and clear themselves on
  the next run.
- If fetching keeps failing, an admin notice explains what went wrong (the URL
  requested, the error, and a suggested fix) so it can be corrected.

## Development

A local WordPress environment is provided via
[`@wordpress/env`](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/),
which needs Docker running. Install the tooling once:

```
npm install        # @wordpress/env
composer install   # PHPUnit and the WordPress test polyfills
```

Then start and stop the environment:

```
npm run wp-env start   # WordPress at http://localhost:8888
npm run wp-env stop
```

To force a refresh without waiting for the schedule:

```
npm run wp-env run cli wp cron event run alaveteli_stats_refresh
```

### Tests

PHPUnit tests for the store and render logic run against a real WordPress
instance inside the `@wordpress/env` test container:

```
npm run test:php
```


## Licence

MIT. See [LICENSE](LICENSE).
