<?php

namespace Augustash;

use Composer\Json\JsonManipulator;
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
   * Path to the project composer.json.
   *
   * @var string
   */
  private static $composerPath = __DIR__ . '/../../../../composer.json';

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
   * Destination dir for the deploy-guard git hooks (project root .githooks).
   *
   * @var string
   */
  private static $hooksDestPath = __DIR__ . '/../../../../.githooks';

  /**
   * Entry point for the `ddev-setup` composer script.
   *
   * Runs the interactive config flow, each prompt seeded from any existing value
   * so pressing enter preserves it (which also makes a non-interactive run
   * non-destructive — it re-affirms the current config rather than clobbering
   * it). Routine scaffolding refreshes happen automatically via the
   * install/update hooks, so a manual run means "I want to (re)configure". The
   * unadvertised `update` argument forces a no-prompt refresh instead. See run()
   * for the orchestration.
   *
   * @param \Composer\Script\Event $event
   *   The event.
   */
  public static function postPackageInstall(Event $event) {
    // Wire the hooks first so the one-time bootstrap lands even if the config
    // prompts below are aborted — the wiring is independent of them.
    static::ensureComposerHooks($event);
    static::run($event, static::isUpdateMode($event));
  }

  /**
   * Auto-fired on `composer install` (post-install-cmd).
   *
   * Catches teammates who pull the project and `composer install` without
   * knowing setup exists — the scaffolding refreshes for them, no command to
   * remember. See autoRefresh() for the ddev guard.
   *
   * @param \Composer\Script\Event $event
   *   The event.
   */
  public static function postInstall(Event $event) {
    static::autoRefresh($event);
  }

  /**
   * Auto-fired on `composer update` (post-update-cmd).
   *
   * @param \Composer\Script\Event $event
   *   The event.
   */
  public static function postUpdate(Event $event) {
    static::autoRefresh($event);
  }

  /**
   * Shared auto-refresh for the install/update hooks — ddev context only.
   *
   * Runs in update mode (no prompts). Guarded to the ddev web container:
   * `composer install` also runs during Pantheon's build, CI, and host tooling,
   * where rewriting the .ddev scaffolding would be wrong or destructive — those
   * are a silent no-op.
   *
   * @param \Composer\Script\Event $event
   *   The event.
   */
  private static function autoRefresh(Event $event) {
    if (!static::isDdevContext()) {
      return;
    }
    static::run($event, TRUE);
  }

  /**
   * Whether we're running inside the ddev web container.
   *
   * DDEV sets IS_DDEV_PROJECT=true in the web container and nowhere else, so it
   * cleanly separates a ddev run from a Pantheon build / CI / host composer run.
   *
   * @return bool
   *   TRUE when running inside ddev.
   */
  protected static function isDdevContext() {
    return getenv('IS_DDEV_PROJECT') === 'true';
  }

  /**
   * Determine whether an explicit update flag was passed.
   *
   * @param \Composer\Script\Event $event
   *   The event.
   *
   * @return bool
   *   TRUE when -u, --update, or update is present.
   */
  protected static function isUpdateMode(Event $event) {
    return static::argsRequestUpdate($event->getArguments());
  }

  /**
   * Whether a raw argument list requests update mode.
   *
   * Split from isUpdateMode() so the flag parsing is unit-testable without a
   * Composer Event.
   *
   * @param array $args
   *   The script arguments.
   *
   * @return bool
   *   TRUE when -u, --update, or update is present.
   */
  protected static function argsRequestUpdate(array $args) {
    foreach ($args as $arg) {
      if (in_array($arg, ['-u', '--update', 'update'], TRUE)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Whether config.yaml already describes a configured project.
   *
   * "Configured" means a non-empty `name`: the asset config.yaml that a fresh
   * scaffold lands ships an empty name. Used to seed the Pantheon prompt default
   * (a brand-new site defaults to yes; an existing one matches its current
   * state).
   *
   * @return bool
   *   TRUE when config.yaml exists and carries a non-empty name.
   */
  protected static function isConfigured() {
    if (!(new Filesystem())->exists(static::$configPath)) {
      return FALSE;
    }
    $config = Yaml::parseFile(static::$configPath);
    return !empty($config['name']);
  }

  /**
   * Wire the install/update auto-refresh hooks into composer.json in place.
   *
   * Runs on the manual `ddev composer ddev-setup` path so the hooks are laid
   * down the first time setup is run; thereafter they fire on their own. The two
   * lifecycle events may already hold another handler, so ours is merged in, not
   * overwritten — `composer config` can't: `--merge --json` stringifies the
   * array and clobbers a scalar, and the `Augustash\Ddev` backslashes are eaten
   * through the host→container double shell. Doing it here keeps a backslash a
   * backslash, and JsonManipulator preserves the file's existing formatting.
   * `ddev-setup` itself isn't touched — it's a scalar nobody else defines, wired
   * by the installer directly.
   *
   * @param \Composer\Script\Event $event
   *   The event.
   */
  protected static function ensureComposerHooks(Event $event) {
    if (!file_exists(static::$composerPath)) {
      return;
    }
    $contents = file_get_contents(static::$composerPath);
    $config = json_decode($contents, TRUE);
    if (!is_array($config)) {
      return;
    }
    $scripts = $config['scripts'] ?? [];
    $handlers = [
      'post-install-cmd' => 'Augustash\\Ddev::postInstall',
      'post-update-cmd' => 'Augustash\\Ddev::postUpdate',
    ];

    $manipulator = new JsonManipulator($contents);
    $wired = [];
    foreach ($handlers as $name => $handler) {
      $current = $scripts[$name] ?? NULL;
      $merged = static::mergeHook($current, $handler);
      if ($merged !== $current) {
        $manipulator->addSubNode('scripts', $name, $merged);
        $wired[] = $name;
      }
    }

    if ($wired) {
      file_put_contents(static::$composerPath, $manipulator->getContents());
      $event->getIO()->info('<info>Wired composer auto-refresh hooks: ' . implode(', ', $wired) . '.</info>');
    }
  }

  /**
   * Merge our handler into an existing composer script value.
   *
   * Missing → create as a scalar; an existing scalar → [theirs, ours]; an
   * existing array → append ours. Already present → returned unchanged (strict
   * `===` compare against the input), so a re-run never duplicates and writes
   * nothing.
   *
   * @param string|array|null $current
   *   The current value of the script hook.
   * @param string $handler
   *   Our handler, e.g. 'Augustash\Ddev::postUpdate'.
   *
   * @return string|array
   *   The merged value.
   */
  protected static function mergeHook($current, $handler) {
    if ($current === NULL) {
      return $handler;
    }
    $list = is_array($current) ? $current : [$current];
    if (in_array($handler, $list, TRUE)) {
      return $current;
    }
    $list[] = $handler;
    return $list;
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
      // Update mode: keep all configured values and skip the prompts. Infer
      // prior choices from the existing config so the generated scaffolding and
      // hooks can be refreshed in place — e.g. an existing Pantheon site has its
      // add-on hook upgraded.
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
      // Seed each prompt's default from the existing config so re-running to
      // change one value never silently drops the rest — pressing enter keeps
      // the current value. On a first-time setup these fall back to empties/ddev
      // defaults.
      $nameDefault = $config['name'] ?? NULL;
      $clientCode = $io->ask('<info>Client code?</info>' . ($nameDefault ? '  [<comment>' . $nameDefault . '</comment>]' : '') . ':' . "\n > ", $nameDefault);
      $phpVersion = static::selectPhpVersion($event, $config['php_version'] ?? '8.3');

      $config = static::configureSite($config, $clientCode, $phpVersion);
      $config = static::configurePantheon($event, $config, $clientCode, $phpVersion);
      $config = static::configureSubdomains($event, $config, $clientCode);
    }

    // Strip noise before writing: keys left at their ddev default and env vars
    // already supplied by a config.*.yaml override are redundant clutter.
    $config = static::pruneDefaultKeys($config);
    $config = static::dedupeWebEnvironment($config);

    // WP Engine: point git at the deploy-guard hooks on every start (gated).
    // The hook files themselves are installed by applyWpEngineFixups() below;
    // this just asserts core.hooksPath, which is per-clone and must be re-set.
    if (static::isWpEngineSite(__DIR__ . '/../../../../' . static::$settingsLocalPath)) {
      $config = static::applyWpEngineConfigHooks($config);
    }

    static::writeConfig($event, $config);
    static::writeSettingsLocal($event);
    static::applyWpEngineFixups($event);
    static::appendGitignore($event);
    static::copyBrowsersync($event);

    // Close with an honest status. This runs in the web container and can't
    // trigger `ddev restart` itself (ddev is a host binary, and the in-container
    // ddev is a no-op stub), so when a managed file changed we tell the dev to
    // restart; when nothing did, we say so. A fresh setup always counts as
    // changed; update mode only when the fingerprint actually moved.
    $io->write('');
    if (!$update || static::fingerprint() !== $before) {
      $io->write('<info>Scaffolding refreshed — run</info> <comment>ddev restart</comment> <info>to acquire the changes (rebuild containers, re-pull add-ons).</info>');
    }
    else {
      $io->write('<info>Everything up-to-date.</info>');
    }
    $io->write('');
  }

  /**
   * Prompt for the PHP version.
   *
   * @return string
   *   The selected PHP version (e.g. "8.3").
   */
  protected static function selectPhpVersion(Event $event, $default = '8.3') {
    $phpVersions = [
      '7.4',
      '8.1',
      '8.2',
      '8.3',
      '8.4',
    ];
    if (!in_array($default, $phpVersions, TRUE)) {
      $default = '8.3';
    }
    $phpIndex = $event->getIO()->select('<info>PHP version</info> [<comment>' . $default . '</comment>]:', $phpVersions, $default);
    return $phpVersions[$phpIndex];
  }

  /**
   * Apply the core site settings.
   *
   * @return array
   *   The updated configuration.
   */
  protected static function configureSite(array $config, $clientCode, $phpVersion) {
    // Guard the name: an empty client code (e.g. a prompt answered with enter,
    // or a non-interactive run) must never overwrite an existing name. A
    // first-time setup legitimately starts empty and gets its name set here.
    if ($clientCode !== NULL && $clientCode !== '') {
      $config['name'] = $clientCode;
    }
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
    [$existingSite, $existingEnv] = static::pantheonEnvValues($config);

    // Default the confirmation from the site's current state: an already-Pantheon
    // site defaults to yes, a configured non-Pantheon one to no. A brand-new
    // setup has neither, so default to yes (the common case for these sites).
    $default = static::isConfigured() ? static::isPantheonSite($config) : TRUE;
    $hint = $default ? '[<comment>Y/n</comment>]' : '[<comment>y/N</comment>]';
    if (!$io->askConfirmation('<info>Is this site hosted on Pantheon?</info> ' . $hint . ' ', $default)) {
      // Non-Pantheon: drop any stale Terminus build artifact.
      (new Filesystem())->remove(static::$ddevRoot . 'web-build/Dockerfile.ddev-terminus');
      return $config;
    }

    // Seed from the existing env vars on a re-run; otherwise derive the usual
    // 'aai'<client-code> guess for a first-time setup.
    $siteDefault = $existingSite ?: 'aai' . $clientCode;
    $envDefault = $existingEnv ?: 'live';
    $siteName = $io->ask('<info>Pantheon site name</info> [<comment>' . $siteDefault . '</comment>]:' . "\n > ", $siteDefault);
    $siteEnv = $io->ask('<info>Pantheon site environment (dev|test|live)</info> [<comment>' . $envDefault . '</comment>]:' . "\n > ", $envDefault);

    $config['web_environment'] = [
      'DDEV_PANTHEON_SITE=' . $siteName,
      'DDEV_PANTHEON_ENVIRONMENT=' . $siteEnv,
    ];

    $config = static::applyPantheonHooks($config);

    static::downgradeTerminus($event, $phpVersion);

    return $config;
  }

  /**
   * Pull the current Pantheon site/environment out of web_environment.
   *
   * Seeds the Pantheon prompts so a re-run defaults to the existing values
   * rather than re-deriving a guess from the client code.
   *
   * @param array $config
   *   The current site configuration.
   *
   * @return array
   *   A [site, environment] pair; either element is NULL when not present.
   */
  protected static function pantheonEnvValues(array $config) {
    $site = NULL;
    $env = NULL;
    foreach ($config['web_environment'] ?? [] as $var) {
      if (strpos($var, 'DDEV_PANTHEON_SITE=') === 0) {
        $site = substr($var, strlen('DDEV_PANTHEON_SITE='));
      }
      elseif (strpos($var, 'DDEV_PANTHEON_ENVIRONMENT=') === 0) {
        $env = substr($var, strlen('DDEV_PANTHEON_ENVIRONMENT='));
      }
    }
    return [$site, $env];
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
    if (static::installWpEngineHooks()) {
      $event->getIO()->info('<info>WP Engine deploy-guard hooks installed in .githooks/.</info>');
    }
    if (static::configureWpEngineGitignore()) {
      $event->getIO()->info('<info>WP Engine .gitignore updated for .githooks/, patches/, and blocked file types.</info>');
    }
  }

  /**
   * Install the WP Engine deploy-guard git hooks into the project's .githooks/.
   *
   * Ships the pre-push guard (WPE-rejected file types + plugin/theme version
   * drift) plus its helpers. The engine files carry a `ddev-wordpress-generated`
   * marker and are overwritten on every pass — unless a dev removed the marker
   * to take ownership, in which case that file is left alone. The wpe-targets
   * map is project data: seeded once, never overwritten.
   *
   * core.hooksPath is asserted separately, via the post-start hook added in
   * applyWpEngineConfigHooks(), so the guard activates for every clone.
   *
   * @return bool
   *   TRUE when any hook file was written or seeded, FALSE when all were current.
   */
  protected static function installWpEngineHooks() {
    $fileSystem = new Filesystem();
    $src = __DIR__ . '/../assets/githooks/';
    $dst = static::$hooksDestPath . '/';
    $marker = 'ddev-wordpress-generated';

    // name => is it an executable script.
    $engine = [
      'pre-push' => TRUE,
      'wpe-file-check' => TRUE,
      'wpe-reconcile' => TRUE,
      'repo-versions' => TRUE,
      'drift.php' => FALSE,
    ];

    $changed = FALSE;
    foreach ($engine as $name => $executable) {
      $target = $dst . $name;
      // A dev who removed the marker owns the file now — don't clobber it.
      if (is_file($target) && strpos(file_get_contents($target), $marker) === FALSE) {
        continue;
      }
      $before = is_file($target) ? file_get_contents($target) : NULL;
      $fileSystem->copy($src . $name, $target, TRUE);
      if ($executable) {
        $fileSystem->chmod($target, 0755);
      }
      if ($before !== file_get_contents($target)) {
        $changed = TRUE;
      }
    }

    // Project-owned target map: seed the commented template once, never rewrite.
    if (!$fileSystem->exists($dst . 'wpe-targets')) {
      $fileSystem->copy($src . 'wpe-targets', $dst . 'wpe-targets');
      $changed = TRUE;
    }

    return $changed;
  }

  /**
   * Add the post-start hook that points git at .githooks on every start.
   *
   * core.hooksPath is per-clone local git config (not committed), so relying on
   * a one-time setup means a fresh clone runs without the deploy guard. Asserting
   * it as a post-start exec-host hook re-applies it on every `ddev start`, for
   * every teammate. Deduped by mergePostStartHooks(), so re-runs don't stack.
   *
   * @param array $config
   *   The current site configuration.
   *
   * @return array
   *   The configuration with the hooks-path post-start hook asserted.
   */
  protected static function applyWpEngineConfigHooks(array $config) {
    $hooks = [
      'hooks' => [
        'post-start' => [
          ['exec-host' => 'git config core.hooksPath .githooks'],
        ],
      ],
    ];
    return static::mergePostStartHooks($config, $hooks);
  }

  /**
   * Ensure the WP Engine `/*` deny-all .gitignore carries the hook + patch dirs
   * and ignores the file types WPE's git push rejects.
   *
   * Two idempotent edits, only on a `/*` deny-all repo:
   * 1. Un-ignore !/.githooks/ and !/patches/ in the allowlist (the guard and any
   *    tracked plugin patches must be committed and deploy with the site).
   * 2. Append the blocked file types (executables/video) WPE refuses on push, so
   *    a bundled plugin binary is ignored rather than bouncing a deploy.
   *
   * @return bool
   *   TRUE when the .gitignore was modified, FALSE when already correct or not a
   *   `/*` deny-all repo.
   */
  protected static function configureWpEngineGitignore() {
    $fileSystem = new Filesystem();
    if (!$fileSystem->exists(static::$gitIgnorePath)) {
      return FALSE;
    }
    $gitignore = file_get_contents(static::$gitIgnorePath);
    $original = $gitignore;

    // Only relevant to the WPE `/*` deny-all convention.
    if (!preg_match('/^\s*\/\*\s*$/m', $gitignore)) {
      return FALSE;
    }

    // 1. Un-ignore the hook + patch dirs (each only if not already present).
    $unignore = [];
    if (!preg_match('/^\s*!\/?\.githooks\b/m', $gitignore)) {
      $unignore[] = '!/.githooks/';
    }
    if (!preg_match('/^\s*!\/?patches\b/m', $gitignore)) {
      $unignore[] = '!/patches/';
    }
    if ($unignore) {
      $gitignore = static::insertAfterAllowlist($gitignore, implode("\n", $unignore) . "\n");
    }

    // 2. Ignore the file types WPE rejects on push (sentinel-guarded append).
    if (strpos($gitignore, '# WP Engine rejects these file types') === FALSE) {
      $gitignore = rtrim($gitignore, "\n") . "\n\n"
        . "# WP Engine rejects these file types (executables/video) on git push.\n"
        . "*.exe\n*.dll\n*.mp4\n*.mov\n*.avi\n";
    }

    if ($gitignore === $original) {
      return FALSE;
    }
    $fileSystem->dumpFile(static::$gitIgnorePath, $gitignore);
    return TRUE;
  }

  /**
   * Insert allowlist rules right after the last `!` entry in a deny-all .gitignore.
   *
   * Keeps new un-ignore rules contiguous with the existing allowlist rather than
   * trailing at EOF. Falls back to appending when no `!` entry exists.
   *
   * @param string $gitignore
   *   The .gitignore contents.
   * @param string $rules
   *   The rule text to insert (newline-terminated).
   *
   * @return string
   *   The updated contents.
   */
  protected static function insertAfterAllowlist($gitignore, $rules) {
    if (preg_match_all('/^\s*!.*\n?/m', $gitignore, $m, PREG_OFFSET_CAPTURE)) {
      $last = end($m[0]);
      $insertAt = $last[1] + strlen($last[0]);
      if (substr($gitignore, $insertAt - 1, 1) !== "\n") {
        $rules = "\n" . $rules;
      }
      return substr($gitignore, 0, $insertAt) . $rules . substr($gitignore, $insertAt);
    }
    return rtrim($gitignore, "\n") . "\n" . $rules;
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
      $projectRoot . '.githooks',
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
