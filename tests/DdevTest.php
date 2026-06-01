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

}
