# Comparenator
Users with **View environment comparisons** permission see a Comparenator
link at the top of every HTML page, including administrative pages. It opens
the current page's comparison in a new tab without requiring the Toolbar module
or JavaScript. Rebuild Drupal caches after deployment (`drush cr`).
The link uses the existing `prod` and `local` screenshot environments and the
configured `laptop` viewport (or the first configured viewport). Screenshot
identifiers replace path slashes with underscores, trim surrounding underscores,
and use `frontpage` for `/`. The installation base path and query string are
excluded. Screenshots must already exist; the link does not capture them.
