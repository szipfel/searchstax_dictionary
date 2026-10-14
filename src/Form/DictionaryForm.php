<?php

declare(strict_types=1);

namespace Drupal\searchstax_dictionary\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\searchstax_dictionary\BatchCallbacks;
use Drupal\searchstax_dictionary\DictionaryExporter;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/** Selects sources and starts the export through a CSRF-protected form. */
final class DictionaryForm extends ConfigFormBase {

  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config,
    private readonly EntityTypeManagerInterface $entities,
    private readonly DictionaryExporter $exporter,
  ) {
    parent::__construct($config_factory, $typed_config);
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('entity_type.manager'),
      $container->get('searchstax_dictionary.exporter'),
    );
  }

  public function getFormId(): string {
    return 'searchstax_dictionary_settings';
  }

  protected function getEditableConfigNames(): array {
    return ['searchstax_dictionary.settings'];
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $settings = $this->config('searchstax_dictionary.settings');
    $form['intro'] = [
      '#markup' => $this->t('Export published content titles and taxonomy terms that you and anonymous visitors can view. Only default translations are included. The UTF-8 text file contains one suggestion per line, with exact duplicates removed. Limits: 35,000 unique suggestions and 10 MB per file. Suggestions already in SearchStax also count toward its total limit; this exporter cannot check that existing list.'),
    ];
    foreach (['content_types' => 'node_type', 'vocabularies' => 'taxonomy_vocabulary'] as $key => $type) {
      $options = [];
      foreach ($this->entities->getStorage($type)->loadMultiple() as $entity) {
        $options[$entity->id()] = $entity->label();
      }
      asort($options);
      $form[$key] = [
        '#type' => 'checkboxes',
        '#title' => $key === 'content_types' ? $this->t('Content types') : $this->t('Taxonomy vocabularies'),
        '#options' => $options,
        '#default_value' => $settings->get($key) ?? [],
      ];
    }
    $token = $this->getRequest()->query->get('export');
    if (is_string($token) && preg_match('/^[a-f0-9]{64}$/D', $token)) {
      try {
        $this->exporter->authorize($token, TRUE);
        $form['download'] = [
          '#type' => 'link',
          '#title' => $this->t('Download dictionary (.txt)'),
          '#url' => Url::fromRoute('searchstax_dictionary.download', ['token' => $token]),
          '#attributes' => ['class' => ['button', 'button--primary']],
          '#weight' => -10,
          '#suffix' => '<p>' . $this->t('Your download is ready for one hour. Upload it in SearchStax under Auto-Suggest → Dictionary-Driven, then save and publish.') . '</p>',
        ];
      }
      catch (AccessDeniedHttpException) {
        $this->messenger()->addWarning($this->t('This export is no longer available. Generate a new dictionary.'));
      }
    }
    $form = parent::buildForm($form, $form_state);
    $form['actions']['generate'] = [
      '#type' => 'submit',
      '#value' => $this->t('Generate Dictionary'),
      '#submit' => ['::submitForm', '::generate'],
    ];
    $form['#cache']['max-age'] = 0;
    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    if (!array_filter($form_state->getValue('content_types', [])) && !array_filter($form_state->getValue('vocabularies', []))) {
      $form_state->setErrorByName('content_types', $this->t('Select at least one content type or vocabulary.'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('searchstax_dictionary.settings')
      ->set('content_types', array_values(array_filter($form_state->getValue('content_types'))))
      ->set('vocabularies', array_values(array_filter($form_state->getValue('vocabularies'))))
      ->save();
    parent::submitForm($form, $form_state);
  }

  public function generate(array &$form, FormStateInterface $form_state): void {
    $token = $this->exporter->start();
    $operations = [];
    foreach (['content_types' => 'node', 'vocabularies' => 'taxonomy_term'] as $key => $type) {
      $bundles = array_values(array_filter($form_state->getValue($key)));
      if ($bundles) {
        $operations[] = [[BatchCallbacks::class, 'process'], [$token, $type, $bundles]];
      }
    }
    batch_set([
      'title' => $this->t('Generating SearchStax dictionary'),
      'operations' => $operations,
      'finished' => [BatchCallbacks::class, 'finish'],
      'results' => ['token' => $token],
    ]);
  }

}
