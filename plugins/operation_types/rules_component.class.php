<?php

/**
 * @file
 * Defines the class for rules components (rule, ruleset, action).
 * Belongs to the "rules_component" operation type plugin.
 */

class ViewsBulkOperationsRulesComponent extends ViewsBulkOperationsBaseOperation {

  /**
   * Contains the provided parameters.
   *
   * @var object
   */
  protected $providedParameters;

  /**
   * Contains the availible options for tokens.
   *
   * @var object
   */
  protected $tokenOptions;

  /**
   * Constructs an operation object.
   *
   * @param int $operation_id
   *   The id of the operation.
   * @param string $entity_type
   *   The entity type that the operation is operating on.
   * @param array $operation_info
   *   An array of information about the operation.
   * @param array $admin_options
   *   An array of options set by the admin.
   * @param string $operation_field
   *   The field the operation is requested from.
   */
  public function __construct($operation_id, $entity_type, array $operation_info, array $admin_options, $operation_field) {
    parent::__construct($operation_id, $entity_type, $operation_info, $admin_options, $operation_field);

    // Get list of the available fields and arguments for token replacement.
    $token_options = array('' => t('None'));
    $count = 0;
    foreach ($operation_field->view->display_handler->get_handlers('argument') as $arg => $handler) {
      $token_options['!' . ++$count] = t('@argument input', array('@argument' => $handler->ui_name()));
    }
    $this->tokenOptions = $token_options;

    // Store this operations provided parameters.
    $this->providedParameters = $this->getAdminOption('parameters', array());
    array_walk($this->providedParameters, function(&$value, $key, $operation_field) {
      $fake_item = array(
        'alter_text' => TRUE,
        'text' => $value,
      );
      $operation_field->view->row_index = 0;
      $tokens = $operation_field->get_render_tokens($fake_item);
      $value = strip_tags($operation_field->render_altered($fake_item, $tokens));
      $value = trim($value);
    }, $operation_field);
    $this->providedParameters = array_filter($this->providedParameters);
  }

  /**
   * Returns the parameters provided for this operation.
   */
  public function getProvidedParameters() {
    return $this->providedParameters;
  }

  /**
   * Returns the available options for adminOptionsForm.
   */
  public function getTokenOptions() {
    return $this->tokenOptions;
  }

  /**
   * Returns the access bitmask for the operation, used for entity access checks.
   *
   * Rules has its own permission system, so the lowest bitmask is enough.
   */
  public function getAccessMask() {
    return VBO_ACCESS_OP_VIEW;
  }

  /**
   * Returns whether the provided account has access to execute the operation.
   *
   * @param $account
   */
  public function access($account) {
    return rules_action('component_' . $this->operationInfo['key'])->access();
  }

  /**
   * Returns the configuration form for the operation.
   * Only called if the operation is declared as configurable.
   *
   * @param $form
   *   The views form.
   * @param $form_state
   *   An array containing the current state of the form.
   * @param $context
   *   An array of related data provided by the caller.
   */
  public function form($form, &$form_state, array $context) {
    $entity_key = $this->operationInfo['parameters']['entity_key'];
    // List types need to match the original, so passing list<node> instead of
    // list<entity> won't work. However, passing 'node' instead of 'entity'
    // will work, and is needed in order to get the right tokens.
    $list_type = 'list<' . $this->operationInfo['type'] . '>';
    $entity_type = $this->aggregate() ? $list_type : $this->entityType;
    $info = entity_get_info($this->entityType);

    // The component action is wrapped in an action set using the entity, so
    // that the action configuration form can make use of the entity e.g. for
    // tokens.
    $set_parameters = array($entity_key => array('type' => $entity_type, 'label' => $info['label']));
    $action_parameters = array($entity_key . ':select' => $entity_key);
    $provided_parameters = array();

    if ($this->getAdminOption('provide_parameters', FALSE)) {
      $provided_parameters = $this->getProvidedParameters();
      foreach (array_keys($provided_parameters) as $provided_parameter) {
        list($provided_parameter_key, $provided_parameter_type) = explode(':', $provided_parameter);
        $set_parameters[$provided_parameter_key] = array('type' => $provided_parameter_type, 'label' => $provided_parameter_key);
        $action_parameters[$provided_parameter_key . ':select'] = $provided_parameter_type;
      }
    }
    $set = rules_action_set($set_parameters);
    $action = rules_action('component_' . $this->operationInfo['key'], $action_parameters);
    $set->action($action);

    // Embed the form of the component action, but default to "input" mode for
    // all parameters if available.
    foreach ($action->parameterInfo() as $name => $info) {
      $form_state['parameter_mode'][$name] = 'input';
    }
    $action->form($form, $form_state);

    // Remove the configuration form element for the "entity" param, as it
    // should just use the passed in entity.
    unset($form['parameter'][$entity_key]);

    // Remove any provided parameters, as they should also use the passed in
    // entities.
    if ($this->getAdminOption('provide_parameters', FALSE)) {
      foreach (array_keys($provided_parameters) as $provided_parameter) {
        list($provided_parameter_key,) = explode(':', $provided_parameter);
        unset($form['parameter'][$provided_parameter_key]);
      }
    }

    // Tweak direct input forms to be more end-user friendly.
    foreach ($action->parameterInfo() as $name => $info) {
      // Remove the fieldset and move its title to the form element.
      if (isset($form['parameter'][$name]['settings'][$name]['#title'])) {
        $form['parameter'][$name]['#type'] = 'container';
        $form['parameter'][$name]['settings'][$name]['#title'] = $form['parameter'][$name]['#title'];
      }
      // Hide the switch button if it's there.
      if (isset($form['parameter'][$name]['switch_button'])) {
        $form['parameter'][$name]['switch_button']['#access'] = FALSE;
      }
    }

    return $form;
  }

  /**
   * Validates the configuration form.
   * Only called if the operation is declared as configurable.
   *
   * @param $form
   *   The views form.
   * @param $form_state
   *   An array containing the current state of the form.
   */
  public function formValidate($form, &$form_state) {
    rules_ui_form_rules_config_validate($form, $form_state);
  }

  /**
   * Stores the rules element added to the form state in form(), so that it
   * can be used in execute().
   * Only called if the operation is declared as configurable.
   *
   * @param $form
   *   The views form.
   * @param $form_state
   *   An array containing the current state of the form.
   */
  public function formSubmit($form, &$form_state) {
    $this->rulesElement = $form_state['rules_element']->root();
  }

  /**
   * Returns the admin options form for the operation.
   *
   * The admin options form is embedded into the VBO field settings and used
   * to configure operation behavior. The options can later be fetched
   * through the getAdminOption() method.
   *
   * @param int $dom_id
   *   The dom path to the level where the admin options form is embedded.
   *   Needed for #dependency.
   * @param string $field_handler
   *   The Views field handler object for the VBO field.
   *
   * @return array
   *   The form array
   */
  public function adminOptionsForm($dom_id, $field_handler) {
    $form = parent::adminOptionsForm($dom_id, $field_handler);

    $entity_key = $this->operationInfo['parameters']['entity_key'];
    $action = rules_action('component_' . $this->operationInfo['key'], array($entity_key . ':select' => $entity_key));

    $parameter_info = $action->parameterInfo();

    if (count($parameter_info) > 0) {

      $provide_parameters = $this->getAdminOption('provide_parameters', FALSE);
      $parameters = $this->getAdminOption('parameters', FALSE);

      $form['provide_parameters'] = array(
        '#type' => 'checkbox',
        '#title' => t('Provide parameters'),
        '#default_value' => $provide_parameters,
        '#dependency' => array(
          $dom_id . '-selected' => array(1),
        ),
        '#dependency_count' => 1,
      );
      $form['parameters'] = array(
        '#type' => 'container',
        '#dependency' => array(
          $dom_id . '-selected' => array(1),
          $dom_id . '-provide-parameters' => array(1),
        ),
        '#dependency_count' => 2,
      );

      $token_options = $this->getTokenOptions();

      foreach ($parameter_info as $name => $info) {
        $form['parameters'][$name . ':' . $info['type']] = array(
          '#type' => 'select',
          '#title' => $name,
          '#options' => $token_options,
          '#default_value' => $parameters[$name . ':' . $info['type']],
          '#dependency' => array(
            $dom_id . '-selected' => array(1),
            $dom_id . '-provide-parameters' => array(1),
          ),
          '#dependency_count' => 2,
        );
      }
    }

    return $form;
  }

  /**
   * Executes the selected operation on the provided data.
   *
   * @param $data
   *   The data to to operate on. An entity or an array of entities.
   * @param $context
   *   An array of related data (selected views rows, etc).
   */
  public function execute($data, array $context) {
    // If there was a config form, there's a rules_element.
    // If not, fallback to the component key.
    if ($this->configurable()) {
      $element = $this->rulesElement;
    }
    else {
     $element = rules_action('component_' . $this->operationInfo['parameters']['component_key']);
    }
    $wrapper_type = is_array($data) ? "list<{$this->entityType}>" : $this->entityType;
    $wrapper = entity_metadata_wrapper($wrapper_type, $data);

    if (array_key_exists('parameters', $context) && is_array($context['parameters'])) {
      $element->executeByArgs(array_merge(array($wrapper), $context['parameters']));
    }
    else {
      $element->execute($wrapper);
    }
  }
  
  public function configurable() {
    if ($this->getAdminOption('provide_parameters', FALSE)) {
      $action = rules_action('component_' . $this->operationInfo['key']);

      $parameterInfo = $action->parameterInfo();
      $parameterInfo = array_splice($parameterInfo, 1);

      $primitiveParameters = array_filter($parameterInfo, function($value) {
        return in_array($value['type'], array('decimal', 'integer', 'text'));
      });

      $providedParameters = $this->getProvidedParameters();

      if (count($parameterInfo) > 0
        && (
          (
            count($parameterInfo) <= count($primitiveParameters)
            &&
            count($primitiveParameters) <= count(array_filter($providedParameters))
          )
          ||
          (count($primitiveParameters) == 0)
        )

      ) {
        return FALSE;
      }
    }
    return parent::configurable();
  }
}
