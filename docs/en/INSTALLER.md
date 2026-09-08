# FEM Setup: Windows, macOS, and Linux installer status

## Status on September 8, 2026 — FEM 2.0 beta

FEM Setup is a Tauri 2 desktop wizard that checks a WordPress website, creates a Figma manifest scoped to the selected domain, and retains one earlier local copy for rollback. The app source and tests are in this repository; signed end-user installers are not published yet.

| Platform | Code status | Current distribution | Required before stable release |
| --- | --- | --- | --- |
| Windows | Tauri build verified locally | Local unsigned x64 MSI and NSIS bundles generated | Authenticode signing, clean-install test, signed release |
| macOS | Tauri build configured | Source build or development CI artifact | Developer ID signing, Apple notarization, Intel/Apple Silicon testing |
| GNU/Linux | Tauri build configured | Source build or development CI artifact | Generated-package testing and real Figma-Linux-build compatibility |

The GitHub workflow builds development artifacts on all three platforms, but it does not sign, notarize, or publish a release. A development artifact must not be distributed as a trusted installer.

## Available features

- website URL syntax and FEM REST namespace check;
- HTTP allowed only for loopback in local mode;
- Figma manifest generated with only the configured website domain;
- local URL preferences without credentials, tokens, or codes;
- atomic site-specific package preparation;
- previous-copy retention and restore action;
- indicative detection of standard Figma and Figma-Linux locations.

## Not yet included

- wizard-managed signed downloads and updates;
- manual Figma-folder selector;
- end-to-end report proving execution inside Figma;
- executable signing/notarization;
- automated support for every Figma-Linux distribution format.

## Build from source

Prerequisites: Node.js 22+, stable Rust, and the Tauri system dependencies for the platform.

```powershell
cd apps/installer
npm ci
npm test
npm run build
npm run tauri build
```

Bundles are produced under `apps/installer/src-tauri/target/release/bundle/`. On Linux, also install the WebKitGTK and Tauri development packages; [installer.yml](../../.github/workflows/installer.yml) lists those used by the Ubuntu build.

## Use the wizard

1. Start FEM Setup.
2. Enter the website origin without an admin path, query, fragment, or credentials.
3. Enable **Local development environment** only for a loopback HTTP URL.
4. Select **Verify connection** and correct any REST error.
5. Close FEM in Figma and confirm it in the wizard.
6. Select **Prepare files**.
7. Import the manifest shown by the wizard in Figma.

The wizard prepares a local Figma plugin; it does not automatically install a plugin inside Figma and cannot by itself prove that Figma loaded the manifest. Verify that step in Figma.

For the complete flow, see [INSTALLATION.md](INSTALLATION.md).
