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
    // postUpdate fires this on every `composer update`, so most runs are no-ops
    // that rewrite the scaffolding byte-for-byte. Fingerprint the managed files
    // up front (update mode only) so the restart prompt at the end fires only
    // when something that actually lands in the containers changed.
    $before = $update ? static::fingerprint() : NULL;

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
      // Rename legacy Pantheon env vars before detection so sites configured
      // prior to the DDEV_ prefix switch are recognised and refreshed.
      $config = static::migratePantheonEnv($config);
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

    // Strip noise before writing: keys left at their ddev default and env vars
    // already supplied by a config.*.yaml override are redundant clutter.
    $config = static::pruneDefaultKeys($config);
    $config = static::dedupeWebEnvironment($config);

    static::writeConfig($event, $config);
    static::writeSettingsLocal($event);
    static::applyWpEngineFixups($event);
    static::appendGitignore($event);
    static::copyBrowsersync($event);

    if ($update && static::fingerprint() !== $before) {
      // Something changed. The add-on pull and container rebuilds happen on the
      // host at start, which this in-container script can't trigger, so prompt a
      // restart to re-run the post-start hooks (e.g. ddev add-on get). When the
      // refresh was a no-op (fingerprint unchanged) we stay silent.
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
   * Rename legacy Pantheon env vars to their DDEV_-prefixed equivalents.
   *
   * Sites configured before the prefix switch carry PANTHEON_SITE= and
   * WORKING_ENVIRONMENT= in web_environment. Update mode skips the prompts that
   * would rewrite these, so without this step isPantheonSite() never matches
   * and the pantheon-db add-on hook is never asserted. Renaming in place lets
   * the existing detection path light up on the next `-u` run.
   *
   * The very oldest sites instead carry a single `project=<site>.<env>` var
   * (e.g. `project=mysite.live`) that packs both values into one dot-separated
   * string — the original pantheon.yaml provider split it with `IFS='.'`. That
   * one var is expanded into the two DDEV_-prefixed vars here so those sites
   * migrate forward the same as the PANTHEON_SITE= generation.
   *
   * @param array $config
   *   The current site configuration.
   *
   * @return array
   *   The configuration with any legacy Pantheon env vars renamed.
   */
  protected static function migratePantheonEnv(array $config) {
    if (empty($config['web_environment'])) {
      return $config;
    }
    // Oldest format: a single `project=<site>.<env>` var. Split it into the two
    // DDEV_-prefixed vars in place before the prefix renames below. Only the
    // first such var is meaningful; a missing environment defaults to 'live',
    // matching configurePantheon()'s default.
    foreach ($config['web_environment'] as $i => $var) {
      if (strpos($var, 'project=') === 0) {
        $parts = explode('.', substr($var, strlen('project=')));
        array_splice($config['web_environment'], $i, 1, [
          'DDEV_PANTHEON_SITE=' . $parts[0],
          'DDEV_PANTHEON_ENVIRONMENT=' . ($parts[1] ?? 'live'),
        ]);
        break;
      }
    }
    // Legacy var name => current DDEV_-prefixed name.
    $renames = [
      'PANTHEON_SITE=' => 'DDEV_PANTHEON_SITE=',
      'WORKING_ENVIRONMENT=' => 'DDEV_PANTHEON_ENVIRONMENT=',
    ];
    foreach ($config['web_environment'] as &$var) {
      foreach ($renames as $legacy => $current) {
        // Match the legacy prefix only, leaving the value intact. The guard
        // skips vars already migrated (DDEV_PANTHEON_SITE= contains the legacy
        // PANTHEON_SITE= substring but not as a prefix).
        if (strpos($var, $legacy) === 0) {
          $var = $current . substr($var, strlen($legacy));
          break;
        }
      }
    }
    unset($var);
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
          // Redirect output: the add-on re-fetches on every start, so its
          // ddev-core "Use ddev restart to enable" notice and the add-on's own
          // install message would otherwise print on every `ddev start`. The
          // dedup/upgrade matching below keys on the command prefix, so the
          // trailing redirect does not affect detection.
          ['exec-host' => 'ddev add-on get augustash/ddev-pantheon-db --version develop >/dev/null 2>&1'],
          ['exec-host' => 'ddev db'],
        ],
      ],
    ];
    return static::mergePostStartHooks($config, $pantheonHooks);
  }

  /**
   * Detect whether a wp-config.php describes a WP Engine-hosted site.
   *
   * WP Engine seeds every install's wp-config.php with a block of platform
   * constants; unlike Pantheon (whose marker lives in ddev env vars) the WPE
   * signal lives in the config file itself. Any one of these constants is a
   * reliable tell — WPE_APIKEY and WPE_CLUSTER_ID are always present, PWP_NAME
   * carries the install name and is a useful secondary anchor.
   *
   * @param string $wpConfig
   *   Absolute path to the site's wp-config.php.
   *
   * @return bool
   *   TRUE when the file carries WP Engine platform constants.
   */
  protected static function isWpEngineSite($wpConfig) {
    if (!is_readable($wpConfig)) {
      return FALSE;
    }
    $contents = file_get_contents($wpConfig);
    return (bool) preg_match(
      "/define\\(\\s*'(WPE_APIKEY|WPE_CLUSTER_ID|PWP_NAME)'/",
      $contents
    );
  }

  /**
   * Gate a WP Engine wp-config.php so ddev's database settings win locally.
   *
   * WP Engine's wp-config.php hard-defines the production DB credentials with
   * bare define()s and ships no local-override hook, so under ddev the prod
   * creds win and the container can't reach its own database. This applies the
   * two edits — idempotently, keyed on sentinels — that let ddev take over
   * inside the container while leaving production untouched:
   *
   * 1. Wrap the contiguous run of DB_* defines in an `IS_DDEV_PROJECT !== 'true'`
   *    guard, so the prod credentials simply don't run under ddev. We wrap the
   *    whole run (keyed on the DB_ prefix, tolerating blank/comment lines) rather
   *    than a fixed set, because the optional DB_HOST_SLAVE/DB_CHARSET/DB_COLLATE
   *    vary between installs.
   * 2. Insert the wp-config-ddev.php include immediately before the
   *    `wp-settings.php` require — the one line every wp-config.php must have, so
   *    it's a stable anchor regardless of what platform mods sit above it. Its
   *    `!defined('DB_USER')` guard means it only loads ddev's creds when the wrap
   *    above suppressed the prod ones, i.e. under ddev.
   *
   * Both edits are inert in production: the wrap's guard is false off-ddev, and
   * the include's is_readable()/!defined() guards skip it when the prod creds
   * are present. Re-running is a no-op once the sentinels are in place, so a
   * re-seed from production self-heals on the next scaffolding pass.
   *
   * @param string $wpConfig
   *   Absolute path to the site's wp-config.php.
   *
   * @return bool
   *   TRUE when the file was modified, FALSE when already gated or unwritable.
   */
  protected static function applyWpEngineDbGate($wpConfig) {
    if (!is_readable($wpConfig) || !is_writable($wpConfig)) {
      return FALSE;
    }
    $contents = file_get_contents($wpConfig);
    $original = $contents;

    $contents = static::wrapDbDefines($contents);
    $contents = static::insertDdevInclude($contents);

    if ($contents === $original) {
      return FALSE;
    }
    (new Filesystem())->dumpFile($wpConfig, $contents);
    return TRUE;
  }

  /**
   * Wrap the contiguous run of DB_* defines in an IS_DDEV_PROJECT guard.
   *
   * Finds the first `define('DB_...')`, extends through the consecutive run of
   * DB_ defines (tolerating blank lines and comment lines between them), and
   * wraps that span so the production credentials are skipped under ddev.
   * Idempotent: if the DB_NAME define already sits inside an IS_DDEV_PROJECT
   * guard the contents are returned unchanged.
   *
   * @param string $contents
   *   The wp-config.php contents.
   *
   * @return string
   *   The contents with the DB block wrapped, or unchanged if already wrapped.
   */
  protected static function wrapDbDefines($contents) {
    $lines = explode("\n", $contents);

    // Locate the DB_* define run.
    $start = NULL;
    $end = NULL;
    foreach ($lines as $i => $line) {
      if (preg_match("/^\\s*define\\(\\s*'DB_[A-Z_]+'/", $line)) {
        if ($start === NULL) {
          $start = $i;
        }
        $end = $i;
        continue;
      }
      // Once the run has started, allow blank/comment lines to sit within it;
      // any other non-DB line ends the run.
      if ($start !== NULL && trim($line) !== '' && !preg_match('/^\\s*(#|\\/\\/)/', $line)) {
        break;
      }
    }
    if ($start === NULL) {
      return $contents;
    }

    // Already wrapped? The line immediately above the run opening the guard is
    // our sentinel — bail so re-runs are no-ops (and hand edits are respected).
    for ($j = $start - 1; $j >= 0; $j--) {
      if (trim($lines[$j]) === '') {
        continue;
      }
      if (strpos($lines[$j], 'IS_DDEV_PROJECT') !== FALSE) {
        return $contents;
      }
      break;
    }

    // Indent the wrapped run one level and fence it with the guard.
    $block = array_slice($lines, $start, $end - $start + 1);
    $indented = array_map(
      function ($line) {
        return $line === '' ? '' : '  ' . $line;
      },
      $block
    );
    $wrapped = array_merge(
      ["if ( getenv( 'IS_DDEV_PROJECT' ) !== 'true' ) {"],
      $indented,
      ['}']
    );
    array_splice($lines, $start, $end - $start + 1, $wrapped);

    return implode("\n", $lines);
  }

  /**
   * Insert the wp-config-ddev.php include before the wp-settings.php require.
   *
   * Idempotent: if the file already requires wp-config-ddev.php the contents are
   * returned unchanged. Anchors on the `wp-settings.php` require — the single
   * line every wp-config.php must contain — so the include lands late enough to
   * follow any platform setup but before WordPress boots.
   *
   * @param string $contents
   *   The wp-config.php contents.
   *
   * @return string
   *   The contents with the include inserted, or unchanged if already present
   *   or no wp-settings.php anchor was found.
   */
  protected static function insertDdevInclude($contents) {
    if (strpos($contents, 'wp-config-ddev.php') !== FALSE) {
      return $contents;
    }
    $block = "// Include for ddev-managed settings in wp-config-ddev.php.\n"
      . "\$ddev_settings = dirname(__FILE__) . '/wp-config-ddev.php';\n"
      . "if (is_readable(\$ddev_settings) && !defined('DB_USER')) {\n"
      . "  require_once(\$ddev_settings);\n"
      . "}\n\n";

    $count = 0;
    $result = preg_replace(
      "/^(\\s*require_once[ (].*wp-settings\\.php.*)$/m",
      $block . '$1',
      $contents,
      1,
      $count
    );
    return $count ? $result : $contents;
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
   * Apply the WP Engine-specific ddev fixups.
   *
   * WP Engine sites need two things a stock ddev WordPress setup doesn't
   * provide, both keyed on the same detection:
   *
   * 1. wp-config.php gated so ddev's DB credentials win locally — complements
   *    writeSettingsLocal() (which only seeds a config when none exists) by
   *    handling the case where one already exists, seeded from production, and
   *    hard-defines the prod DB credentials.
   * 2. .ddev/ un-ignored — WP Engine repos track wp-content only, ignoring the
   *    doc root wholesale with a `/*` deny-all. That swallows .ddev/ too, so
   *    without this a fresh clone gets no ddev config at all.
   *
   * Detection is self-guarding and each fixup is idempotent, so this runs
   * unconditionally on every pass — a non-WPE site is left untouched, and an
   * already-fixed repo is a no-op.
   */
  protected static function applyWpEngineFixups(Event $event) {
    // run() executes in the web container where cwd is the project root, but
    // resolve against the package location so the path holds regardless of cwd
    // (matching how fingerprint() reaches wp-config.php).
    $wpConfig = __DIR__ . '/../../../../' . static::$settingsLocalPath;
    if (!static::isWpEngineSite($wpConfig)) {
      return;
    }
    if (static::applyWpEngineDbGate($wpConfig)) {
      $event->getIO()->info('<info>WP Engine wp-config.php gated for ddev.</info>');
    }
    if (static::unignoreDdevDir()) {
      $event->getIO()->info('<info>WP Engine .gitignore updated to track .ddev/.</info>');
    }
  }

  /**
   * Un-ignore .ddev/ in a WP Engine project's `/*` deny-all .gitignore.
   *
   * WP Engine repos ignore the doc root wholesale (`/*`) and re-include only a
   * short allowlist (`!/wp-content/`, etc.), which leaves .ddev/ ignored. Add
   * .ddev/ to that allowlist so the ddev config commits and a fresh clone comes
   * up configured. DDEV's own generated .ddev/.gitignore still excludes the
   * machine-specific files within, so only the shareable config is tracked.
   *
   * Surgical and idempotent: only acts when a `/*` deny-all is present and
   * .ddev/ isn't already un-ignored, and inserts the rules right after the
   * existing allowlist so they read as part of it.
   *
   * @return bool
   *   TRUE when the .gitignore was modified, FALSE when already correct, not a
   *   `/*` deny-all repo, or unreadable.
   */
  protected static function unignoreDdevDir() {
    $fileSystem = new Filesystem();
    if (!$fileSystem->exists(static::$gitIgnorePath)) {
      return FALSE;
    }
    $gitignore = file_get_contents(static::$gitIgnorePath);

    // Only relevant to the WPE `/*` deny-all convention; a normal .gitignore
    // has no root wildcard to override, so there's nothing to un-ignore.
    if (!preg_match('/^\s*\/\*\s*$/m', $gitignore)) {
      return FALSE;
    }
    // Already un-ignored (sentinel: an un-ignore rule naming .ddev)?
    if (preg_match('/^\s*!\/?\.ddev\b/m', $gitignore)) {
      return FALSE;
    }

    $rules = "!/.ddev/\n!/.ddev/**\n";

    // Insert directly after the last existing `!` allowlist entry so the new
    // rules sit with their kin, contiguous — no blank line inserted between the
    // last allowlist entry and ours. Fall back to appending if none is found.
    if (preg_match_all('/^\s*!.*\n?/m', $gitignore, $m, PREG_OFFSET_CAPTURE)) {
      $last = end($m[0]);
      // Offset just past the matched line (including its trailing newline).
      $insertAt = $last[1] + strlen($last[0]);
      // Guard against a match that didn't capture the newline (EOF line).
      if (substr($gitignore, $insertAt - 1, 1) !== "\n") {
        $rules = "\n" . $rules;
      }
      $gitignore = substr($gitignore, 0, $insertAt) . $rules . substr($gitignore, $insertAt);
    }
    else {
      $gitignore = rtrim($gitignore, "\n") . "\n" . $rules;
    }

    $fileSystem->dumpFile(static::$gitIgnorePath, $gitignore);
    return TRUE;
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
   * Drop top-level keys left at their ddev default.
   *
   * Carrying a key whose value already equals ddev's default is pure noise —
   * removing it changes nothing ddev does but keeps config.yaml legible. Only
   * an exact (strict) match is pruned, so an intentionally non-default value
   * (e.g. `xdebug_enabled: true`) is preserved.
   *
   * The map is a deliberately small allowlist of stable toggles/ports.
   * Version-sensitive keys (`php_version`, `database`, `type`) are omitted on
   * purpose: ddev shifts their defaults between releases (e.g. php 8.3 → 8.4),
   * and this code runs in the web container where ddev can't be queried, so a
   * captured baseline would rot. Leaving them out means we never touch a pin.
   * Validated against ddev v1.25.
   *
   * @param array $config
   *   The current site configuration.
   *
   * @return array
   *   The configuration with default-valued noise keys removed.
   */
  protected static function pruneDefaultKeys(array $config) {
    $ddevDefaults = [
      'webserver_type' => 'nginx-fpm',
      'xdebug_enabled' => FALSE,
      'additional_hostnames' => [],
      'additional_fqdns' => [],
      'use_dns_when_possible' => TRUE,
      'composer_version' => '2',
      'corepack_enable' => FALSE,
      'xhgui_https_port' => '8142',
      'xhgui_http_port' => '8143',
    ];
    foreach ($ddevDefaults as $key => $default) {
      if (array_key_exists($key, $config) && $config[$key] === $default) {
        unset($config[$key]);
      }
    }
    return $config;
  }

  /**
   * Drop web_environment vars already supplied by a config.*.yaml override.
   *
   * ddev merges every `.ddev/config.*.yaml` into the effective config, so any
   * var the main config repeats from an override is redundant. Removing the
   * duplicate keeps the main config focused on what's genuinely site-specific
   * (e.g. the Pantheon vars). A no-op when no override files exist — which is
   * the usual case here, since WordPress ships no such override.
   *
   * @param array $config
   *   The current site configuration.
   *
   * @return array
   *   The configuration with override-provided env vars removed.
   */
  protected static function dedupeWebEnvironment(array $config) {
    if (empty($config['web_environment'])) {
      return $config;
    }
    // Collect every var defined by a merged override file.
    $overrideVars = [];
    $ddevDir = dirname(static::$configPath);
    foreach (glob($ddevDir . '/config.*.yaml') ?: [] as $file) {
      $override = Yaml::parseFile($file);
      foreach ($override['web_environment'] ?? [] as $var) {
        $overrideVars[$var] = TRUE;
      }
    }
    if (!$overrideVars) {
      return $config;
    }
    $config['web_environment'] = array_values(array_filter(
      $config['web_environment'],
      function ($var) use ($overrideVars) {
        return !isset($overrideVars[$var]);
      }
    ));
    // Drop the key entirely if nothing site-specific is left.
    if (!$config['web_environment']) {
      unset($config['web_environment']);
    }
    return $config;
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

  /**
   * Hash the contents of every file the scaffolding run manages.
   *
   * Taken before and after run() in update mode to tell a real refresh from a
   * no-op: postUpdate fires on every `composer update`, and prompting for a
   * restart when nothing changed is just misleading noise. Hashing file
   * *contents* (not mtimes) means the unconditional copies/dumps the run
   * performs don't register as changes when the bytes are identical.
   *
   * @return string
   *   A content hash of the managed file set, stable across no-op runs.
   */
  protected static function fingerprint() {
    // WordPress has no docroot; wp-config.php sits at the project root.
    $projectRoot = __DIR__ . '/../../../../';

    $paths = [
      static::$configPath,
      static::$gitIgnorePath,
      $projectRoot . static::$settingsLocalPath,
      static::$ddevRoot . 'docker-compose.browsersync.yaml',
      static::$ddevRoot . 'web-build/Dockerfile.ddev-terminus',
      static::$ddevRoot . 'commands/host/db',
    ];

    $parts = [];
    foreach ($paths as $path) {
      $parts[] = $path . ':' . static::hashPath($path);
    }
    return md5(implode('|', $parts));
  }

  /**
   * Content hash of a file, or of an entire directory tree, or '' if absent.
   *
   * Directory entries are sorted before hashing so the result is independent
   * of filesystem iteration order.
   *
   * @param string $path
   *   A file or directory path.
   *
   * @return string
   *   A stable hash of the path's contents, or '' when it does not exist.
   */
  protected static function hashPath($path) {
    if (is_file($path)) {
      return md5_file($path);
    }
    if (!is_dir($path)) {
      return '';
    }
    $hashes = [];
    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
      if ($file->isFile()) {
        $hashes[$file->getPathname()] = md5_file($file->getPathname());
      }
    }
    ksort($hashes);
    $parts = [];
    foreach ($hashes as $file => $hash) {
      $parts[] = $file . ':' . $hash;
    }
    return md5(implode('|', $parts));
  }

}
