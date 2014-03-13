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
   * @param $operationId
   *   The id of the operation.
   * @param $entityType
   *   The entity type that the operation is operating on.
   * @param $operationInfo
   *   An array of information about the operation.
   * @param $adminOptions
   *   An array of options set by the admin.
   * @param $adminOptions
   *   An array of options set by the admin.
   * @param $operationField
   *   The field the operation is requested from
   */
  public function __construct($operationId, $entityType, array $operationInfo, array $adminOptions, $operationsField) {
    parent::__construct($operationId, $entityType, $operationInfo, $adminOptions, $operationsField);

    // Get list of the available fields and arguments for token replacement.
    $tokenOptions = array('' => t('None'));
    $count = 0; // This lets us prepare the key as we want it printed.
    foreach ($operationsField->view->display_handler->get_handlers('argument') as $arg => $handler) {
      $tokenOptions['!' . ++$count] = t('@argument input', array('@argument' => $handler->ui_name()));
    }
    $this->tokenOptions = $tokenOptions;

    // Store this operations provided parameters
    $this->providedParameters = $this->getAdminOption('parameters', array());
    array_walk($this->providedParameters, function(&$value, $key, $operationsField) {
      $fake_item = array(
        'alter_text' => TRUE,
        'text' => $value,
      );
      $operationsField->view->row_index = 0;
      $tokens = $operationsField->get_render_tokens($fake_item);
      $value = strip_tags($operationsField->render_altered($fake_item, $tokens));
      $value = trim($value);
    }, $operationsField);
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
   * Returns whether the operation is configurable. Used to determine
   * whether the operation's form methods should be invoked.
   */
  public function configurable() {
    if ($this->getAdminOption('provide_parameters', FALSE)) {
      $action = rules_action('component_' . $this->operationInfo['key']);

      $parameterInfo = $action->parameterInfo();
      $parameterInfo = array_splice($parameterInfo, 1);

      $primitiveParameters = array_filter($parameterInfo, function($value) {
        return in_array($value['type'], array('decimal', 'integer', 'text'));
      });

      $providedParameters = $this->getProvidedParameters();
      if (count($parameterInfo) > 0 && count($parameterInfo) <= count($primitiveParameters) &&
          count($primitiveParameters) <= count(array_filter($providedParameters))) {
        return FALSE;
      }
    }
    return parent::configurable();
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
    if ($this->getAdminOption('provide_parameters', FALSE)) {
      $providedParameters = $this->getProvidedParameters();
      foreach ($providedParameters as $providedParameter => $providedParameterValue) {
        list($providedParameterKey, $providedParameterType) = explode(':', $providedParameter);
        $set_parameters[$providedParameterKey] = array('type' => $providedParameterType, 'label' => $providedParameterKey);
        $action_parameters[$providedParameterKey . ':select'] = $providedParameterKey;
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

    // Remove any provided parameters, as they should also use the passed in entities
    if ($this->getAdminOption('provide_parameters', FALSE)) {
      foreach ($providedParameters as $providedParameter => $providedParameterValue) {
        list($providedParameterKey, $providedParameterType) = explode(':', $providedParameter);
        unset($form['parameter'][$providedParameterKey]);
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
   * @param $dom_id
   *   The dom path to the level where the admin options form is embedded.
   *   Needed for #dependency.
   * @param $field_handler
   *   The Views field handler object for the VBO field.
   */
  public function adminOptionsForm($dom_id, $field_handler) {
    $form = parent::adminOptionsForm($dom_id, $field_handler);

    $entity_key = $this->operationInfo['parameters']['entity_key'];
    $entity_type = $this->entityType;
    $info = entity_get_info($this->entityType);

    $action = rules_action('component_' . $this->operationInfo['key'], array($entity_key . ':select' => $entity_key));

    $parameterInfo = $action->parameterInfo();
    $primitiveParameters = array_filter($parameterInfo, function($value) {
      return in_array($value['type'], array('decimal', 'integer', 'text'));
    });

    if (count($parameterInfo) > 0 && count($parameterInfo) == count($primitiveParameters)) {

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

      $tokenOptions = $this->getTokenOptions();

      foreach ($parameterInfo as $name => $info) {
        $form['parameters'][$name . ':' . $info['type']] = array(
          '#type' => 'select',
          '#title' => $name,
          '#options' => $tokenOptions,
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
    if (array_key_exists('parameters', $context) && is_array($context['parameters'])) {
      $element->executeByArgs(array_merge(is_array($data) ? $data : array($data), $context['parameters']));
    }
    else {
      $element->execute($data);
    }
  }
}
