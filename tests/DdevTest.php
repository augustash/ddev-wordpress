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

}
