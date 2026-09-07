# Figma configuration

## Requirements

FEM requires Figma Desktop, an account with an active Figma license, and development plugins. The import does not rely on private APIs; the wizard-generated manifest limits network requests to the configured WordPress website.

On Linux, install [Figma-Linux](https://github.com/Figma-Linux/figma-linux) first and verify that **Plugins → Development** in the installed build accepts a local manifest.

## Import the manifest

1. Prepare files with FEM Setup.
2. In Figma Desktop choose **Plugins → Development → Import plugin from manifest…**.
3. Select the wizard-generated `manifest.json`, not the source manifest in this repository.
4. Start FEM from **Plugins → Development**.

The wizard retains one previous site-specific copy. Before choosing **Restore previous version**, close FEM in Figma and confirm the closure in the wizard.

## Connect WordPress

1. Generate a code in WordPress under **Tools → FEM Pairing**.
2. Copy the site URL, Pairing ID, and code.
3. Paste the values into FEM and choose **Connect to WordPress**.
4. After a successful exchange the code is no longer needed: the plugin stores only the temporary credential in its own storage.

When the credential approaches expiry, the plugin tries to renew it. If it is already expired or revoked, create a new pairing in WordPress.

## Prepare an importable design

- Select a frame or component as the import root.
- Use clear names for sections, variants, and nodes; they become part of FEM traceability.
- Keep images and content within the selection. Missing or inconsistent assets are blocked before commit.
- For responsive work, associate explicit desktop/tablet/mobile frames when the project has them. Do not assume one Figma geometry represents every website breakpoint.
- Read the warning list for unsupported nodes before sending the design to WordPress.

## What is not executed

FEM does not transfer or execute arbitrary HTML or JavaScript from Figma. This prevents ID/class collisions, unreviewed scripts, and potentially dangerous content in the WordPress theme. Supported layout, text, media, and properties are transformed into native targets or FEM containers instead.
