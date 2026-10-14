<?php

declare(strict_types=1);

namespace Drupal\searchstax_dictionary;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/** Bounded entity processing and database-backed deduplication. */
final class DictionaryExporter {

  public const CHUNK = 100;
  public const MAX_BYTES = 10000000;
  public const MAX_ENTRIES = 35000;

  public function __construct(
    private readonly EntityTypeManagerInterface $entities,
    private readonly Connection $database,
    private readonly AccountProxyInterface $account,
    private readonly TimeInterface $time,
  ) {}

  public function start(): string {
    $token = bin2hex(random_bytes(32));
    $this->database->insert('searchstax_dictionary_job')->fields([
      'token' => $token,
      'uid' => (int) $this->account->id(),
      'expires' => $this->time->getCurrentTime() + 86400,
      'ready' => 0,
    ])->execute();
    return $token;
  }

  public function authorize(string $token, bool $ready = FALSE): object {
    $job = $this->database->select('searchstax_dictionary_job', 'j')
      ->fields('j')->condition('token', $token)->execute()->fetchObject();
    if (!$this->account->isAuthenticated()
      || !$this->account->hasPermission('administer searchstax dictionary')
      || !$job || (int) $job->uid !== (int) $this->account->id()
      || (int) $job->expires <= $this->time->getCurrentTime()
      || ($ready && !(int) $job->ready)) {
      throw new AccessDeniedHttpException();
    }
    return $job;
  }

  /** A single Batch API iteration; sandbox contains only counters. */
  public function process(string $token, string $type, array $bundles, array &$context): void {
    $this->authorize($token);
    $context['results']['token'] = $token;
    $storage = $this->entities->getStorage($type);
    $definition = $this->entities->getDefinition($type);
    $id_key = $definition->getKey('id');
    $query = $storage->getQuery()->accessCheck(TRUE)
      ->condition($definition->getKey('bundle'), $bundles, 'IN')
      ->condition('status', 1);
    $sandbox = &$context['sandbox'];
    if (!isset($sandbox['last'])) {
      $highest = (clone $query)->sort($id_key, 'DESC')->range(0, 1)->execute();
      $sandbox = ['last' => 0, 'ceiling' => $highest ? (int) reset($highest) : 0];
    }
    $ids = (clone $query)->condition($id_key, $sandbox['last'], '>')
      ->condition($id_key, $sandbox['ceiling'], '<=')
      ->sort($id_key)->range(0, self::CHUNK)->execute();
    $anonymous = new AnonymousUserSession();
    foreach ($storage->loadMultiple($ids) as $entity) {
      // Export default translations only. Never include privileged-only labels.
      $entity = $entity->getUntranslated();
      $field = $entity->get($definition->getKey('label'));
      if (!$entity->isPublished()
        || !$entity->access('view', $this->account)
        || !$entity->access('view', $anonymous)
        || !$field->access('view', $this->account)
        || !$field->access('view', $anonymous)) {
        continue;
      }
      $label = TermNormalizer::normalize((string) $entity->label());
      if ($label !== '') {
        // Exact, case-sensitive deduplication across both entity types. Hashes
        // avoid database collation differences and make retries idempotent.
        $this->database->merge('searchstax_dictionary_entry')
          ->keys(['token' => $token, 'hash' => hash('sha256', $label)])
          ->fields(['label' => $label])->execute();
      }
    }
    $storage->resetCache($ids);
    if ($ids) {
      $sandbox['last'] = (int) max($ids);
    }
    $context['finished'] = count($ids) < self::CHUNK ? 1 : min(0.99, $sandbox['last'] / max(1, $sandbox['ceiling']));
  }

  /** Iterate in bounded pages even when the database driver buffers results. */
  public function lines(string $token): \Generator {
    $last = '';
    do {
      $rows = $this->database->select('searchstax_dictionary_entry', 'e')
        ->fields('e', ['hash', 'label'])->condition('token', $token)
        ->condition('hash', $last, '>')->orderBy('hash')->range(0, 500)
        ->execute()->fetchAll();
      foreach ($rows as $row) {
        $last = $row->hash;
        yield $row->label . "\n";
      }
    } while (count($rows) === 500);
  }

  /** Refuse oversized dictionaries without silently truncating them. */
  public function complete(string $token): void {
    $this->authorize($token);
    $count = (int) $this->database->select('searchstax_dictionary_entry', 'e')
      ->condition('token', $token)->countQuery()->execute()->fetchField();
    if ($count > self::MAX_ENTRIES) {
      $this->delete($token);
      throw new \LengthException('The dictionary exceeds the 35,000 unique suggestion limit. Select fewer sources. Splitting files does not bypass the total SearchStax suggestion limit.');
    }
    $bytes = 0;
    foreach ($this->lines($token) as $line) {
      $bytes += strlen($line);
      if ($bytes > self::MAX_BYTES) {
        $this->delete($token);
        throw new \LengthException('The dictionary exceeds the 10 MB upload limit. Select fewer sources.');
      }
    }
    if ($bytes === 0) {
      $this->delete($token);
      throw new \LengthException('No eligible public titles or terms were found. Check your selections and anonymous view permissions.');
    }
    $this->database->update('searchstax_dictionary_job')->fields([
      'ready' => 1,
      'expires' => $this->time->getCurrentTime() + 3600,
    ])->condition('token', $token)->execute();
  }

  public function delete(string $token): void {
    $this->database->delete('searchstax_dictionary_entry')->condition('token', $token)->execute();
    $this->database->delete('searchstax_dictionary_job')->condition('token', $token)->execute();
  }

  public function cleanup(): void {
    $tokens = $this->database->select('searchstax_dictionary_job', 'j')
      ->fields('j', ['token'])->condition('expires', $this->time->getCurrentTime(), '<=')
      ->range(0, 100)->execute()->fetchCol();
    foreach ($tokens as $token) {
      $this->delete($token);
    }
  }

}
