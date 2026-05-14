# Setup

```
ddev composer config extra.drupal-scaffold.allowed-packages --json '["augustash/ddev-wordpress"]' && ddev composer config scripts.ddev-setup 'Augustash\\Ddev::postPackageInstall' && ddev composer require augustash/ddev-wordpress && ddev composer ddev-setup
```

Follow the prompts to complete configuration.

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
