# Setup

```
ddev composer config extra.drupal-scaffold.allowed-packages --json '["augustash/ddev-wordpress"]' && ddev composer config scripts.ddev-setup 'Augustash\Ddev::postPackageInstall' && ddev composer config scripts.post-update-cmd 'Augustash\Ddev::postUpdate' && ddev composer require augustash/ddev-wordpress && ddev composer ddev-setup
```

Follow the prompts to complete configuration.

> **Why scalar `composer config`, not `--json`?** The two script values contain
> backslashes (the `Augustash\Ddev` namespace separator). Passing them as inline
> JSON through `ddev composer config --json '[...]'` lets the double shell (host →
> container) eat the backslashes, so Composer stores the value as a quoted *string*
> — `"[\"Augustash\\Ddev::postUpdate\"]"` — instead of an array. The next
> `composer update` then fails with `Class "[\"Augustash\Ddev ... is not autoloadable`.
> The scalar form above sidesteps it: a single hook is a valid scalar script value.
> (The `allowed-packages` line keeps `--json` safely — it has no backslashes.)
>
> **Already have a `post-update-cmd`?** (e.g. a Pantheon
> `DrupalComposerManaged\ComposerScripts::postUpdate` hook) the scalar command
> *replaces* it. Skip that one segment and instead add `Augustash\Ddev::postUpdate`
> to the existing array by editing `composer.json` directly, so both hooks run:
> ```json
> "post-update-cmd": [
>     "DrupalComposerManaged\\ComposerScripts::postUpdate",
>     "Augustash\\Ddev::postUpdate"
> ]
> ```

# Updating

The generated scaffolding and hooks refresh **automatically** on every
`composer update`: the `post-update-cmd` hook (`Augustash\Ddev::postUpdate`)
re-runs setup in update mode without re-prompting. So pulling the latest
`ddev-wordpress` is normally all you need:
```bash
ddev composer update augustash/ddev-wordpress
```
Update mode keeps your existing `config.yaml` values (client code, PHP version,
subdomains) and only rebuilds what may have changed — BrowserSync, the Terminus
image, and the Pantheon add-on hook (upgraded in place to track `develop`). Run
`ddev restart` afterward to rebuild the containers and re-pull add-ons.

To force a refresh **without** updating the package, re-run setup manually in
update mode (`-u`):
```bash
ddev composer ddev-setup -- -u
```

Omit `-u` to be re-prompted for the configuration values (the original setup
flow).

# Configuration

On ddev-setup, you will be prompted for:
  - Client code
  - PHP version
  - Is this site hosted on Pantheon? — if yes:
    - Pantheon site name
    - Pantheon site environment
  - Subdomains (optional)

These are used to set config.yaml ddev configuration.

# Database

Database pull is handled by the [ddev-pantheon-db](https://github.com/augustash/ddev-pantheon-db) add-on, which is automatically installed on `ddev start`.

Will not download if there is more than one table in the existing local db.

To force a fresh pull:
  `ddev db -f`
