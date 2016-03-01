<?php

/**
 * @file
 * Implements a generic entity change author action.
 */

function views_bulk_operations_change_author_action_info() {
  return array(
    'views_bulk_operations_change_author_action' => array(
      'type' => 'entity',
      'label' => t('Change author'),
      'configurable' => TRUE,
      'behavior' => array('changes_property'),
      'triggers' => array('any'),
    ),
  );
}

/**
 * Action function.
 */
function views_bulk_operations_change_author_action($entity, $context) {
  $entity->uid = $context['author_uid'];
}

/**
 * Action form function.
 */
function views_bulk_operations_change_author_action_form($context, &$form_state) {
  $form['author_username'] = array(
    '#type' => 'textfield',
    '#maxlength' => USERNAME_MAX_LENGTH,
    '#title' => t('Author'),
    '#required' => TRUE,
    '#description' => t('Choose the user you would like to set as the author.'),
    '#autocomplete_path' => 'user/autocomplete',
  );
  return $form;
}

/**
 * Action form validate function.
 *
 * Checks that the submitted text is a valid username.
 */
function views_bulk_operations_change_author_action_validate($form, $form_state) {
  if (!user_load_by_name($form_state['values']['author_username'])) {
    form_set_error('author_username', t('Valid username required.'));
  }
}

/**
 * Action form submit function.
 *
 * Pass submitted username back to views_bulk_operations_change_author
 */
function views_bulk_operations_change_author_action_submit($form, $form_state) {
  $user = user_load_by_name($form_state['values']['author_username']);
  return array('author_uid' => $user->uid);
}