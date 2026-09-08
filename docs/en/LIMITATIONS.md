# Known limitations and mandatory checks

## Beta and flow direction

FEM 2.0 beta (latest published build: `v2.0.0-beta.1`) only handles Figma → WordPress. It stores mapping metadata to make future WordPress → Figma work possible, but does not expose reverse import yet.

## Visual fidelity and responsiveness

The import translates supported properties and layout; it does not promise pixel-perfect equivalence across every theme, browser, font, and breakpoint combination. Check before publishing:

- margins, padding, borders, shadows, and radii;
- fonts actually available on the website;
- desktop, tablet, and mobile;
- images and galleries on the front end;
- Elementor widgets and Gutenberg blocks after editor save.

Responsive frames must be explicitly declared and associated in Figma. When no tablet or mobile variant exists, FEM imports the base and reports the context: final adjustment remains a WordPress editor decision.

## Complex nodes and animation

FEM supports a controlled set of containers, text, headings, buttons, images, galleries/carousels, accordions, icons, dividers, and spacers. Unsupported items are reported; they are not converted into arbitrary HTML/JavaScript.

Figma animations, prototypes, third-party JavaScript libraries, iframes, and custom code are not automatically transferred. Integrate these functions with reviewed WordPress components and a separate security assessment.

## Installer

The wizard generates a local Figma package, but a stable release with Windows signing, macOS notarization, and broader Linux verification is not available yet. See [INSTALLER.md](INSTALLER.md).

## When an import fails

1. Do not retry with an expired code: create a new one.
2. Verify the URL, HTTPS, and REST namespace in the wizard.
3. Check Figma selection and warnings for unsupported nodes.
4. Inspect WordPress logs without copying credentials into them.
5. Retry on a test page after renewing pairing if needed.

An error should not leave a partial import: staging expires, assets are verified, and commit is idempotent. You still need to inspect the destination page when an explicit render has been requested.
