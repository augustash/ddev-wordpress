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

Database will be downloaded automatically, this is handled in /.ddev/commands/host/db.
  Will not download if there are tables in the existing local db.

# TODO:

Nothing currently.
