<?php

use Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Term;
use Drupal\searchstax_dictionary\TermNormalizer;
use Drupal\searchstax_dictionary\Controller\DownloadController;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\Session\UserSession;
use Drupal\Core\Form\FormState;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

function check_dictionary($condition, $message) {
  if (!$condition) {
    throw new RuntimeException($message);
  }
  echo "PASS: $message\n";
}

$database = \Drupal::database();
$transaction = $database->startTransaction();
$switcher = \Drupal::service('account_switcher');
$switcher->switchTo(\Drupal\user\Entity\User::load(1));
$exporter = \Drupal::service('searchstax_dictionary.exporter');
$prefix = 'Dictionary validation ' . bin2hex(random_bytes(5));
$tokens = [];
try {
  check_dictionary(TermNormalizer::normalize("\u{FEFF} Café\r\nParks\t&\u{00A0}trails\0 ") === 'Café Parks & trails', 'UTF-8, newline, tab, BOM and control normalization');
  check_dictionary(TermNormalizer::normalize('A, B => C <D>') === 'A, B => C <D>', 'Punctuation remains literal text');
  try {
    TermNormalizer::normalize("bad\xFF");
    throw new RuntimeException('Invalid UTF-8 was accepted');
  }
  catch (UnexpectedValueException) {
    echo "PASS: Invalid UTF-8 rejected\n";
  }
  // SQL changes are rolled back; use existing local demo source bundles.
  for ($i = 0; $i < 102; $i++) {
    Node::create(['type' => 'article', 'title' => "$prefix Café\nLibrary", 'status' => 1, 'uid' => 1])->save();
  }
  Node::create(['type' => 'article', 'title' => "$prefix hidden", 'status' => 0, 'uid' => 1])->save();
  Node::create(['type' => 'page', 'title' => "$prefix other bundle", 'status' => 1, 'uid' => 1])->save();
  Term::create(['vid' => 'tags', 'name' => "$prefix Café Library", 'status' => 1])->save();
  Term::create(['vid' => 'tags', 'name' => "$prefix term only", 'status' => 1])->save();
  Term::create(['vid' => 'tags', 'name' => "$prefix hidden term", 'status' => 0])->save();

  $token = $exporter->start();
  $tokens[] = $token;
  $passes = 0;
  foreach (['node' => ['article'], 'taxonomy_term' => ['tags']] as $type => $bundles) {
    $context = ['sandbox' => [], 'results' => [], 'finished' => 0];
    do {
      $exporter->process($token, $type, $bundles, $context);
      $passes++;
    } while ($context['finished'] < 1);
  }
  check_dictionary($passes >= 3, 'Generation spans multiple bounded batch iterations');
  $exporter->complete($token);
  $lines = iterator_to_array($exporter->lines($token));
  $fixture_lines = array_values(array_filter($lines, fn($line) => str_starts_with($line, $prefix)));
  sort($fixture_lines);
  check_dictionary($fixture_lines === ["$prefix Café Library\n", "$prefix term only\n"], 'Cross-source deduplication, publication filters and source selection');
  $response = DownloadController::create(\Drupal::getContainer())->download($token);
  ob_start();
  $response->sendContent();
  $body = ob_get_clean();
  check_dictionary($body === implode('', $lines), 'Download streams exact UTF-8 lines');
  check_dictionary(str_contains($response->headers->get('Cache-Control'), 'no-store'), 'Download is not cached');
  check_dictionary(str_contains($response->headers->get('Content-Disposition'), 'attachment'), 'Download uses attachment disposition');

  foreach ([new AnonymousUserSession(), new UserSession(['uid' => 987654321, 'roles' => ['administrator']])] as $account) {
    $switcher->switchTo($account);
    try {
      $exporter->authorize($token, TRUE);
      throw new RuntimeException('Unauthorized download accepted');
    }
    catch (AccessDeniedHttpException) {
      echo "PASS: Non-owner or anonymous download rejected\n";
    }
    finally {
      $switcher->switchBack();
    }
  }
  $route = \Drupal::service('router.route_provider')->getRouteByName('searchstax_dictionary.settings');
  check_dictionary($route->getPath() === '/admin/config/search/searchstax-dictionary', 'Admin route is registered');
  check_dictionary($route->getRequirement('_permission') === 'administer searchstax dictionary', 'Admin route requires restricted permission');
  $form = \Drupal::formBuilder()->getForm(\Drupal\searchstax_dictionary\Form\DictionaryForm::class);
  check_dictionary(isset($form['content_types'], $form['vocabularies'], $form['actions']['generate']), 'Dependency-injected form builds with both selectors and Generate button');

  $database->update('searchstax_dictionary_job')->fields(['expires' => 1])->condition('token', $token)->execute();
  try {
    $exporter->authorize($token, TRUE);
    throw new RuntimeException('Expired download accepted');
  }
  catch (AccessDeniedHttpException) {
    echo "PASS: Expired download rejected\n";
  }
  $exporter->cleanup();
  check_dictionary(!(bool) $database->select('searchstax_dictionary_entry', 'e')->condition('token', $token)->countQuery()->execute()->fetchField(), 'Cron removes expired entries');
  foreach (['empty', 'oversize'] as $case) {
    $test_token = $exporter->start();
    $tokens[] = $test_token;
    if ($case === 'oversize') {
      $database->insert('searchstax_dictionary_entry')->fields(['token' => $test_token, 'hash' => hash('sha256', 'large'), 'label' => str_repeat('x', 10000000)])->execute();
    }
    try {
      $exporter->complete($test_token);
      throw new RuntimeException("$case export accepted");
    }
    catch (LengthException) {
      echo "PASS: $case export rejected without partial download\n";
    }
  }
}
finally {
  $transaction->rollBack();
  $switcher->switchBack();
  \Drupal::entityTypeManager()->getStorage('node')->resetCache();
  \Drupal::entityTypeManager()->getStorage('taxonomy_term')->resetCache();
  echo "Validation fixtures rolled back.\n";
}
