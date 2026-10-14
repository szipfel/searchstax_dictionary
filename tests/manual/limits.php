<?php

/**
 * @file
 * Run with drush php:script on a local test site with this module enabled.
 * All fixture rows are rolled back. Requires an active user 1.
 */

use Drupal\searchstax_dictionary\DictionaryExporter;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

$database = \Drupal::database();
$transaction = $database->startTransaction();
$switcher = \Drupal::service('account_switcher');
$switcher->switchTo(\Drupal\user\Entity\User::load(1));
$exporter = \Drupal::service('searchstax_dictionary.exporter');
try {
  $token = $exporter->start();
  for ($start = 0; $start < 35000; $start += 500) {
    $insert = $database->insert('searchstax_dictionary_entry')->fields(['token', 'hash', 'label']);
    for ($i = $start; $i < $start + 500; $i++) {
      $label = 'Boundary suggestion ' . $i;
      $insert->values([$token, hash('sha256', $label), $label]);
    }
    $insert->execute();
  }
  $exporter->complete($token);
  $exporter->authorize($token, TRUE);
  if (iterator_count($exporter->lines($token)) !== 35000) {
    throw new \RuntimeException('The exact entry boundary was truncated.');
  }
  echo "PASS: Exactly 35,000 unique entries accepted without truncation.\n";

  // Inserting a duplicate must not consume another entry slot.
  $database->merge('searchstax_dictionary_entry')
    ->keys(['token' => $token, 'hash' => hash('sha256', 'Boundary suggestion 0')])
    ->fields(['label' => 'Boundary suggestion 0'])->execute();
  $exporter->complete($token);
  echo "PASS: A duplicate does not exceed the entry limit.\n";

  $database->insert('searchstax_dictionary_entry')->fields([
    'token' => $token, 'hash' => hash('sha256', 'One extra'), 'label' => 'One extra',
  ])->execute();
  try {
    $exporter->complete($token);
    throw new \RuntimeException('35,001 entries were accepted.');
  }
  catch (\LengthException $exception) {
    if (!str_contains($exception->getMessage(), '35,000')) {
      throw $exception;
    }
  }
  foreach (['searchstax_dictionary_job', 'searchstax_dictionary_entry'] as $table) {
    if ($database->select($table, 't')->condition('token', $token)->countQuery()->execute()->fetchField()) {
      throw new \RuntimeException('Rejected export data was not cleaned up.');
    }
  }
  try {
    $exporter->authorize($token, TRUE);
    throw new \RuntimeException('Rejected export remains downloadable.');
  }
  catch (AccessDeniedHttpException) {
    echo "PASS: 35,001 entries rejected, cleaned up and unavailable for download.\n";
  }

  // The independent file-size limit includes each trailing newline.
  $token = $exporter->start();
  $label = str_repeat('x', DictionaryExporter::MAX_BYTES - 1);
  $database->insert('searchstax_dictionary_entry')->fields([
    'token' => $token, 'hash' => hash('sha256', $label), 'label' => $label,
  ])->execute();
  $exporter->complete($token);
  echo "PASS: Exactly 10,000,000 bytes accepted.\n";
  $database->insert('searchstax_dictionary_entry')->fields([
    'token' => $token, 'hash' => hash('sha256', 'x'), 'label' => 'x',
  ])->execute();
  try {
    $exporter->complete($token);
    throw new \RuntimeException('Oversized file was accepted.');
  }
  catch (\LengthException $exception) {
    if (!str_contains($exception->getMessage(), '10 MB')) {
      throw $exception;
    }
    echo "PASS: The 10 MB limit remains enforced independently.\n";
  }
}
finally {
  $transaction->rollBack();
  $switcher->switchBack();
  echo "Validation fixtures rolled back.\n";
}
