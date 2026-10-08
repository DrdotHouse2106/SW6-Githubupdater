# DhGithubUpdater – securely install Shopware 6 plugins from GitHub

🇩🇪 [Deutsche Version](README.md)

Install and update Shopware 6 plugins directly from public GitHub repositories – without a
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
bin/build-administration.sh
bin/console cache:clear
```

## Configuration

See *Settings → Extensions → GitHub plugin updater*: admins only (default on), allowed
repositories (`owner/repo` or `owner/*`), allow branches (default off), GitHub token
(optional, sent only to `api.github.com`), max download size, number of tags to check.

## Development

```bash
composer install
vendor/bin/phpunit
```

## License

MIT
