<?php

declare(strict_types=1);

namespace Drupal\Tests\searchstax_dictionary\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\searchstax_dictionary\TermNormalizer;

/**
 * Tests the boundary between content labels and upload lines.
 *
 * @group searchstax_dictionary
 */
final class TermNormalizerTest extends UnitTestCase {

  public function testNormalization(): void {
    $this->assertSame('Café Parks & trails', TermNormalizer::normalize("\u{FEFF} Café\r\nParks\t&\u{00A0}trails\0 "));
    $this->assertSame('', TermNormalizer::normalize("\t\r\n\0"));
    $this->assertSame('A, B => C <D>', TermNormalizer::normalize('A, B => C <D>'));
    $this->assertSame('家庭 👩‍👩‍👧‍👦', TermNormalizer::normalize('家庭 👩‍👩‍👧‍👦'));
  }

  public function testInvalidUtf8IsRejected(): void {
    $this->expectException(\UnexpectedValueException::class);
    TermNormalizer::normalize("bad\xFF");
  }

}
