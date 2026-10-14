<?php

declare(strict_types=1);

namespace Drupal\searchstax_dictionary;

use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;

/** Serializable Batch API bridge; application logic lives in the DI service. */
final class BatchCallbacks {

  public static function process(string $token, string $type, array $bundles, array &$context): void {
    \Drupal::service('searchstax_dictionary.exporter')->process($token, $type, $bundles, $context);
    $context['message'] = t('Collecting publicly viewable dictionary entries.');
  }

  public static function finish(bool $success, array $results, array $operations, string $elapsed): RedirectResponse {
    $exporter = \Drupal::service('searchstax_dictionary.exporter');
    $token = $results['token'] ?? NULL;
    if ($success && $token) {
      try {
        $exporter->complete($token);
        return new RedirectResponse(Url::fromRoute('searchstax_dictionary.settings', [], [
          'query' => ['export' => $token],
        ])->toString());
      }
      catch (\LengthException $exception) {
        \Drupal::messenger()->addError($exception->getMessage());
      }
    }
    else {
      if ($token) {
        $exporter->delete($token);
      }
      \Drupal::messenger()->addError(t('Dictionary generation failed. Please generate it again.'));
    }
    return new RedirectResponse(Url::fromRoute('searchstax_dictionary.settings')->toString());
  }

}
