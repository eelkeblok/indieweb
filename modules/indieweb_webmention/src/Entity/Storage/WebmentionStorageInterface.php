<?php

namespace Drupal\indieweb_webmention\Entity\Storage;

use Drupal\Core\Entity\ContentEntityStorageInterface;

interface WebmentionStorageInterface extends ContentEntityStorageInterface {

  /**
   * Get webmentions.
   *
   * @param $types
   *   The types
   * @param $target
   *   The target path.
   * @param int $number_of_posts
   *   The number of posts
   * @param string $sort_field
   *   The field to sort by
   * @param string $sort_direction
   *   The direction to sort by
   *
   * @return mixed
   */
  public function getWebmentions($types, $target, $number_of_posts = 0, $sort_field = 'id', $sort_direction = 'DESC');

  /**
   * Returns options for a single webmention property.
   *
   * @param $field
   *
   * @return array $options
   */
  public function getFieldOptions($field);

  /**
   * Get a webmention by target, property and uid.
   *
   * @param $target
   * @param $property
   * @param $uid
   *
   * @return mixed
   */
  public function getWebmentionByTargetPropertyAndUid($target, $property, $uid);

  /**
   * Check identical webmention by source, target and property.
   *
   * @param $source
   * @param $target
   * @param $property
   *
   * @return mixed
   */
  public function checkIdenticalWebmention($source, $target, $property);

  /**
   * Update RSVP value.
   *
   * @param $value
   *   The RSVP value.
   * @param $id
   *   The webmention id.
   */
  public function updateRSVP($value, $id);

  /**
   * Get comment id by webmention id.
   *
   * @param $field_name
   *   The comment field name.
   * @param $id
   *   The webmention id.
   *
   * @return int $comment_id | FALSE
   */
  public function getCommentIdByWebmentionId($field_name, $id);

}
