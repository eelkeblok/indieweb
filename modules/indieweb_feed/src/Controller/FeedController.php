<?php

namespace Drupal\indieweb_feed\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\indieweb_feed\Entity\FeedInterface;
use p3k\XRay;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;

class FeedController extends ControllerBase {

  /**
   * Routing callback: update items for a feed.
   *
   * @param \Drupal\indieweb_feed\Entity\FeedInterface $indieweb_feed
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *
   * @throws \Exception
   */
  public function updateItems(FeedInterface $indieweb_feed) {
    \Drupal::service('indieweb_feed.updater')->updateFeedItems($indieweb_feed);
    $this->messenger()->addMessage($this->t('Updated items for %feed', ['%feed' => $indieweb_feed->label()]));
    return new RedirectResponse(Url::fromRoute('entity.indieweb_feed.collection')->toString());
  }

  /**
   * Routing callback: returns a microformat feed.
   *
   * @param \Drupal\indieweb_feed\Entity\FeedInterface $indieweb_feed
   *
   * @return array|\Symfony\Component\HttpFoundation\Response
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function feedMicroformat(FeedInterface $indieweb_feed) {
    $build = [];

    $build['#title'] = $indieweb_feed->label();

    // Author info.
    if ($indieweb_feed->getAuthor()) {
      $build['author'] = [
        '#markup' => '<div class="h-card author-information hidden">' .
          $indieweb_feed->getAuthor() .
          '</div>',
        '#allowed_tags' => ['a', 'img', 'div', 'span'],
      ];
    }

    $items = [];
    $entities = $this->getItems($indieweb_feed);
    foreach ($entities as $entity) {
      try {
        $items[] = \Drupal::entityTypeManager()->getViewBuilder($entity->getEntityTypeId())->view($entity, 'indieweb_microformat');
      }
      catch (\Exception $ignored) {}
    }

    if (empty($items)) {
      $build['info']['#markup'] = '<p>' . $this->t('No items found') . '</p>';
    }
    else {

      $build['wrapper'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['h-feed'],
        ],
      ];

      $build['wrapper']['feed_name'] = ['#markup' => '<span class="hidden p-name">' . $indieweb_feed->getFeedTitle() . '</span>'];
      $build['wrapper']['items'] = $items;

      $build['pager'] = [
        '#type' => 'pager',
      ];
    }

    if ($indieweb_feed->excludeIndexing()) {
      $noindex = [
        '#tag' => 'meta',
        '#attributes' => [
          'name' => 'robots',
          'content' => 'noindex, nofollow',
        ],
      ];
      $build['#attached']['html_head'][] = [$noindex, 'indieweb_feed_noindex'];
    }

    $build['#cache']['tags'][] = 'indieweb_feed:' . $indieweb_feed->id();

    return $build;
  }

  /**
   * Routing callback: returns a JF2 feed.
   *
   * @param \Drupal\indieweb_feed\Entity\FeedInterface $indieweb_feed
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   */
  public function feedJf2(FeedInterface $indieweb_feed) {
    $data = [];

    $path = Url::fromUri('internal:/' . $indieweb_feed->getPath(), ['absolute' => TRUE])->toString();
    $client = \Drupal::httpClient();
    try {
      $response = $client->get($path);
      $body = $response->getBody()->getContents();
      $xray = new XRay();
      $data = $xray->parse($path, $body, ['expect' => 'feed']);
    }
    catch (\Exception $e) {
      $this->getLogger('indieweb_feed')->notice('Error generating JF2 feed: @message', ['@message' => $e->getMessage()]);
    }

    return JsonResponse::create($data);
  }

  /**
   * Get items for a feed.
   *
   * @param \Drupal\indieweb_feed\Entity\FeedInterface $indieweb_feed
   *
   * @return array $entities
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  protected function getItems(FeedInterface $indieweb_feed) {
    return $this->entityTypeManager()->getStorage('indieweb_feed_item')->loadItemsByFeed($indieweb_feed->id(), $indieweb_feed->getLimit());
  }

}
