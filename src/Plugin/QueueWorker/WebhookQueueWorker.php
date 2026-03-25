<?php

namespace Drupal\autotix\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\autotix\Service\WebhookClient;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Processes queued webhook payloads during cron.
 *
 * @QueueWorker(
 *   id = "autotix",
 *   title = @Translation("Autotix webhook sender"),
 *   cron = {"time" = 30}
 * )
 */
class WebhookQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  protected WebhookClient $client;

  public function __construct(array $configuration, $plugin_id, $plugin_definition, WebhookClient $client) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->client = $client;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('autotix.client'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data) {
    if (!is_array($data)) {
      return;
    }
    $this->client->send($data);
  }

}
