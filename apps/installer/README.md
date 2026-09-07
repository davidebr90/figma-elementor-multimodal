# FEM Setup

FEM Setup is the Tauri 2 desktop wizard for creating a site-scoped Figma development-plugin package.

It validates the WordPress URL, confirms the FEM REST namespace, writes a local package atomically, and retains one prior copy for rollback. It never stores a WordPress password, pairing code, or bearer credential.

## Build and test

```powershell
npm ci
npm test
npm run build
cargo test --locked --manifest-path src-tauri/Cargo.toml
npm run tauri build
```

See [installer status](../../docs/en/INSTALLER.md) and [Italian installer documentation](../../docs/INSTALLER.md) for platform limits, signing status, and use instructions.
