# Setup

Set the following to root composer.json:

Root level:
```
"extra": {
    "drupal-scaffold": {
        "allowed-packages": [
            "augustash/ddev-wordpress"
        ]
    }
},
"scripts": {
    "ddev-setup": "Augustash\\Ddev::postPackageInstall"
}
```

Run:
```
composer require augustash/ddev-wordpress && composer ddev-setup
```

Composer install will trigger configuration script, follow prompts.

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
