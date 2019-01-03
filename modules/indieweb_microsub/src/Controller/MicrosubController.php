<?php

namespace Drupal\indieweb_microsub\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\indieweb_microsub\Entity\MicrosubChannelInterface;
use Drupal\indieweb_microsub\Entity\MicrosubSourceInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

class MicrosubController extends ControllerBase {

  /**
   * @var  \Drupal\Core\Config\Config
   */
  protected $config;
  
  /**
   * Request object.
   *
   * @var \Symfony\Component\HttpFoundation\Request $request
   */
  protected $request;
  
  /**
   * @var \Drupal\indieweb_indieauth\IndieAuthClient\IndieAuthClientInterface
   */
  protected $indieAuth;

  /**
   * Microsub endpoint.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function endpoint(Request $request) {

    $this->request = $request;
    $this->indieAuth = \Drupal::service('indieweb.indieauth.client');

    $this->config = \Drupal::config('indieweb_microsub.settings');
    $microsub_enabled = $this->config->get('microsub_internal');

    // Early response when endpoint is not enabled.
    if (!$microsub_enabled) {
      return new JsonResponse('', 404);
    }

    // Default response code and message.
    $response = [
      'message' => 'Bad request',
      'code' => 400,
    ];

    // Get authorization header, response early if none found.
    $auth_header = $this->indieAuth->getAuthorizationHeader();
    if (!$auth_header) {
      return new JsonResponse('', 401);
    }

    $scope = NULL;
    $request_method = $request->getMethod();
    $action = $request->get('action');
    if ($action == 'channels' || $action == 'timeline') {
      $scope = 'read';
    }

    if (!$this->indieAuth->isValidToken($auth_header, $scope)) {
      return new JsonResponse('', 403);
    }

    // GET actions.
    if ($request_method == 'GET') {

      switch ($action) {

        case 'channels':
          $response = $this->getChannelList();
          break;

        case 'timeline':
          $response = $this->getTimeline();
          break;

      }
    }

    // POST actions.
    if ($request_method == 'POST') {
      switch ($action) {
        case 'timeline':

          $method = $request->get('method');
          if ($method == 'mark_read') {
            $response = $this->timelineMarkAllRead();
          }

          if ($method == 'remove') {
            $response = $this->removeItem();
          }

          break;
      }
    }

    $response_message = isset($response['response']) ? $response['response'] : [];
    $response_code = isset($response['code']) ? $response['code'] : 200;

    return new JsonResponse($response_message, $response_code);
  }

  /**
   * Handle channels request.
   *
   * @return array $response
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  protected function getChannelList() {
    $channels = [];

    $ids = $this->entityTypeManager()
      ->getStorage('indieweb_microsub_channel')
      ->getQuery()
      ->condition('status', 1)
      ->sort('weight', 'ASC')
      ->execute();

    $channels_list = $this->entityTypeManager()->getStorage('indieweb_microsub_channel')->loadMultiple($ids);

    // Notifications channel.
    $notifications = \Drupal::entityTypeManager()->getStorage('indieweb_microsub_item')->getUnreadCountByChannel(0);
    $channels[] = (object) [
      'uid' => 0,
      'name' => 'Notifications',
      'unread' => (int) $notifications,
    ];

    /** @var \Drupal\indieweb_microsub\Entity\MicrosubChannelInterface $channel */
    foreach ($channels_list as $channel) {
      $channels[] = (object) [
        'uid' => $channel->id(),
        'name' => $channel->label(),
        'unread' => (int) $channel->getUnreadCount(),
      ];
    }

    return ['response' => ['channels' => $channels]];
  }

  /**
   * Handle timeline request.
   *
   * @return array $response
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  protected function getTimeline() {
    $response = [];

    $channel = $this->request->get('channel');

    // Get items for a specific channel.
    if ($channel || ((int) $channel === 0)) {

      $items = [];
      $paging = [];

      // Set pager.
      $page = $this->request->get('after', 0);
      if ($page > 0) {
        \Drupal::request()->query->set('page', $page);
      }

      /** @var \Drupal\indieweb_microsub\Entity\MicrosubItemInterface[] $microsub_items */
      $microsub_items = $this->entityTypeManager()->getStorage('indieweb_microsub_item')->loadByChannel($channel);
      foreach ($microsub_items as $item) {

        $data = $item->getData();

        // See https://github.com/swentel/indieweb/issues/325
        $fields_to_fix = ['in-reply-to', 'like-of', 'repost-of'];
        foreach ($fields_to_fix as $field) {
          if (isset($data->{$field})) {
            $flat = [];
            foreach ($data->{$field} as $field_value) {
              $flat[] = $field_value;
            }
            $data->{$field} = $flat;
          }
        }

        // Apply media cache.
        if ($channel > 0 && !$item->getSource()->disableImageCache()) {
          $this->applyCache($data);
        }

        $entry = $data;
        $entry->_id = $item->id();
        $entry->_is_read = $item->isRead();

        // Get context.
        if (!isset($entry->refs) && ($context = $item->getContext())) {
          // TODO fix when https://github.com/indieweb/jf2/issues/41 lands.
          $entry->refs = $context;
        }

        $items[] = $entry;
      }

      // Calculate pager and after.
      global $pager_total;
      $page++;
      if (isset($pager_total[0]) && $pager_total[0] > $page) {
        $paging = ['after' => $page];
      }

      $response = ['items' => $items, 'paging' => (object) $paging];
    }

    return ['response' => $response, 'code' => 200];
  }

  /**
   * Apply cache settings.
   *
   * @param $data
   */
  protected function applyCache($data) {

    // Author images.
    if (isset($data->author->photo)) {
      $data->author->photo = \Drupal::service('indieweb.media_cache.client')->applyImageCache($data->author->photo);
    }

    // Photos.
    if (isset($data->photo) && !empty($data->photo) && is_array($data->photo)) {
      foreach ($data->photo as $i => $p) {
        $data->photo[$i] = \Drupal::service('indieweb.media_cache.client')->applyImageCache($p, 'photo');
      }
    }

    // Images in html content.
    if (!empty($data->content->html)) {
      $data->content->html = \Drupal::service('indieweb.media_cache.client')->replaceImagesInString($data->content->html, 'photo');
    }
  }

  /**
   * Mark items read for a channel.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  protected function timelineMarkAllRead() {

    $channel_id = $this->request->get('channel');
    if ($channel_id || ((int) $channel_id === 0)) {
      $this->entityTypeManager()->getStorage('indieweb_microsub_item')->markItemsRead($channel_id);
    }

    return ['response' => [], 'code' => 200];
  }

  /**
   * Removes a microsub item
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  protected function removeItem() {

    $entry_id = $this->request->get('entry');
    if ($entry_id) {
     $this->entityTypeManager()->getStorage('indieweb_microsub_item')->removeItem($entry_id);
    }

    return ['response' => [], 'code' => 200];
  }

  /**
   * Microsub channel overview.
   *
   * @return array
   */
  public function channelOverview() {
    return $this->entityManager()->getListBuilder('indieweb_microsub_channel')->render();
  }

  /**
   * Microsub sources overview.
   *
   * @param \Drupal\indieweb_microsub\Entity\MicrosubChannelInterface $indieweb_microsub_channel
   *
   * @return array
   */
  public function sourcesOverview(MicrosubChannelInterface $indieweb_microsub_channel) {
    return $this->entityManager()->getListBuilder('indieweb_microsub_source')->render($indieweb_microsub_channel);
  }

  /**
   * Reset fetch next time for a source.
   *
   * @param \Drupal\indieweb_microsub\Entity\MicrosubSourceInterface $indieweb_microsub_source
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function resetNextFetch(MicrosubSourceInterface $indieweb_microsub_source) {
    $indieweb_microsub_source->setNextFetch(0)->save();
    $this->messenger()->addMessage($this->t('Next update reset for %source', ['%source' => $indieweb_microsub_source->label()]));
    return new RedirectResponse(Url::fromRoute('indieweb.admin.microsub_sources', ['indieweb_microsub_channel' => $indieweb_microsub_source->getChannelId()])->toString());
  }

}