# DhGithubUpdater – securely install Shopware 6 plugins from GitHub

🇩🇪 [Deutsche Version](README.md)

Install and update Shopware 6 plugins directly from public or private GitHub repositories – without a
Shopware account or the plugin store. It offers the same features as
[yaetcom/ShopwareGitHubUpdater](https://github.com/yaetcom/ShopwareGitHubUpdater) but was
rewritten from scratch with a focus on security.

## Features

- Install from GitHub via *Extensions → My extensions → "Install from GitHub"*.
- Only versions whose `composer.json` (`require.shopware/core`) matches the running Shopware
  version are offered (real semver check via `composer/semver`).
- Link a repository on any plugin card, check for updates and update. Shopware's regular
  update lifecycle (incl. migrations) runs afterwards.
- Optional branch installs with commit tracking.
- Automatic rollback if Shopware cannot read the new version.

## Security

| Issue in the original | Fix |
|---|---|
| Shell injection via `exec()` with plugin name from untrusted `composer.json` (RCE) | No shell commands at all; Shopware services and Shopware's own ACL-protected lifecycle endpoints are used; strict name validation |
| Zip slip via unchecked `extractTo()` | Custom extractor rejecting `..`, absolute paths, backslashes, control chars, symlinks, multiple roots |
| Path traversal / arbitrary deletion via request parameter `name` | Target paths derived only from validated plugin name, checked with `realpath()` |
| No ACL checks on API routes | `system.plugin_maintain` + `system.plugin_upload`, real admin users only, admins only by default, honours `runtime_extension_management` |
| Loose URL parsing, refs injected into URLs | Strict `https://github.com/<owner>/<repo>` parser, refs taken only from the GitHub API, downloads pinned to commit SHA |
| Unlimited downloads, arbitrary redirects | Host allow-list (also for redirects), timeouts, size/file-count limits, streaming |
| Update source taken from the request, DAL entity writable via API | Repository stored server side; archive must contain the expected plugin (name + class) |
| Raw exception messages returned | Only curated messages; everything else is logged |
| Extraction directly into `custom/plugins` | Staging directory, atomic `rename()`, rollback, lock |

> A plugin is executable code with full access to your shop. This plugin makes sure the
> *updater itself* is not exploitable, but it cannot judge third-party code. Only install
> from sources you trust and use the repository allow-list.

## Installation

```bash
cd <shopware>/custom/plugins
git clone https://github.com/DrdotHouse2106/SW6-Githubupdater.git DhGithubUpdater
cd <shopware>
bin/console plugin:refresh
bin/console plugin:install --activate DhGithubUpdater
# Admin UI is shipped pre-built (src/Resources/public/administration).
# Alternatively upload the ZIP in Extensions → My extensions → Upload extension.
bin/console cache:clear
```

## Configuration

See *Settings → Extensions → GitHub plugin updater*: admins only (default on), allowed
repositories (`owner/repo` or `owner/*`), allow branches (default off), GitHub token
(required for private repositories, sent only to `api.github.com`), max download size, number of tags to check.

## Private repositories

Set a GitHub token in the plugin configuration. Create a fine-grained token
(*Settings → Developer settings → Fine-grained tokens*) with:

- **Repository access**: only the repositories you need
- **Permissions**: only **Contents: Read-only** (Metadata is added automatically)
- an **expiration date**

The token is sent exclusively to `api.github.com`; GitHub redirects the download to
`codeload.github.com` with a short-lived archive key. It is never logged, but it is stored in
`system_config`, so keep its permissions minimal.

To install this updater itself from a private repository, clone with a token and remove it
from the remote afterwards, or download the ZIP from GitHub and upload the folder as
`custom/plugins/DhGithubUpdater`.

## Development

```bash
composer install
vendor/bin/phpunit
```

## Troubleshooting

Known pitfalls from real installations. **Please add new ones** (or open an issue).

- **"Install from GitHub" button missing after ZIP upload:** install *and* activate the plugin, reload the admin (F5); if still missing run `bin/console assets:install` and `cache:clear`.
- **"No compatible versions":** the repository has no tags/releases. Create a release (tag `v1.0.0`) or enable branch installs.
- **Release not listed:** `require.shopware/core` of that tag does not match, or the tag is not a version number.
- **Private repository not found / rate limit:** configure a read-only GitHub token.
- **"Extension management disabled":** `shopware.deployment.runtime_extension_management` is false – install via Composer instead.
- **403:** only administrators may install by default.
- **Plugins with Composer dependencies** may fail on hosts without Composer – install the dependency manually in the shop root.

## License

MIT
