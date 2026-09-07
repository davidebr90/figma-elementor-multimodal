# Gutenberg saved-markup regression test

Requires Node.js, npm, PHP on PATH and Composer dependencies installed in
`packages/wordpress-plugin`.

From this directory run:

```sh
npm ci --ignore-scripts
npm test
```

The test uses pinned WordPress packages and their native block save functions.
It validates the PHP renderer output, repeated serialization, a text edit,
links, FEM identity metadata and preservation of responsive style attributes.
Dependencies are test-only and are not shipped with the WordPress plugin.

This is not a browser/editor visual test or proof of responsive rendering.
Previously imported invalid content is not automatically migrated: reimport it
after updating the plugin, preserving any WordPress-side edits beforehand.
