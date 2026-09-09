# WordPress configuration

## Administration language

The **Tools → FEM Pairing** page uses the `figma-elementor-multimodal` text domain and follows the language selected for WordPress administration. English remains the source language; the Italian catalogue will be completed with the remaining administration surfaces during the 2.0 beta line.

## Requirements and compatibility

| Component | Requirement |
| --- | --- |
| WordPress | 7.0 or later |
| PHP | 8.3 or later |
| Elementor | 3.20 or later, only for Elementor output |
| Permission | `manage_fem_imports` (assigned to administrators on activation) |

The plugin registers the dynamic **FEM Scene** block even when Elementor is inactive. The Elementor target remains unavailable when Elementor does not meet the requirement.

## FEM Pairing page

The admin page is at **Tools → FEM Pairing**. It displays the site URL, Pairing ID, and code with copy buttons; it generates values only once so that a code cannot be accidentally recovered from a later screen.

The chosen lifetime governs the Figma bearer:

- **8 hours**: recommended for a normal design session;
- **7 days**: only for a workstation controlled by the administrator.

The initial code is valid for ten minutes, single-use, and locks after five invalid attempts. Always generate a new pairing for a new installation or after a suspected exposure.

## Stored data

Private tables use the WordPress prefix plus `fem_`:

- pairing and credential: HMAC hashes, scopes, expiry, and revocation state;
- audit: technical events retained for at most thirty days;
- design and snapshot: integrity-checked FEM contract;
- import: temporary staging state, deleted at expiry;
- idempotency: repeated-commit result, deleted at expiry.

The plugin does not put a token in the permalink, website URL, or repository. A credential is sent in the `Authorization` header by the Figma plugin only after pairing.

## Elementor target

FEM generates native Elementor elements for recognized widgets and adds stable FEM classes, such as `fem-design-*`, `fem-node-*`, and `fem-role-*`. These classes prevent collisions with theme classes and trace the source node; do not reuse them manually as generic site selectors.

Always open the page in Elementor after import. Complex Figma nodes can render as containers or need a manual correction, which is indicated by import warnings.

## Gutenberg target

The dynamic `fem/scene` block protects imported content: the editor does not receive arbitrary JavaScript from Figma. Native compatible blocks are used for media and groups where available. The block panel exposes the normal WordPress settings supported by the contract.

## Upgrade and rollback

1. Back up the database and `wp-content/uploads`.
2. Test the upgrade on staging.
3. Update the plugin and open an existing FEM page.
4. Renew pairing when the credential has expired or been revoked.
5. If needed, restore the preceding ZIP and the matching database backup.

This beta does not yet offer automatic rollback of generated pages. Do not use replacement on a production page until it has been validated on a test copy.
