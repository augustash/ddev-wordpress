# Setup

```
ddev composer config extra.drupal-scaffold.allowed-packages --json '["augustash/ddev-wordpress"]' && ddev composer config scripts.ddev-setup 'Augustash\Ddev::postPackageInstall' && ddev composer config --json --merge scripts.post-update-cmd '["Augustash\\Ddev::postUpdate"]' && ddev composer require augustash/ddev-wordpress && ddev composer ddev-setup
```

Follow the prompts to complete configuration.

# Updating

To pull the latest `ddev-wordpress` and refresh the generated scaffolding and
hooks **without re-answering the setup prompts**, re-run setup in update mode
(`-u`):
```bash
ddev composer require augustash/ddev-wordpress && ddev composer ddev-setup -- -u
```
Update mode keeps your existing `config.yaml` values (client code, PHP version,
subdomains) and only rebuilds what may have changed — BrowserSync, the Terminus
image, and the Pantheon add-on hook (upgraded in place to track `develop`). Run
`ddev restart` afterward to rebuild the containers and re-pull add-ons.

Omit `-u` to be re-prompted for the configuration values (the original setup
flow).

# Configuration

On ddev-setup, you will be prompted for:
  - Client code
  - Pantheon site name
  - Pantheon site environment
  - PHP version

These are used to set config.yaml ddev configuration.

# Database

Database pull is handled by the [ddev-pantheon-db](https://github.com/augustash/ddev-pantheon-db) add-on, which is automatically installed on `ddev start`.

Will not download if there is more than one table in the existing local db.

To force a fresh pull:
  `ddev db -f`
