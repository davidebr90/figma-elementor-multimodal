# Security and privacy

## Security boundaries

FEM treats the WordPress site as the pairing authority. The Figma plugin has no preconfigured endpoint or token: FEM Setup locally creates a manifest scoped to the selected origin.

- pairing code lifetime is 10 minutes, single-use, with at most 5 invalid attempts;
- Figma credential lifetime is 8 hours or 7 days, selected by the administrator;
- the server stores an HMAC-SHA-256 credential hash, not a plaintext bearer value;
- temporary imports expire after one hour;
- commits accept an idempotency key and assets must exactly match the manifest and document hash;
- technical audit data and expired state are automatically pruned.

## CORS and authentication

The Figma plugin canvas has an isolated origin, so FEM routes answer with a bearer-compatible CORS policy. FEM routes do not accept WordPress cookies as import authentication and do not enable CORS credentials. Access requires the temporary bearer header and the matching scope.

Do not put tokens in the website URL or expose a pairing code in screenshots, issues, or repositories.

## Checklist before a real environment

1. Use HTTPS with a valid certificate.
2. Restrict WordPress administrative access and update core, theme, plugins, and Elementor.
3. Keep PHP at or above the required version and disable public debug output.
4. Generate pairing from a trusted browser; choose seven days only on a trusted workstation.
5. Renew or recreate pairing for a lost device, an unauthorized person, or any suspected exposure.
6. Back up the database and media before the first import or update.
7. Test on staging first.

## Reporting

Use the repository’s private GitHub reporting path for a vulnerability when available. Do not send passwords, tokens, pairing codes, private keys, backups, or real user data.
