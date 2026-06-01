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
   * Entry point for the `ddev-setup` composer script.
   *
   * Honors an optional update flag (`-u`) to refresh in place without prompts;
   * see run() for the orchestration.
   *
   * @param \Composer\Script\Event $event
   *   The event.
   */
  public static function postPackageInstall(Event $event) {
    static::run($event, static::isUpdateMode($event));
  }

  /**
   * Run on post-update-cmd.
   *
   * Auto-fired on `composer update`. Always runs in update mode (no prompts).
   *
   * @param \Composer\Script\Event $event
   *   The event.
   */
  public static function postUpdate(Event $event) {
    static::run($event, TRUE);
  }

  /**
   * Determine whether ddev-setup was invoked in update mode.
   *
   * Update mode (`ddev composer ddev-setup -- -u`) refreshes the generated
   * scaffolding and hooks without re-prompting for, or rewriting, the
   * project's configuration values.
   *
   * @param \Composer\Script\Event $event
   *   The event.
   *
   * @return bool
   *   TRUE when an update flag (-u, --update, or update) was passed.
   */
  protected static function isUpdateMode(Event $event) {
    foreach ($event->getArguments() as $arg) {
      if (in_array($arg, ['-u', '--update', 'update'], TRUE)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Shared setup/refresh routine.
   *
   * Thin orchestrator: each step is its own method (see syncConfig/cleanup).
   * On a fresh run the inputs (client code, PHP version) are gathered via
   * prompts and threaded through the configure* steps; in update mode they are
   * inferred from the existing config so nothing is re-prompted. The $config
   * array is written once at the end.
   *
   * @param \Composer\Script\Event $event
   *   The event.
   * @param bool $update
   *   When TRUE, skip prompts and refresh in place from the existing config.
   */
  protected static function run(Event $event, $update) {
    static::syncConfig();
    static::cleanup();

    if (!(new Filesystem())->exists(static::$configPath)) {
      return;
    }

    $io = $event->getIO();
    $config = Yaml::parseFile(static::$configPath);

    if ($update) {
      // Update mode (`ddev composer ddev-setup -- -u`): keep all configured
      // values and skip the prompts. Infer prior choices from the existing
      // config so the generated scaffolding and hooks can be refreshed in
      // place — e.g. an existing Pantheon site has its add-on hook upgraded.
      if (static::isPantheonSite($config)) {
        $config = static::applyPantheonHooks($config);
        static::downgradeTerminus($event, $config['php_version'] ?? '8.1');
      }
      $io->info('<info>Update mode: configuration left untouched; rebuilding scaffolding.</info>');
    }
    else {
      $clientCode = $io->ask('<info>Client code?</info>:' . "\n > ");
      $phpVersion = static::selectPhpVersion($event);

      $config = static::configureSite($config, $clientCode, $phpVersion);
      $config = static::configurePantheon($event, $config, $clientCode, $phpVersion);
      $config = static::configureSubdomains($event, $config, $clientCode);
    }

    static::writeConfig($event, $config);
    static::writeSettingsLocal($event);
    static::appendGitignore($event);
    static::copyBrowsersync($event);

    if ($update) {
      // The add-on pull and container rebuilds happen on the host at start,
      // which this in-container script can't trigger. Prompt a restart so the
      // post-start hooks (e.g. ddev add-on get) re-run.
      $io->write('');
      $io->write('<info>Scaffolding refreshed.</info> Run <comment>ddev restart</comment> to rebuild the containers and re-pull add-ons (e.g. ddev-pantheon-db).');
      $io->write('');
    }
  }

  /**
   * Prompt for the PHP version.
   *
   * @return string
   *   The selected PHP version (e.g. "8.3").
   */
  protected static function selectPhpVersion(Event $event) {
    $phpVersions = [
      '7.4',
      '8.1',
      '8.2',
      '8.3',
      '8.4',
    ];
    $phpIndex = $event->getIO()->select('<info>PHP version</info> [<comment>8.3</comment>]:', $phpVersions, '8.3');
    return $phpVersions[$phpIndex];
  }

  /**
   * Apply the core site settings.
   *
   * @return array
   *   The updated configuration.
   */
  protected static function configureSite(array $config, $clientCode, $phpVersion) {
    $config['name'] = $clientCode;
    $config['docroot'] = '';
    $config['type'] = 'wordpress';
    $config['php_version'] = $phpVersion;
    return $config;
  }

  /**
   * Optionally wire up Pantheon hosting.
   *
   * Pantheon hosting is optional. Only wire up the Pantheon DB pull (env vars,
   * post-start add-on/db hooks, Terminus) when the site is actually hosted
   * there. Non-Pantheon sites (e.g. SiteGround) skip all of it.
   *
   * @return array
   *   The updated configuration.
   */
  protected static function configurePantheon(Event $event, array $config, $clientCode, $phpVersion) {
    $io = $event->getIO();

    if (!$io->askConfirmation('<info>Is this site hosted on Pantheon?</info> [<comment>Y/n</comment>] ', TRUE)) {
      // Non-Pantheon: drop any stale Terminus build artifact.
      (new Filesystem())->remove(static::$ddevRoot . 'web-build/Dockerfile.ddev-terminus');
      return $config;
    }

    $siteName = $io->ask('<info>Pantheon site name</info> [<comment>' . 'aai' . $clientCode . '</comment>]:' . "\n > ", 'aai' . $clientCode);
    $siteEnv = $io->ask('<info>Pantheon site environment (dev|test|live)</info> [<comment>live</comment>]:' . "\n > ", 'live');

    $config['web_environment'] = [
      'DDEV_PANTHEON_SITE=' . $siteName,
      'DDEV_PANTHEON_ENVIRONMENT=' . $siteEnv,
    ];

    $config = static::applyPantheonHooks($config);

    static::downgradeTerminus($event, $phpVersion);

    return $config;
  }

  /**
   * Detect whether the existing config describes a Pantheon-hosted site.
   *
   * Used by update mode, where the "Is this site hosted on Pantheon?" prompt is
   * skipped: the answer is inferred from the Pantheon env var or an existing
   * add-on hook so the hook can be refreshed without re-prompting.
   *
   * @param array $config
   *   The current site configuration.
   *
   * @return bool
   *   TRUE when the site is configured for Pantheon.
   */
  protected static function isPantheonSite(array $config) {
    foreach ($config['web_environment'] ?? [] as $var) {
      if (strpos($var, 'DDEV_PANTHEON_SITE=') === 0) {
        return TRUE;
      }
    }
    foreach ($config['hooks']['post-start'] ?? [] as $hook) {
      if (isset($hook['exec-host']) && strpos($hook['exec-host'], 'ddev add-on get augustash/ddev-pantheon-db') === 0) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Assert the Pantheon DB post-start hooks, upgrading any prior version.
   *
   * Pulls the Pantheon DB on start via the add-on (tracking the develop branch
   * so it self-updates). Any pre-existing pantheon-db add-on hook is stripped
   * first so the hook is upgraded in place rather than duplicated; other
   * site-specific hooks are preserved and de-duplicated.
   *
   * @param array $config
   *   The current site configuration.
   *
   * @return array
   *   The configuration with the Pantheon hooks asserted.
   */
  protected static function applyPantheonHooks(array $config) {
    if (!empty($config['hooks']['post-start'])) {
      $config['hooks']['post-start'] = array_values(array_filter(
        $config['hooks']['post-start'],
        function ($hook) {
          return !isset($hook['exec-host'])
            || strpos($hook['exec-host'], 'ddev add-on get augustash/ddev-pantheon-db') !== 0;
        }
      ));
    }

    $pantheonHooks = [
      'hooks' => [
        'post-start' => [
          ['exec-host' => 'ddev add-on get augustash/ddev-pantheon-db --version develop'],
          ['exec-host' => 'ddev db'],
        ],
      ],
    ];
    return static::mergePostStartHooks($config, $pantheonHooks);
  }

  /**
   * Prompt for and apply additional subdomain hostnames.
   *
   * @return array
   *   The updated configuration.
   */
  protected static function configureSubdomains(Event $event, array $config, $clientCode) {
    $subdomains = $event->getIO()->ask('<info>Subdomains? (space delimiter)</info> [<comment>no</comment>]:' . "\n > ", FALSE);
    if ($subdomains) {
      $config['additional_hostnames'] = [];
      foreach (explode(' ', $subdomains) as $subdomain) {
        $config['additional_hostnames'][] = $subdomain . '.' . $clientCode;
      }
    }
    return $config;
  }

  /**
   * Write the assembled configuration to config.yaml.
   */
  protected static function writeConfig(Event $event, array $config) {
    $io = $event->getIO();
    try {
      (new Filesystem())->dumpFile(static::$configPath, Yaml::dump($config, 2, 2));
      $io->info('<info>Config.yaml updated.</info>');
    }
    catch (\Error $e) {
      $io->error('<error>' . $e->getMessage() . '</error>');
    }
  }

  /**
   * Seed the local settings file when missing.
   */
  protected static function writeSettingsLocal(Event $event) {
    $fileSystem = new Filesystem();
    if ($fileSystem->exists(static::$settingsLocalPath)) {
      return;
    }
    try {
      $data = file_get_contents(__DIR__ . '/../assets/wp-config-local.php');
      $fileSystem->dumpFile(static::$settingsLocalPath, $data);
    }
    catch (\Error $e) {
      $event->getIO()->error('<error>' . $e->getMessage() . '</error>');
    }
  }

  /**
   * Append the ddev ignore rules to .gitignore once.
   */
  protected static function appendGitignore(Event $event) {
    $fileSystem = new Filesystem();
    try {
      $gitignore = $fileSystem->exists(static::$gitIgnorePath) ? file_get_contents(static::$gitIgnorePath) : '';
      if (strpos($gitignore, '# Ignore ddev files') === FALSE) {
        $gitignore .= "\n" . file_get_contents(__DIR__ . '/../assets/.gitignore.append');
        $fileSystem->dumpFile(static::$gitIgnorePath, $gitignore);
      }
    }
    catch (\Error $e) {
      $event->getIO()->error('<error>' . $e->getMessage() . '</error>');
    }
  }

  /**
   * Add the BrowserSync docker-compose service.
   */
  protected static function copyBrowsersync(Event $event) {
    try {
      (new Filesystem())->copy(__DIR__ . '/../assets/docker-compose.browsersync.yaml', static::$ddevRoot . 'docker-compose.browsersync.yaml');
      $event->getIO()->info('<info>docker-compose.browsersync.yaml added.</info>');
    }
    catch (\Error $e) {
      $event->getIO()->error('<error>'. $e->getMessage() .'</error>');
    }
  }

  /**
   * Sync missing config keys from asset config to site config.
   */
  protected static function syncConfig() {
    $fileSystem = new Filesystem();
    $assetConfigPath = __DIR__ . '/../assets/config.yaml';

    if (!$fileSystem->exists(static::$configPath) || !$fileSystem->exists($assetConfigPath)) {
      return;
    }

    $siteConfig = Yaml::parseFile(static::$configPath);
    $assetConfig = Yaml::parseFile($assetConfigPath);

    // Merge missing top-level keys.
    $missing = array_diff_key($assetConfig, $siteConfig);
    if (!empty($missing)) {
      $siteConfig = array_merge($siteConfig, $missing);
    }

    // Merge post-start exec-host hooks: asset hooks first, then any unique
    // local hooks appended. This ensures add-on installs run before commands
    // like ddev db, while preserving site-specific hooks. Only runs when the
    // asset actually defines hooks.
    if (!empty($assetConfig['hooks']['post-start'])) {
      $siteConfig = static::mergePostStartHooks($siteConfig, $assetConfig);
    }

    $fileSystem->dumpFile(static::$configPath, Yaml::dump($siteConfig, 2, 2));
  }

  /**
   * Merge post-start hooks from asset config into site config.
   *
   * Asset hooks are placed first, followed by any unique site-specific hooks.
   *
   * @param array $siteConfig
   *   The site configuration.
   * @param array $assetConfig
   *   The asset configuration.
   *
   * @return array
   *   The site configuration with merged hooks.
   */
  protected static function mergePostStartHooks(array $siteConfig, array $assetConfig) {
    $assetHooks = $assetConfig['hooks']['post-start'] ?? [];
    $siteHooks = $siteConfig['hooks']['post-start'] ?? [];

    // Collect asset exec-host values for deduplication.
    $assetValues = [];
    foreach ($assetHooks as $hook) {
      $assetValues[] = $hook['exec-host'];
    }

    // Start with asset hooks, then append unique site hooks.
    $merged = $assetHooks;
    foreach ($siteHooks as $hook) {
      if (isset($hook['exec-host']) && in_array($hook['exec-host'], $assetValues)) {
        continue;
      }
      $merged[] = $hook;
    }

    $siteConfig['hooks']['post-start'] = $merged;
    return $siteConfig;
  }

  /**
   * Remove legacy commands that have moved to plugins.
   */
  protected static function cleanup() {
    $fileSystem = new Filesystem();
    $dbCommand = static::$ddevRoot . 'commands/host/db';

    if ($fileSystem->exists($dbCommand)) {
      $contents = file_get_contents($dbCommand);
      if (strpos($contents, '#ddev-generated') === FALSE) {
        $fileSystem->remove($dbCommand);
      }
    }
  }

  /**
   * Ddev installs its own version of Terminus.
   * Terminus 4 requires PHP 8.2+.
   *
   * If the site's PHP version is < 8.2, install Terminus 3.
   */
  protected static function downgradeTerminus(Event $event, $phpVersion) {
    $fileSystem = new Filesystem();
    $io = $event->getIO();

    if (version_compare($phpVersion, '8.2', '<')) {
      try {
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
