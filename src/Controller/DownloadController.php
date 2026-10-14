<?php

declare(strict_types=1);

namespace Drupal\searchstax_dictionary\Controller;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\searchstax_dictionary\DictionaryExporter;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Serves only completed exports to their authenticated owner. */
final class DownloadController implements ContainerInjectionInterface {

  public function __construct(private readonly DictionaryExporter $exporter) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('searchstax_dictionary.exporter'));
  }

  public function download(string $token): StreamedResponse {
    $this->exporter->authorize($token, TRUE);
    return new StreamedResponse(function () use ($token): void {
      foreach ($this->exporter->lines($token) as $line) {
        echo $line;
      }
    }, 200, [
      'Content-Type' => 'text/plain; charset=UTF-8',
      'Content-Disposition' => 'attachment; filename="searchstax-dictionary.txt"',
      'Cache-Control' => 'private, no-store, max-age=0',
      'X-Content-Type-Options' => 'nosniff',
      'Referrer-Policy' => 'no-referrer',
    ]);
  }

}
