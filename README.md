# Setup

```
ddev composer config extra.drupal-scaffold.allowed-packages --json '["augustash/ddev-wordpress"]' && ddev composer config scripts.ddev-setup 'Augustash\Ddev::postPackageInstall' && ddev composer require augustash/ddev-wordpress && ddev composer ddev-setup
```

Follow the prompts to complete configuration. The `ddev-setup` run wires the
`post-install-cmd` / `post-update-cmd` auto-refresh hooks into `composer.json`
itself, so the installer doesn't — and neither do you.

# Updating

The generated scaffolding refreshes **automatically** — nobody has to remember a
setup command. The initial `ddev composer ddev-setup` wires two composer hooks
into `composer.json` (`post-install-cmd` → `Augustash\Ddev::postInstall`,
`post-update-cmd` → `postUpdate`), merged alongside any hooks already there. From
then on they re-run setup in update mode — no prompts — on every composer install
or update:
```bash
ddev composer update augustash/ddev-wordpress   # or just `ddev composer install` after a pull
```

> **Upgrading a project installed before 1.0.34?** The auto-refresh hooks have to
> be wired into your `composer.json` once — run `ddev composer ddev-setup update`
> (no prompts). After that you never run it again; the scaffolding is kept
> up-to-date automatically on every install/update.

Update mode keeps your existing `config.yaml` values (client code, PHP version,
subdomains, Pantheon env) and only rebuilds what may have changed — BrowserSync,
the Terminus image, and the Pantheon add-on hook (upgraded in place to track
`develop`).

The hooks run **only inside ddev** (guarded on `IS_DDEV_PROJECT`), so a Pantheon
build, CI, or host `composer install` never touches the `.ddev` scaffolding.

When a run changes a managed file it ends with *“Scaffolding refreshed — run
`ddev restart` to acquire the changes”*; a no-op ends with *“Everything
up-to-date.”* The script runs inside the web container and can't invoke the
host's `ddev`, so it tells you to restart rather than doing it for you (ddev also
warns natively when `config.yaml` changes).

### Changing configuration values

Since refreshes are automatic, you only run setup by hand when you actually need
to **change** a value (a typo'd client code, a PHP bump, …):
```bash
ddev composer ddev-setup
```
It walks the config prompts pre-filled with your current answers — press enter to
keep each, or type a new value for the one you're changing.

# Configuration

Running `ddev composer ddev-setup` (first-time setup, or to change a value) prompts
for:
  - Client code
  - PHP version
  - Is this site hosted on Pantheon? — if yes:
    - Pantheon site name
    - Pantheon site environment
  - Subdomains (optional)

These are used to set config.yaml ddev configuration. On a re-run each prompt is
pre-filled with the project's current value, so pressing enter through them keeps
the existing configuration unchanged.

# Database

Database pull is handled by the [ddev-pantheon-db](https://github.com/augustash/ddev-pantheon-db) add-on, which is automatically installed on `ddev start`.

Will not download if there is more than one table in the existing local db.

To force a fresh pull:
  `ddev db -f`
