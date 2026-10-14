<?php

declare(strict_types=1);

namespace Drupal\searchstax_dictionary;

/** Normalizes plain-text entity labels into a single UTF-8 dictionary line. */
final class TermNormalizer {

  public static function normalize(string $label): string {
    if (!mb_check_encoding($label, 'UTF-8')) {
      throw new \UnexpectedValueException('A dictionary label is not valid UTF-8.');
    }
    // Preserve punctuation and literal HTML-like text in plain-text fields.
    $label = preg_replace('/[\p{Z}\s\p{Cc}]+/u', ' ', $label);
    $label = str_replace("\u{FEFF}", '', $label);
    return trim($label);
  }

}
