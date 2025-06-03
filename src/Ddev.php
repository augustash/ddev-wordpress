<?php

namespace Augustash;

use Composer\Script\Event;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

/**
 * Ddev console class.
 */
class Ddev {

  /**
   * Path to config file.
   *
   * @var string
   */
  private static $configPath = __DIR__ . '/../../../../.ddev/config.yaml';

  /**
   * Path to gitignore file.
   *
   * @var string
   */
  private static $gitIgnorePath = __DIR__ . '/../../../../.gitignore';

  /**
   * Path to gitignore file.
   *
   * @var string
   */
  private static $settingsLocalPath = 'wp-config.php';

    /**
   * The ddev root.
   *
   * @var string
   */
  private static $ddevRoot = __DIR__ . '/../../../../.ddev/';

  /**
   * Run on post-install-cmd.
   *
   * @param \Composer\Script\Event $event
   *   The event.
   */
  public static function postPackageInstall(Event $event) {

    $fileSystem = new Filesystem();
    if ($fileSystem->exists(static::$configPath)) {
      $io = $event->getIO();
      $config = Yaml::parseFile(static::$configPath);

      $clientCode = $io->ask('<info>Client code?</info>:' . "\n > ");
      $siteName = $io->ask('<info>Pantheon site name</info> [<comment>' . 'aai' . $clientCode . '</comment>]:' . "\n > ", 'aai' . $clientCode);
      $siteEnv = $io->ask('<info>Pantheon site environment (dev|test|live)</info> [<comment>live</comment>]:' . "\n > ", 'live');

      $phpVersions = [
        '7.4',
        '8.1',
        '8.2',
      ];
      $phpVersion = $io->select('<info>PHP version</info> [<comment>8.1</comment>]:', $phpVersions, '8.1');

      static::downgradeTerminus($event, $phpVersion);

      $config['name'] = $clientCode;
      $config['docroot'] = '';
      $config['type'] = 'wordpress';
      $config['php_version'] = $phpVersions[$phpVersion];
      $config['web_environment'] = [
        'project=' . $siteName . '.' . $siteEnv,
      ];

      // Subdomain configuration handling.
      $subdomains = $io->ask('<info>Subdomains? (space delimiter)</info> [<comment>no</comment>]:' . "\n > ", FALSE);
      if ($subdomains) {
        $subdomains = explode(' ', $subdomains);
        $config['additional_hostnames'] = [];

        foreach ($subdomains as $subdomain) {
          $config['additional_hostnames'][] = $subdomain. '.' . $clientCode;
        }
      }

      // config.yaml.
      try {
        $fileSystem->dumpFile(static::$configPath, Yaml::dump($config, 2, 2));
        $io->info('<info>Config.yaml updated.</info>');
      }
      catch (\Error $e) {
        $io->error('<error>' . $e->getMessage() . '</error>');
      }

      // settings.local.php.
      $settingsLocalPath = static::$settingsLocalPath;
      if (!$fileSystem->exists($settingsLocalPath)) {
        try {
          $data = file_get_contents(__DIR__ . '/../assets/wp-config-local.php');
          $fileSystem->dumpFile($settingsLocalPath, $data);
        }
        catch (\Error $e) {
          $io->error('<error>' . $e->getMessage() . '</error>');
        }
      }

      // .gitignore.
      try {
        $gitignore = $fileSystem->exists(static::$gitIgnorePath) ? file_get_contents(static::$gitIgnorePath) : '';
        if (strpos($gitignore, '# Ignore ddev files') === FALSE) {
          $gitignore .= "\n" . file_get_contents(__DIR__ . '/../assets/.gitignore.append');
          $fileSystem->dumpFile(static::$gitIgnorePath, $gitignore);
        }
      }
      catch (\Error $e) {
        $io->error('<error>' . $e->getMessage() . '</error>');
      }

      // docker-compose.browsersync.yaml.
      try {
        $fileSystem->copy(__DIR__ . '/../assets/docker-compose.browsersync.yaml', static::$ddevRoot . 'docker-compose.browsersync.yaml');
        $io->info('<info>docker-compose.browsersync.yaml added.</info>');
      }
      catch (\Error $e) {
        $io->error('<error>'. $e->getMessage() .'</error>');
      }
    }
  }

  /**
   * Ddev installs its own version of Terminus.
   * Terminus 4 requires php 8.2.
   * 
   * If sites php version is < 8.2, install Terminus 3.
   */
  protected static function downgradeTerminus(Event $event, $phpVersion) {
    $fileSystem = new Filesystem();
    $io = $event->getIO();

    // Phpversion is option selection number, not actual version.
    // All future options will be greater than 2.
    if ($phpVersion < 2) {
      try {
        $fileSystem = new Filesystem();
        $fileSystem->copy(__DIR__ . '/../assets/web-build/Dockerfile.ddev-terminus', static::$ddevRoot . 'web-build/Dockerfile.ddev-terminus');
      }
      catch (\Error $e) {
        $io->error('<error>' . $e->getMessage() . '</error>');
      }
    } else {
      $fileSystem->remove(static::$ddevRoot . 'web-build/Dockerfile.ddev-terminus');
    }
  }

}
