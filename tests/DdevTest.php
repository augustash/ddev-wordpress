<?php

declare(strict_types=1);

namespace Augustash\Tests;

/**
 * ddev-wordpress specific tests, on top of the shared DdevTestCase base.
 *
 * WordPress ships no Selenium/test override, so dedupeWebEnvironment() is a
 * no-op in practice here. This guards that assumption: if an override is ever
 * added, this test fails and signals that the dedupe path now matters and
 * deserves the same coverage Drupal's shipped-override test gives it.
 */
final class DdevTest extends DdevTestCase {

  public function testNoSeleniumOverrideAssetShipped(): void {
    $assets = glob(__DIR__ . '/../assets/config.*.yaml') ?: [];
    $this->assertSame(
      [],
      $assets,
      'WordPress ships no config.*.yaml override; if that changes, add dedupe coverage for it.'
    );
  }

  // ---------------------------------------------------------------------------
  // hashPath() + fingerprint()
  //
  // These back the no-op detection that keeps postUpdate from printing a
  // restart prompt on every `composer update`. They're generic (not
  // WordPress-specific) and belong in DdevTestCase once the base is unified
  // across packages; kept here for now so the shared base stays byte-identical.
  // ---------------------------------------------------------------------------

  public function testHashPathMatchesFileContents(): void {
    $dir = $this->setConfigDir();
    $file = $dir . '/a.txt';
    file_put_contents($file, 'hello');
    $this->assertSame(md5_file($file), self::call('hashPath', $file));
  }

  public function testHashPathReturnsEmptyForMissingPath(): void {
    $this->assertSame('', self::call('hashPath', '/no/such/path-' . __LINE__));
  }

  public function testHashPathHashesDirectoryAndReactsToContentChange(): void {
    $dir = $this->setConfigDir();
    mkdir($dir . '/tree');
    file_put_contents($dir . '/tree/one.txt', 'one');
    file_put_contents($dir . '/tree/two.txt', 'two');

    $hash = self::call('hashPath', $dir . '/tree');
    $this->assertNotSame('', $hash);
    // Stable across calls when nothing changed.
    $this->assertSame($hash, self::call('hashPath', $dir . '/tree'));

    // A content change anywhere in the tree moves the hash.
    file_put_contents($dir . '/tree/two.txt', 'CHANGED');
    $this->assertNotSame($hash, self::call('hashPath', $dir . '/tree'));
  }

  public function testFingerprintIsStableOnNoopRun(): void {
    // The whole point: with no managed file touched between calls, the
    // fingerprint must not move — that's what lets the run stay silent.
    $dir = $this->setConfigDir();
    $this->setManagedRoots($dir);
    file_put_contents($dir . '/config.yaml', "name: site\n");

    $this->assertSame(self::call('fingerprint'), self::call('fingerprint'));
  }

  public function testFingerprintChangesWhenManagedFileChanges(): void {
    $dir = $this->setConfigDir();
    $this->setManagedRoots($dir);
    file_put_contents($dir . '/config.yaml', "name: site\n");

    $before = self::call('fingerprint');
    file_put_contents($dir . '/docker-compose.browsersync.yaml', "version: '3'\n");
    $this->assertNotSame($before, self::call('fingerprint'));
  }

  /**
   * Point $ddevRoot and $gitIgnorePath at the temp dir setConfigDir() created.
   *
   * setConfigDir() only redirects $configPath; fingerprint() also reads the
   * ddev asset root and the project .gitignore, so those need redirecting too
   * to keep the test off the real filesystem.
   */
  private function setManagedRoots(string $dir): void {
    foreach (['ddevRoot' => $dir . '/', 'gitIgnorePath' => $dir . '/.gitignore'] as $prop => $value) {
      $ref = new \ReflectionProperty(\Augustash\Ddev::class, $prop);
      $ref->setAccessible(TRUE);
      $ref->setValue(NULL, $value);
    }
  }

  // ---------------------------------------------------------------------------
  // isWpEngineSite() — WP Engine detection by wp-config.php constants.
  // ---------------------------------------------------------------------------

  public function testIsWpEngineSiteDetectsByApiKey(): void {
    $file = $this->writeWpConfig(self::stockWpEngineConfig());
    $this->assertTrue(self::call('isWpEngineSite', $file));
  }

  public function testIsWpEngineSiteDetectsByPwpNameAlone(): void {
    // A stripped WPE file with only PWP_NAME still counts — any one marker does.
    $file = $this->writeWpConfig(
      "<?php\ndefine( 'PWP_NAME', 'networksupport' );\nrequire_once(ABSPATH . 'wp-settings.php');\n"
    );
    $this->assertTrue(self::call('isWpEngineSite', $file));
  }

  public function testIsWpEngineSiteFalseForNonWpEngine(): void {
    // A vanilla wp-config.php with plain DB defines is not WP Engine.
    $file = $this->writeWpConfig(
      "<?php\ndefine( 'DB_NAME', 'wp' );\ndefine( 'WPE_LOOKALIKE', 'no' );\nrequire_once(ABSPATH . 'wp-settings.php');\n"
    );
    $this->assertFalse(self::call('isWpEngineSite', $file));
  }

  public function testIsWpEngineSiteFalseForMissingFile(): void {
    $this->assertFalse(self::call('isWpEngineSite', '/no/such/wp-config-' . __LINE__ . '.php'));
  }

  // ---------------------------------------------------------------------------
  // wrapDbDefines() — fence the prod DB credentials behind IS_DDEV_PROJECT.
  // ---------------------------------------------------------------------------

  public function testWrapDbDefinesWrapsContiguousRun(): void {
    $result = self::call('wrapDbDefines', self::stockWpEngineConfig());

    // The full DB_* run — including the optional SLAVE/CHARSET/COLLATE — sits
    // inside a single IS_DDEV_PROJECT guard, indented one level.
    $this->assertStringContainsString(
      "if ( getenv( 'IS_DDEV_PROJECT' ) !== 'true' ) {\n"
      . "  define( 'DB_NAME', 'wp_networksupport' );\n"
      . "  define( 'DB_USER', 'networksupport' );\n"
      . "  define( 'DB_PASSWORD', 'secret' );\n"
      . "  define( 'DB_HOST', '127.0.0.1:3306' );\n"
      . "  define( 'DB_HOST_SLAVE', '127.0.0.1:3306' );\n"
      . "  define('DB_CHARSET', 'utf8mb4');\n"
      . "  define('DB_COLLATE', '');\n"
      . "}",
      $result
    );
  }

  public function testWrapDbDefinesLeavesNonDbDefinesOutsideGuard(): void {
    $result = self::call('wrapDbDefines', self::stockWpEngineConfig());
    // $table_prefix and WPE constants sit after the run and must stay unwrapped.
    $this->assertMatchesRegularExpression('/^\}\n+\$table_prefix/m', $result);
    $this->assertStringContainsString("\ndefine( 'WPE_APIKEY',", $result);
  }

  public function testWrapDbDefinesIsIdempotent(): void {
    $once = self::call('wrapDbDefines', self::stockWpEngineConfig());
    $twice = self::call('wrapDbDefines', $once);
    $this->assertSame($once, $twice);
  }

  public function testWrapDbDefinesRespectsHandWrappedBlock(): void {
    // A file already gated by hand (the developer's exact edit) is left alone,
    // so the automation never double-wraps or fights a manual fix.
    $hand = "<?php\n"
      . "if ( getenv( 'IS_DDEV_PROJECT' ) !== 'true' ) {\n"
      . "  define( 'DB_NAME', 'wp' );\n"
      . "  define( 'DB_USER', 'u' );\n"
      . "}\n"
      . "define( 'WPE_APIKEY', 'x' );\n";
    $this->assertSame($hand, self::call('wrapDbDefines', $hand));
  }

  public function testWrapDbDefinesNoopWithoutDbDefines(): void {
    $none = "<?php\ndefine( 'WPE_APIKEY', 'x' );\nrequire_once(ABSPATH . 'wp-settings.php');\n";
    $this->assertSame($none, self::call('wrapDbDefines', $none));
  }

  // ---------------------------------------------------------------------------
  // insertDdevInclude() — load wp-config-ddev.php before WP boots.
  // ---------------------------------------------------------------------------

  public function testInsertDdevIncludeLandsBeforeWpSettings(): void {
    $result = self::call('insertDdevInclude', self::stockWpEngineConfig());

    // The include block is inserted, and immediately precedes the require.
    $this->assertStringContainsString("\$ddev_settings = dirname(__FILE__) . '/wp-config-ddev.php';", $result);
    $this->assertMatchesRegularExpression(
      "/if \\(is_readable\\(\\\$ddev_settings\\) && !defined\\('DB_USER'\\)\\) \\{\n"
      . "  require_once\\(\\\$ddev_settings\\);\n"
      . "\\}\n\n"
      . "\\s*require_once\\(ABSPATH \\. 'wp-settings\\.php'\\);/",
      $result
    );
  }

  public function testInsertDdevIncludeIsIdempotent(): void {
    $once = self::call('insertDdevInclude', self::stockWpEngineConfig());
    $twice = self::call('insertDdevInclude', $once);
    $this->assertSame($once, $twice);
    // Exactly one include block, even after two passes.
    $this->assertSame(1, substr_count($twice, "/wp-config-ddev.php';"));
  }

  public function testInsertDdevIncludeNoopWithoutAnchor(): void {
    // No wp-settings.php require means no safe anchor: leave the file untouched
    // rather than guess a location.
    $noAnchor = "<?php\ndefine( 'DB_NAME', 'wp' );\n";
    $this->assertSame($noAnchor, self::call('insertDdevInclude', $noAnchor));
  }

  // ---------------------------------------------------------------------------
  // applyWpEngineDbGate() — the full file round-trip.
  // ---------------------------------------------------------------------------

  public function testApplyWpEngineDbGateProducesRunnablePhp(): void {
    $file = $this->writeWpConfig(self::stockWpEngineConfig());

    $this->assertTrue(self::call('applyWpEngineDbGate', $file));

    $gated = file_get_contents($file);
    // Both edits present.
    $this->assertStringContainsString("if ( getenv( 'IS_DDEV_PROJECT' ) !== 'true' ) {", $gated);
    $this->assertStringContainsString("/wp-config-ddev.php';", $gated);
    // And the result still parses (php -l via token check — no syntax error).
    $this->assertNotFalse(
      @token_get_all($gated, TOKEN_PARSE),
      'Gated wp-config.php must remain valid PHP.'
    );
  }

  public function testApplyWpEngineDbGateIsIdempotent(): void {
    $file = $this->writeWpConfig(self::stockWpEngineConfig());

    $this->assertTrue(self::call('applyWpEngineDbGate', $file), 'First pass modifies the file.');
    $after = file_get_contents($file);

    $this->assertFalse(self::call('applyWpEngineDbGate', $file), 'Second pass is a no-op.');
    $this->assertSame($after, file_get_contents($file), 'Second pass leaves bytes unchanged.');
  }

  // ---------------------------------------------------------------------------
  // unignoreDdevDir() — track .ddev/ in a WPE `/*` deny-all .gitignore.
  // ---------------------------------------------------------------------------

  public function testUnignoreDdevDirInsertsAfterAllowlist(): void {
    $file = $this->writeGitignore(self::wpEngineGitignore());

    $this->assertTrue(self::call('unignoreDdevDir'));

    $result = file_get_contents($file);
    // The un-ignore rules land right after the last existing `!` allowlist entry
    // (!/wp-content/), reading as part of the keep-list.
    $this->assertMatchesRegularExpression(
      "/!\/wp-content\/\n!\/\.ddev\/\n!\/\.ddev\/\*\*\n/",
      $result
    );
    // The `/*` deny-all and the wp-content ignores below are untouched.
    $this->assertStringContainsString("/*\n", $result);
    $this->assertStringContainsString('wp-content/uploads/', $result);
  }

  public function testUnignoreDdevDirIsIdempotent(): void {
    $file = $this->writeGitignore(self::wpEngineGitignore());

    $this->assertTrue(self::call('unignoreDdevDir'), 'First pass modifies.');
    $after = file_get_contents($file);

    $this->assertFalse(self::call('unignoreDdevDir'), 'Second pass is a no-op.');
    $this->assertSame($after, file_get_contents($file));
    // Exactly one un-ignore rule, no duplication.
    $this->assertSame(1, substr_count($after, "!/.ddev/\n"));
  }

  public function testUnignoreDdevDirNoopWithoutDenyAll(): void {
    // A normal .gitignore (no `/*` root wildcard) has nothing to override.
    $normal = "wp-content/uploads/\n.DS_Store\n";
    $this->writeGitignore($normal);
    $this->assertFalse(self::call('unignoreDdevDir'));
    $this->assertSame($normal, file_get_contents(self::gitignorePath()));
  }

  public function testUnignoreDdevDirNoopWhenAlreadyUnignored(): void {
    // A repo that already un-ignores .ddev/ (any form) is left alone.
    $already = "/*\n!/.gitignore\n!/.ddev/\n!/wp-content/\n";
    $this->writeGitignore($already);
    $this->assertFalse(self::call('unignoreDdevDir'));
    $this->assertSame($already, file_get_contents(self::gitignorePath()));
  }

  public function testUnignoreDdevDirNoopWhenFileMissing(): void {
    // Point at a nonexistent path; nothing to do.
    $ref = new \ReflectionProperty(\Augustash\Ddev::class, 'gitIgnorePath');
    $ref->setAccessible(TRUE);
    $ref->setValue(NULL, '/no/such/.gitignore-' . __LINE__);
    $this->assertFalse(self::call('unignoreDdevDir'));
  }

  /**
   * Write .gitignore contents to a temp file, redirect $gitIgnorePath, return.
   */
  private function writeGitignore(string $contents): string {
    $dir = $this->setConfigDir();
    $file = $dir . '/.gitignore';
    file_put_contents($file, $contents);
    $ref = new \ReflectionProperty(\Augustash\Ddev::class, 'gitIgnorePath');
    $ref->setAccessible(TRUE);
    $ref->setValue(NULL, $file);
    return $file;
  }

  /**
   * The path $gitIgnorePath currently points at (set by writeGitignore()).
   */
  private static function gitignorePath(): string {
    $ref = new \ReflectionProperty(\Augustash\Ddev::class, 'gitIgnorePath');
    $ref->setAccessible(TRUE);
    return $ref->getValue();
  }

  /**
   * A representative WP Engine `/*` deny-all .gitignore (wp-content only).
   */
  private static function wpEngineGitignore(): string {
    return <<<'GITIGNORE'
# Track wp-content code only.

# Ignore everything at the doc root by default...
/*

# ...but keep these:
!/.gitignore
!/.githooks/
!/wp-content/

# --- Inside wp-content: ignore uploads, caches ---
wp-content/uploads/
wp-content/cache/
GITIGNORE;
  }

  /**
   * Write wp-config.php contents to a temp file and return its path.
   */
  private function writeWpConfig(string $contents): string {
    $dir = $this->setConfigDir();
    $file = $dir . '/wp-config.php';
    file_put_contents($file, $contents);
    return $file;
  }

  /**
   * A representative stock WP Engine wp-config.php (pre-gate).
   *
   * Mirrors the real shape: an unconditional DB_* run with the optional
   * SLAVE/CHARSET/COLLATE variants, WPE platform constants, $table_prefix after
   * the DB block, and the wp-settings.php require as the boot anchor.
   */
  private static function stockWpEngineConfig(): string {
    return <<<'PHP'
<?php
// Database Configuration
define( 'DB_NAME', 'wp_networksupport' );
define( 'DB_USER', 'networksupport' );
define( 'DB_PASSWORD', 'secret' );
define( 'DB_HOST', '127.0.0.1:3306' );
define( 'DB_HOST_SLAVE', '127.0.0.1:3306' );
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');

$table_prefix = 'wp_';

define( 'WPE_APIKEY', '3816abcbb8d7ef88301a3de9d1478a605ae36b37' );
define( 'PWP_NAME', 'networksupport' );

if ( !defined('ABSPATH') )
	define('ABSPATH', __DIR__ . '/');
require_once(ABSPATH . 'wp-settings.php');
PHP;
  }

}
