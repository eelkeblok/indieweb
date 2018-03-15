<?php

namespace Drupal\indieweb\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

class PublishSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['indieweb.publish'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'indieweb_publish_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    $form['#attached']['library'][] = 'indieweb/admin';

    $config = $this->config('indieweb.publish');

    $form['info'] = [
      '#markup' => '<p>' . $this->t('The easiest way to start pulling back content or publish content on social networks is by using <a href="https://brid.gy/" target="_blank">https://brid.gy</a>. <br />You have to create an account by signing in with your preferred social network. Bridgy is open source so you can also host the service yourself.<br /><br />Publishing, which is nothing more than sending a webmention, can be done per node in the "Publish to" fieldset, which is protected with the "send webmentions" permission.<br />If no channels are configured, there is nothing to do.') . '</p>',
    ];

    $form['channels_wrapper'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Publishing channels')
    ];

    $form['channels_wrapper']['channels'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Publishing channels'),
      '#title_display' => 'invisible',
      '#default_value' => $config->get('channels'),
      '#description' => $this->t('Enter every channel line by line if you want to publish content, in following format:<br /><br />Name|webmention_url<br />Twitter (bridgy)|https://brid.gy/publish/twitter<br /><br />When you add or remove channels, extra fields will be enabled on the manage display screens of every node type (you will have to clear cache to see them showing up).<br />These need to be added on the page (usually on the "full" view mode) because bridgy will check for the url in the markup. The field will print them hidden in your markup.<br />You can also add them yourself:<br /><div class="indieweb-highlight-code">&lt;a href="https://brid.gy/publish/twitter"&gt;&lt;/a&gt;</div><br />These channels are also used for the syndicate-to request if you are using micropub.')
    ];

    $form['send_wrapper'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Sending publications')
    ];

    $form['send_wrapper']['publish_send_webmention_by'] = [
      '#title' => $this->t('Send publication'),
      '#title_display' => 'invisible',
      '#type' => 'radios',
      '#options' => [
        'disabled' => $this->t('Disabled'),
        'cron' => $this->t('On cron run'),
        'drush' => $this->t('With drush'),
      ],
      '#default_value' => $config->get('publish_send_webmention_by'),
      '#description' => $this->t('Publications are not send immediately, but are stored in a queue when the content is published and when you toggled one or more channels to publish to.<br />The drush command is <strong>indieweb-send-webmentions</strong>')
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

    $this->config('indieweb.publish')
      ->set('channels', $form_state->getValue('channels'))
      ->set('publish_send_webmention_by', $form_state->getValue('publish_send_webmention_by'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
