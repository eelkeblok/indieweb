<?php

namespace Drupal\indieweb_test\MediaCacheClient;

use Drupal\indieweb\MediaCacheClient\MediaCacheClientDefault;

class MediaCacheClientTest extends MediaCacheClientDefault {

  /**
   * {@inheritdoc}
   */
  public function imageCacheExternalEnabled() {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function replaceImagesInString($content, $type = 'photo') {
    $images = [];
    $extracted_images = $this->extractImages($content);
    if (!empty($extracted_images)) {
      foreach ($extracted_images as $image) {
        $images[$image] = $this->applyImageCache($image, $type);
      }
      $content = str_replace(array_keys($images), array_values($images), $content);
    }
    return $content;
  }

  /**
   * {@inheritdoc}
   */
  public function applyImageCache($filename, $type = 'avatar', $context = '') {
    return str_replace('.png', '.jpg', $filename);
  }

}
