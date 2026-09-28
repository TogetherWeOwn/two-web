# App manifest baseline

`public/site.webmanifest` supplies a stable, same-origin application identity,
standalone launch at `/`, and 192px/512px PNG icons. The shared public layout
links the manifest, browser icon and 180px Apple touch icon. Browser chrome uses
the design system's dark canvas token; the launch background matches the ledger
homepage. The nginx exact-match location pins `application/manifest+json` rather
than relying on the image's MIME table. No Laravel route, database, session or
external asset request is needed to serve these files.

## Icon source and regeneration

There is no usable original logo image in this checkout (`favicon.ico` is empty).
These icons render the existing TWO name in the vendored Archivo font at weight
800, using canvas `#0b0714`, text `#f3eeff` and brand `#c80154` from
`resources/css/two.css`. The maskable icon has an opaque full-bleed background;
its lettering and underline stay inside the central 80%-diameter safe circle.
It is separate from the larger regular icon, so the ordinary launcher icon does
not inherit the maskable padding.

The PNGs are committed; Python packages are only needed to regenerate them.
Install `fonttools`, `brotli` and `Pillow` in a scratch directory outside the
checkout, then run:

```sh
PYTHONPATH="$ICON_TOOLS" python3 bin/generate-app-icons.py
php artisan test tests/Unit/WebManifestTest.php tests/Unit/DesignSystemTest.php
vendor/bin/pint --test tests/Unit/WebManifestTest.php
```

Review regenerated PNGs and their diff before committing. The generator never
modifies the vendored font or design-system stylesheet.

## Staging acceptance (after deployment)

Using authorized staging access, open the homepage and an events page in a real
browser. Confirm exactly one manifest link and theme-color tag in each head.
In Chromium DevTools Application → Manifest:

- Confirm the name, launch URL, scope, standalone display, and all three icons.
- Fetch `/site.webmanifest`: HTTP 200, JSON body, and
  `Content-Type: application/manifest+json` (an optional charset is fine).
- Fetch every manifest icon and `/icons/apple-touch-icon.png`: HTTP 200,
  `image/png`, and the dimensions recorded in the manifest/head.
- Inspect the maskable icon using the circular and rounded-square masks.
- Record the browser/version and any installability diagnostics. If the
  installed Lighthouse version exposes a PWA/installability audit, retain its
  result; absence of an audit is not a pass.

This slice does **not** add a service worker, offline response/cache, push
notifications, or a scripted install prompt. Any audit that requires these
must be recorded as an out-of-scope failure, not waived as a passing audit.
HTTPS/trusted-origin and successful manifest/icon delivery still need to pass.
Do not claim installation or staging acceptance solely from the unit tests:
those validate the assets and rendered head, not the deployed web server or
browser's installation policy.
