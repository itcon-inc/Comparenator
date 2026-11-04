# Comparenator

Comparenator compares anonymous, full-page PNG captures of the same path on a
reference (`prod`) and local (`local`) environment. Users with **View environment
comparisons** permission see a link at the top of every HTML page that opens the
comparison in a new tab, without requiring the Toolbar module or JavaScript.

## Deployment

Drupal 11/12 and PHP 8.3+ (including PHP 8.4) are supported. Enable the PHP GD
extension in the PHP worker for pixel diffs. The host/container also needs a
currently supported Node.js LTS version supported by Playwright.

From the deployed module directory:

```sh
npm ci
npm run browser:install
```

The browser install command installs Chromium and its Linux dependencies; the
Linux package installation may require administrator privileges. Install the
browser as the PHP worker user, or set `PLAYWRIGHT_BROWSERS_PATH` consistently
for installation and the PHP worker. Do not copy a browser installed only in a
developer's home directory and expect the web worker to find it. Deploy
`node_modules` alongside this module. Playwright is Apache-2.0 licensed and
requires no paid subscription; Chromium includes its own open-source licenses.

Run Drupal database updates (`drush updb`) and rebuild caches (`drush cr`).
Configure both base URLs (including any installation subdirectory), the Node.js
executable and viewport sizes at `/admin/config/development/comparenator`.
Both URLs must be reachable from the PHP worker's browser environment. Use a
multi-worker web server: the comparison request waits while Chromium requests
the local site. A single-worker PHP development server will deadlock/time out.
Allow enough PHP and proxy request time for two captures (up to 90 seconds each).

## Capture behavior

Opening a comparison attempts to create missing screenshots. Existing reference
captures are retained until **Refresh screenshots**, retention expiry, or a
source URL/viewport configuration change. The local page is loaded on each
comparison request. A fingerprint of its rendered HTML and loaded images,
stylesheets and fonts determines whether its PNG needs regenerating. Unchanged
content reuses the existing PNG without rewriting it. Per-request scripts, Drupal
form tokens and the administrative/Comparenator toolbar are excluded from the
fingerprint. Other dynamic content may legitimately trigger a new screenshot.

**Refresh screenshots** submits a CSRF-protected form and forces both sides of
that comparison to be recaptured. Manual refresh requires a signed-in user with
comparison permission so Drupal's session-bound Form API CSRF protection applies.
Mode and viewport links preserve the page path.
Capture failures are shown in the viewer and logged; an old capture is not
silently presented as a successful refresh. Captures for the same comparison
are locked to prevent overlapping writes. URLs are cache-busted by PNG content,
and pixel diffs are keyed by both source images so refreshes cannot show a stale
diff. Cron removes PNGs older than the configured retention period.

Captures are anonymous: Drupal login sessions are not forwarded to Chromium.
Protected pages may show a login page or fail, not the signed-in user's content.
Screenshots are stored under `public://comparenator` and must not contain
confidential information. Grant the comparison permission only to trusted roles:
opening a comparison starts a browser process and makes HTTP requests. Restrict
the browser's network access at the host/container level as appropriate.

The source path excludes query strings and the installation base path. Different
query variants of a page share a capture. New links use a SHA-256 path identifier
(`frontpage` for `/`), with the original path carried in the `path` query parameter.
Legacy comparison URLs without `path` can still display existing screenshots,
but cannot infer an unambiguous source path for capture or refresh. Automatic
capture covers environment comparisons, not historical node revisions.

## Development

```sh
composer install
npm ci
npm run browser:install
vendor/bin/phpunit
npm test
```

The browser test runs real Chromium against a temporary localhost fixture.
