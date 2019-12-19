<?php

namespace Drupal\entity_reference_layout\Plugin\Field\FieldWidget;

use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\Layout\LayoutPluginManager;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\Renderer;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Plugin\PluginFormInterface;
use Drupal\Core\Plugin\PluginWithFormsInterface;
use Drupal\Core\Layout\LayoutInterface;
use Drupal\Core\Form\SubformState;
use Drupal\Component\Utility\NestedArray;
use Drupal\Component\Utility\Html;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManager;
use Drupal\Core\Field\FieldFilteredMarkup;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Entity Reference with Layout field widget.
 *
 * @FieldWidget(
 *   id = "entity_reference_layout_widget",
 *   label = @Translation("Entity reference layout (With layout builder)"),
 *   description = @Translation("Layout builder for paragraphs."),
 *   field_types = {
 *     "entity_reference_layout_revisioned"
 *   },
 * )
 */
class EntityReferenceLayoutWidget extends WidgetBase implements ContainerFactoryPluginInterface {

  /**
   * The Renderer service property.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * The Entity Type Manager service property.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The Layouts Manager.
   *
   * @var \Drupal\Core\Layout\LayoutPluginManager
   */
  protected $layoutPluginManager;

  /**
   * The entity that contains this field.
   *
   * @var \Drupal\Core\Entity\Entity
   */
  protected $host;

  /**
   * The name of the field.
   *
   * @var string
   */
  protected $fieldName;

  /**
   * The Html Id of the wrapper element.
   *
   * @var string
   */
  protected $wrapperId;

  /**
   * The Html Id of the item form wrapper element.
   *
   * @var string
   */
  protected $itemFormWrapperId;

  /**
   * The language manager.
   *
   * @var \Drupal\Core\Language\LanguageManager
   */
  protected $languageManager;

  /**
   * Entity type bundle info.
   *
   * @var \Drupal\Core\Entity\EntityTypeBundleInfo
   */
  protected $entityTypeBundleInfo;

  /**
   * Current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * Constructs a WidgetBase object.
   *
   * @param string $plugin_id
   *   The plugin_id for the widget.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   The definition of the field to which the widget is associated.
   * @param array $settings
   *   The widget settings.
   * @param array $third_party_settings
   *   Any third party settings.
   * @param \Drupal\Core\Render\Renderer $renderer
   *   Core renderer service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   Core entity type manager service.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $entity_type_bundle_info
   *   The entity type bundle info.
   * @param \Drupal\Core\Layout\LayoutPluginManager $layout_plugin_manager
   *   Core layout plugin manager service.
   * @param \Drupal\Core\Language\LanguageManager $language_manager
   *   Core language manager service.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   */
  public function __construct(
    $plugin_id,
    $plugin_definition,
    FieldDefinitionInterface $field_definition,
    array $settings,
    array $third_party_settings,
    Renderer $renderer,
    EntityTypeManagerInterface $entity_type_manager,
    EntityTypeBundleInfoInterface $entity_type_bundle_info,
    LayoutPluginManager $layout_plugin_manager,
    LanguageManager $language_manager,
    AccountProxyInterface $current_user) {

    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $third_party_settings);

    $this->renderer = $renderer;
    $this->entityTypeManager = $entity_type_manager;
    $this->entityTypeBundleInfo = $entity_type_bundle_info;
    $this->layoutPluginManager = $layout_plugin_manager;
    $this->fieldName = $this->fieldDefinition->getName();
    $this->languageManager = $language_manager;
    $this->currentUser = $current_user;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['third_party_settings'],
      $container->get('renderer'),
      $container->get('entity_type.manager'),
      $container->get('entity_type.bundle.info'),
      $container->get('plugin.manager.core.layout'),
      $container->get('language_manager'),
      $container->get('current_user')
    );
  }

  /**
   * Builds the main widget form array container/wrapper.
   *
   * Form elements for individual items are built by formElement().
   */
  protected function formMultipleElements(FieldItemListInterface $items, array &$form, FormStateInterface $form_state) {

    $parents = $form['#parents'];
    $widget_state = static::getWidgetState($parents, $this->fieldName, $form_state);

    $this->wrapperId = Html::getId(implode('-', $parents) . $this->fieldName . '-wrapper');
    $this->itemFormWrapperId = Html::getId(implode('-', $parents) . $this->fieldName . '-form');

    $handler_settings = $items->getSetting('handler_settings');
    $layout_bundles = $handler_settings['layout_bundles'] ?? [];
    $target_bundles = $handler_settings['target_bundles'] ?? [];

    if (!empty($handler_settings['negate'])) {
      $target_bundles_options = array_keys($handler_settings['target_bundles_drag_drop']);
      $target_bundles = array_diff($target_bundles_options, $target_bundles);
    }
    $title = $this->fieldDefinition->getLabel();
    $description = FieldFilteredMarkup::create(\Drupal::token()->replace($this->fieldDefinition->getDescription()));

    // Save items to widget state when the form first loads.
    if (empty($widget_state['items'])) {
      $widget_state['items'] = [];
      foreach ($items as $delta => $item) {
        $widget_state['items'][$delta] = [
          'entity' => $item->entity,
          'layout' => $item->layout,
          'config' => $item->config,
          'options' => $item->options,
          'new_region' => NULL,
          'parent_weight' => NULL,
        ];
      }
    }
    // Handle asymmetric translation if field is translatable
    // by duplicating items for enabled languages.
    if ($items->getFieldDefinition()->isTranslatable()) {
      $langcode = $this->languageManager
        ->getCurrentLanguage(LanguageInterface::TYPE_CONTENT)
        ->getId();

      foreach ($widget_state['items'] as $delta => $item) {
        if (empty($item['entity']) || $item['entity']->get('langcode')->value == $langcode) {
          continue;
        }
        $duplicate = $item['entity']->createDuplicate();
        $duplicate->set('langcode', $langcode);
        $widget_state['items'][$delta]['entity'] = $duplicate;
      }
    }
    static::setWidgetState($parents, $this->fieldName, $form_state, $widget_state);

    $elements = parent::formMultipleElements($items, $form, $form_state);
    $elements += [
      '#title' => $title,
      '#description' => $description,
    ];
    $elements['#theme'] = 'entity_reference_layout_widget';
    $elements['#id'] = $this->wrapperId;

    // Button to add new section and other paragraphs.
    $elements['add_more'] = [
      '#attributes' => ['class' => ['js-hide']],
      '#type' => 'container',
    ];
    $bundle_info = $this->entityTypeBundleInfo->getBundleInfo('paragraph');
    foreach ($layout_bundles as $bundle_id) {
      $elements['add_more']['section'] = [
        '#type' => 'submit',
        '#bundle_id' => $bundle_id,
        '#host' => $items->getEntity(),
        '#value' => $this->t('Add @label', ['@label' => $bundle_info[$bundle_id]['label']]),
        '#modal_label' => $this->t('Add new @label', ['@label' => $bundle_info[$bundle_id]['label']]),
        '#name' => implode('_', $parents) . '_add_' . $bundle_id,
        '#submit' => [
          [$this, 'newItemSubmit'],
        ],
        '#attributes' => ['class' => ['erl-add-section']],
        '#limit_validation_errors' => [array_merge($parents, [$this->fieldName])],
        '#ajax' => [
          'callback' => [$this, 'elementAjax'],
          'wrapper' => $this->wrapperId,
        ],
        '#element_parents' => $parents,
      ];
    }

    // Add other paragraph types.
    $options = [];
    $bundle_ids = array_diff($target_bundles, $layout_bundles);
    $target_type = $items->getSetting('target_type');
    $definition = $this->entityTypeManager->getDefinition($target_type);
    $storage = $this->entityTypeManager->getStorage($definition->getBundleEntityType());
    foreach ($bundle_ids as $bundle_id) {
      $type = $storage->load($bundle_id);
      // Get the icon and pass to Javascript.
      if (method_exists($type, 'getIconFile')) {
        if ($icon = $type->getIconFile()) {
          $path = $icon->url();
          $elements['#attached']['drupalSettings']['erlIcons']['icon_' . $bundle_id] = $path;
        }
      }
      $options[$bundle_id] = $bundle_info[$bundle_id]['label'];
    }
    $elements['add_more']['type'] = [
      '#title' => $this->t('Choose type'),
      '#type' => 'select',
      '#options' => $options,
      '#attributes' => ['class' => ['erl-item-type']],
    ];
    $elements['add_more']['item'] = [
      '#type' => 'submit',
      '#host' => $items->getEntity(),
      '#value' => $this->t('Create New'),
      '#submit' => [[$this, 'newItemSubmit']],
      '#limit_validation_errors' => [array_merge($parents, [$this->fieldName])],
      '#attributes' => ['class' => ['erl-add-item']],
      '#ajax' => [
        'callback' => [$this, 'elementAjax'],
        'wrapper' => $this->wrapperId,
      ],
      '#name' => implode('_', $parents) . '_add_item',
      '#element_parents' => $parents,
    ];
    // Add region and parent_delta hidden items only in this is a new entity.
    // Prefix with underscore to prevent namespace collisions.
    $elements['add_more']['_region'] = [
      '#type' => 'hidden',
      '#attributes' => ['class' => ['erl-new-item-region']],
    ];
    $elements['add_more']['_parent_weight'] = [
      '#type' => 'hidden',
      '#attributes' => ['class' => ['erl-new-item-parent']],
    ];
    return $elements;
  }

  /**
   * Builds the widget form array for an individual item.
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {

    $parents = $form['#parents'];
    $widget_state = static::getWidgetState($parents, $this->fieldName, $form_state);
    $handler_settings = $items->getSetting('handler_settings');
    $layout_bundles = $handler_settings['layout_bundles'] ?? [];

    if (empty($widget_state['items'][$delta]['entity'])) {
      return [];
    }

    // Flatten layouts array for use with radio buttons.
    $available_layouts = [];
    foreach ($handler_settings['allowed_layouts'] as $group) {
      foreach ($group as $layout_id => $layout_name) {
        $available_layouts[$layout_id] = $layout_name;
      }
    }

    $entity = !empty($widget_state['items'][$delta]) ? $widget_state['items'][$delta]['entity'] : NULL;
    $options = !empty($widget_state['items'][$delta]['options']) ? $widget_state['items'][$delta]['options'] : [];
    $config = !empty($widget_state['items'][$delta]['config']) ? $widget_state['items'][$delta]['config'] : [];
    $layout_path = array_merge($parents, [
      $this->fieldName,
      $delta,
      'entity_form',
      'layout_selection',
      'layout',
    ]);
    if (!$layout = $form_state->getValue($layout_path)) {
      $layout = !empty($widget_state['items'][$delta]['layout']) ? $widget_state['items'][$delta]['layout'] : NULL;
    }

    $element = [
      '#type' => 'container',
      '#delta' => $delta,
      '#entity' => $entity,
      '#layout' => !empty($items[$delta]->layout) ? $items[$delta]->layout : '',
      '#region' => !empty($items[$delta]->region) ? $items[$delta]->region : '',
      '#layout_options' => $items[$delta]->options ?? [],
      '#attributes' => [
        'class' => [
          'erl-item',
        ],
        'id' => [
          $this->fieldName . '--item-' . $delta,
        ],
      ],
      'region' => [
        '#type' => 'hidden',
        '#attributes' => ['class' => ['erl-region']],
        '#default_value' => !empty($items[$delta]->region) ? $items[$delta]->region : '',
      ],

      // Edit and remove button.
      'actions' => [
        '#type' => 'container',
        '#weight' => -1000,
        '#attributes' => ['class' => ['erl-actions']],
        'edit' => [
          '#type' => 'submit',
          '#name' => 'edit_' . $this->fieldName . '_' . $delta,
          '#value' => $this->t('Edit'),
          '#attributes' => ['class' => ['erl-edit']],
          '#limit_validation_errors' => [array_merge($parents, [$this->fieldName])],
          '#submit' => [[$this, 'editItemSubmit']],
          '#delta' => $delta,
          '#ajax' => [
            'callback' => [$this, 'elementAjax'],
            'wrapper' => $this->wrapperId,
          ],
          '#element_parents' => $parents,
        ],
        'remove' => [
          '#type' => 'submit',
          '#name' => 'remove_' . $this->fieldName . '_' . $delta,
          '#value' => $this->t('Remove'),
          '#attributes' => ['class' => ['erl-remove']],
          '#limit_validation_errors' => [array_merge($parents, [$this->fieldName])],
          '#submit' => [[$this, 'removeItemSubmit']],
          '#delta' => $delta,
          '#ajax' => [
            'callback' => [$this, 'elementAjax'],
            'wrapper' => $this->wrapperId,
          ],
          '#element_parents' => $parents,
        ],
      ],

      // These properties aren't modified by the main form,
      // but are modified when a user edits a specific item.
      'entity' => [
        '#type' => 'value',
        '#value' => $entity,
      ],
      'config' => [
        '#type' => 'value',
        '#value' => $config,
      ],
      'options' => [
        '#type' => 'value',
        '#value' => $options,
      ],
      'layout' => [
        '#type' => 'value',
        '#value' => $layout,
      ],
      '#process' => [],
    ];

    // If this is a new entity, pass the region and parent
    // item's weight to the theme.
    if (!empty($widget_state['items'][$delta]['is_new'])) {
      $element['#is_new'] = TRUE;
      $element['#new_region'] = $widget_state['items'][$delta]['new_region'];
      $element['#parent_weight'] = $widget_state['items'][$delta]['parent_weight'];
      $element['#attributes']['class'][] = 'js-hide';
    }

    // Build the preview and render it in the form.
    $preview = [];
    if (isset($entity)) {
      $view_builder = $this->entityTypeManager->getViewBuilder($entity->getEntityTypeId());
      $preview = $view_builder->view($entity);
      $preview['#cache']['max-age'] = 0;
    }
    $element += [
      'preview' => $preview,
    ];

    // Add remove confirmation form if we're removing.
    if (isset($widget_state['remove_item']) && $widget_state['remove_item'] === $delta) {
      $element['remove_form'] = [
        '#prefix' => '<div class="erl-form">',
        '#suffix' => '</div>',
        '#type' => 'container',
        '#attributes' => ['data-dialog-title' => [$this->t('Confirm removal')]],
        'message' => [
          '#type' => 'markup',
          '#markup' => $this->t('Are you sure you want to permanently remove this <b>@type?</b><br />This action cannot be undone.', ['@type' => $entity->type->entity->label()]),
        ],
        'actions' => [
          '#type' => 'container',
          'confirm' => [
            '#type' => 'submit',
            '#value' => $this->t('Remove'),
            '#delta' => $delta,
            '#submit' => [[$this, 'removeItemConfirmSubmit']],
            '#limit_validation_errors' => [array_merge($parents, [$this->fieldName])],
            '#ajax' => [
              'callback' => [$this, 'elementAjax'],
              'wrapper' => $this->wrapperId,
            ],
            '#element_parents' => $parents,
          ],
          'cancel' => [
            '#type' => 'submit',
            '#value' => $this->t('Cancel'),
            '#delta' => $delta,
            '#submit' => [[$this, 'removeItemCancelSubmit']],
            '#limit_validation_errors' => [array_merge($parents, [$this->fieldName])],
            '#attributes' => [
              'class' => ['erl-cancel'],
            ],
            '#ajax' => [
              'callback' => [$this, 'elementAjax'],
              'wrapper' => $this->wrapperId,
            ],
            '#element_parents' => $parents,
          ],
        ],
        '#weight' => 1000,
        '#delta' => $delta,
      ];
    }

    // Add edit form if open.
    if (isset($widget_state['open_form']) && $widget_state['open_form'] === $delta) {
      $display = EntityFormDisplay::collectRenderDisplay($entity, 'default');
      $bundle_label = $entity->type->entity->label();
      $element['entity_form'] = [
        '#prefix' => '<div class="erl-form">',
        '#suffix' => '</div>',
        '#type' => 'container',
        '#parents' => array_merge($parents, [
          $this->fieldName,
          $delta,
          'entity_form',
        ]),
        '#weight' => 1000,
        '#delta' => $delta,
        '#display' => $display,
        '#attributes' => [
          'data-dialog-title' => [
            $entity->id() ? $this->t('Edit @type', ['@type' => $bundle_label]) : $this->t('Create new @type', ['@type' => $bundle_label]),
          ],
        ],
      ];

      // Support for Field Group module based on Paragraphs module.
      // @todo Remove as part of https://www.drupal.org/node/2640056
      if (\Drupal::moduleHandler()->moduleExists('field_group')) {
        $context = [
          'entity_type' => $entity->getEntityTypeId(),
          'bundle' => $entity->bundle(),
          'entity' => $entity,
          'context' => 'form',
          'display_context' => 'form',
          'mode' => $display->getMode(),
        ];

        field_group_attach_groups($element['entity_form'], $context);
        if (function_exists('field_group_form_pre_render')) {
          $element['entity_form']['#pre_render'][] = 'field_group_form_pre_render';
        }
        if (function_exists('field_group_form_process')) {
          $element['entity_form']['#process'][] = 'field_group_form_process';
        }
      }

      $display->buildForm($entity, $element['entity_form'], $form_state);

      // Add the layout plugin form if applicable.
      if (in_array($entity->bundle(), $layout_bundles)) {
        $element['entity_form']['layout_selection'] = [
          '#type' => 'container',
          'layout' => [
            '#type' => 'radios',
            '#title' => $this->t('Select a layout:'),
            '#options' => $available_layouts,
            '#default_value' => $layout,
            '#attributes' => [
              'class' => ['erl-layout-select'],
            ],
            '#required' => TRUE,
            '#after_build' => [[get_class($this), 'processLayoutOptions']],
          ],
          'update' => [
            '#type' => 'submit',
            '#value' => $this->t('Update'),
            '#name' => 'update_layout',
            '#delta' => $element['#delta'],
            '#limit_validation_errors' => [
              array_merge($parents, [
                $this->fieldName,
                $delta,
                'entity_form',
                'layout_selection',
              ]),
            ],
            '#submit' => [
              [$this, 'editItemSubmit'],
            ],
            '#attributes' => [
              'class' => ['js-hide'],
            ],
            '#element_parents' => $parents,
          ],
        ];
        // Switching layouts should change the layout plugin options form
        // with Ajax for users with adequate permissions.
        if ($this->currentUser->hasPermission('edit entity reference layout plugin config')) {
          $element['entity_form']['layout_selection']['layout']['#ajax'] = [
            'event' => 'change',
            'callback' => [$this, 'buildLayoutConfigurationFormAjax'],
            'trigger_as' => ['name' => 'update_layout'],
            'wrapper' => 'layout-config',
          ];
          $element['entity_form']['layout_selection']['update']['#ajax'] = [
            'callback' => [$this, 'buildLayoutConfigurationFormAjax'],
            'wrapper' => 'layout-config',
          ];
        }
        $element['entity_form']['layout_plugin_form'] = [
          '#prefix' => '<div id="layout-config">',
          '#suffix' => '</div>',
          '#access' => $this->currentUser->hasPermission('edit entity reference layout plugin config'),
        ];
        // Add the layout configuration form if applicable.
        if (!empty($layout)) {
          $layout_instance = $this->layoutPluginManager->createInstance($layout, $config);
          if ($layout_plugin = $this->getLayoutPluginForm($layout_instance)) {
            $element['entity_form']['layout_plugin_form'] += [
              '#type' => 'details',
              '#title' => $this->t('Layout Configuration'),
            ];
            $element['entity_form']['layout_plugin_form'] += $layout_plugin->buildConfigurationForm([], $form_state);
          }
        }
        // Add the additional options form if applicable.
        // This is deprecated and included only for backwards compatibility.
        if ($this->getSetting('always_show_options_form')) {
          // Other layout options.
          $element['entity_form']['options'] = [
            '#type' => 'details',
            '#title' => $this->t('Basic Layout Options'),
            '#description' => $this->t('Classes will be applied to the container for this field item.'),
            '#open' => FALSE,
          ];
          $element['entity_form']['options']['container_classes'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Custom Classes for Layout Container'),
            '#description' => $this->t('Classes will be applied to the container for this field item.'),
            '#size' => 50,
            '#default_value' => $options['options']['container_classes'] ?? '',
            '#placeholder' => $this->t('CSS Classes'),
          ];
          $element['entity_form']['options']['bg_color'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Background Color for Layout Container'),
            '#description' => $this->t('Background will be applied to the layout container.'),
            '#size' => 10,
            '#default_value' => $options['options']['bg_color'] ?? '',
            '#placeholder' => $this->t('Hex Code'),
          ];
        }
      }
      // Add save, cancel, etc.
      $element['entity_form'] += [
        'actions' => [
          '#weight' => 1000,
          '#type' => 'container',
          '#attributes' => ['class' => ['erl-item-form-actions']],
          'save_item' => [
            '#type' => 'submit',
            '#name' => 'save',
            '#value' => $this->t('Save'),
            '#delta' => $element['#delta'],
            '#limit_validation_errors' => [array_merge($parents, [$this->fieldName])],
            '#submit' => [
              [$this, 'saveItemSubmit'],
            ],
            '#ajax' => [
              'callback' => [$this, 'elementAjax'],
              'wrapper' => $this->wrapperId,
            ],
            '#element_parents' => $parents,
          ],
          'cancel' => [
            '#type' => 'submit',
            '#name' => 'cancel',
            '#value' => $this->t('Cancel'),
            '#limit_validation_errors' => [],
            '#delta' => $element['#delta'],
            '#submit' => [
              [$this, 'cancelItemSubmit'],
            ],
            '#attributes' => [
              'class' => ['erl-cancel'],
            ],
            '#ajax' => [
              'callback' => [$this, 'elementAjax'],
              'wrapper' => $this->wrapperId,
            ],
            '#element_parents' => $parents,
          ],
        ],
      ];
    }
    return $element;
  }

  /**
   * Add theme wrappers to layout selection radios.
   *
   * Theme function injects layout icons into radio buttons.
   */
  public static function processLayoutOptions($element) {
    foreach (Element::children($element) as $radio_item) {
      $element[$radio_item]['#theme_wrappers'][] = 'entity_reference_layout_radio';
    }
    return $element;
  }

  /**
   * Form submit handler - adds a new item and opens its edit form.
   */
  public function newItemSubmit(array $form, FormStateInterface $form_state) {

    $element = $form_state->getTriggeringElement();
    $parents = $element['#element_parents'];
    $widget_state = static::getWidgetState($parents, $this->fieldName, $form_state);

    if (!empty($element['#bundle_id'])) {
      $bundle_id = $element['#bundle_id'];
    }
    else {
      $element_parents = $element['#parents'];
      array_splice($element_parents, -1, 1, 'type');
      $bundle_id = $form_state->getValue($element_parents);
    }

    $entity_type = $this->entityTypeManager->getDefinition('paragraph');
    $bundle_key = $entity_type->getKey('bundle');

    $paragraphs_entity = $this->entityTypeManager->getStorage('paragraph')->create([
      $bundle_key => $bundle_id,
    ]);
    $paragraphs_entity->setParentEntity($element['#host'], $this->fieldDefinition->getName());

    $path = array_merge($parents, [$this->fieldDefinition->getName(), 'add_more']);
    $new_region = $form_state->getValue(array_merge($path, ['_region']));
    $parent_weight = intval($form_state->getValue(array_merge($path, ['_parent_weight'])));

    $widget_state['items'][] = [
      'entity' => $paragraphs_entity,
      'is_new' => TRUE,
      'new_region' => $new_region,
      'parent_weight' => $parent_weight,
    ];
    $widget_state['open_form'] = $widget_state['items_count'];
    $widget_state['items_count']++;

    static::setWidgetState($parents, $this->fieldName, $form_state, $widget_state);
    $form_state->setRebuild();
  }

  /**
   * Form submit handler - opens the edit form for an existing item.
   */
  public function editItemSubmit($form, $form_state) {

    $element = $form_state->getTriggeringElement();
    $parents = $element['#element_parents'];
    $delta = $element['#delta'];

    $widget_state = static::getWidgetState($parents, $this->fieldName, $form_state);
    $widget_state['open_form'] = $delta;

    static::setWidgetState($parents, $this->fieldName, $form_state, $widget_state);
    $form_state->setRebuild();
  }

  /**
   * Form submit handler - opens confirm removal form for an item.
   */
  public function removeItemSubmit($form, $form_state) {

    $element = $form_state->getTriggeringElement();
    $parents = $element['#element_parents'];
    $delta = $element['#delta'];

    $widget_state = static::getWidgetState($parents, $this->fieldName, $form_state);
    $widget_state['remove_item'] = $delta;

    static::setWidgetState($parents, $this->fieldName, $form_state, $widget_state);
    $form_state->setRebuild();
  }

  /**
   * Form submit handler - removes/deletes an item.
   */
  public function removeItemConfirmSubmit($form, $form_state) {

    $element = $form_state->getTriggeringElement();
    $parents = $element['#element_parents'];
    $delta = $element['#delta'];

    $widget_state = static::getWidgetState($parents, $this->fieldName, $form_state);

    unset($widget_state['items'][$delta]['entity']);
    unset($widget_state['remove_item']);

    static::setWidgetState($parents, $this->fieldName, $form_state, $widget_state);
    $form_state->setRebuild();
  }

  /**
   * Form submit handler - cancels item removal and closes confirmation form.
   */
  public function removeItemCancelSubmit($form, $form_state) {

    $element = $form_state->getTriggeringElement();
    $parents = $element['#element_parents'];

    $widget_state = static::getWidgetState($parents, $this->fieldName, $form_state);

    unset($widget_state['remove_item']);

    static::setWidgetState($parents, $this->fieldName, $form_state, $widget_state);
    $form_state->setRebuild();
  }

  /**
   * Form submit handler - saves an item.
   */
  public function saveItemSubmit($form, $form_state) {

    $element = $form_state->getTriggeringElement();
    $parents = $element['#element_parents'];
    $delta = $element['#delta'];
    $element_array_parents = $element['#array_parents'];
    $item_array_parents = array_splice($element_array_parents, 0, -2);

    $item_form = NestedArray::getValue($form, $item_array_parents);
    $display = $item_form['#display'];
    $widget_state = static::getWidgetState($parents, $this->fieldName, $form_state);

    // Remove is_new flag since we're saving the entity.
    unset($widget_state['items'][$delta]['is_new']);
    // Save field values to entity.
    $display->extractFormValues($widget_state['items'][$delta]['entity'], $item_form, $form_state);

    // Save layout settings.
    if (!empty($item_form['layout_selection']['layout'])) {

      $layout = $form_state->getValue($item_form['layout_selection']['layout']['#parents']);
      $widget_state['items'][$delta]['layout'] = $layout;

      // Save layout config:
      if (!empty($item_form['layout_plugin_form'])) {
        $layout_instance = $this->layoutPluginManager->createInstance($layout);
        if ($this->getLayoutPluginForm($layout_instance)) {
          $subform_state = SubformState::createForSubform($item_form['layout_plugin_form'], $form_state->getCompleteForm(), $form_state);
          $layout_instance->submitConfigurationForm($item_form['layout_plugin_form'], $subform_state);
          $layout_config = $layout_instance->getConfiguration();
          $widget_state['items'][$delta]['config'] = $layout_config;
        }
      }
    }

    // Save layout options (deprecated).
    if (!empty($item_form['options'])) {
      $options_path = array_merge($parents, [
        $this->fieldName,
        $delta,
        'entity_form',
        'options',
      ]);
      $widget_state['items'][$delta]['options']['options'] = $form_state->getValue($options_path);
    }

    // Close the entity form.
    $widget_state['open_form'] = FALSE;

    static::setWidgetState($parents, $this->fieldName, $form_state, $widget_state);
    $form_state->setRebuild();
  }

  /**
   * Form submit handler - cancels editing an item and closes form.
   */
  public function cancelItemSubmit($form, $form_state) {

    $element = $form_state->getTriggeringElement();
    $parents = $element['#element_parents'];
    $delta = $element['#delta'];
    $widget_state = static::getWidgetState($parents, $this->fieldName, $form_state);

    // If canceling an item that hasn't been created yet, remove it.
    if (!empty($widget_state['items'][$delta]['is_new'])) {
      array_splice($widget_state['items'], $delta, 1);
      $widget_state['items_count'] = count($widget_state['items']);
    }
    $widget_state['open_form'] = FALSE;
    static::setWidgetState($parents, $this->fieldName, $form_state, $widget_state);
    $form_state->setRebuild();
  }

  /**
   * Ajax callback to return the entire ERL element.
   */
  public function elementAjax(array $form, FormStateInterface $form_state) {
    $element = $form_state->getTriggeringElement();
    $parents = $element['#element_parents'];
    $field_state = static::getWidgetState($parents, $this->fieldName, $form_state);
    return NestedArray::getValue($form, $field_state['array_parents']);
  }

  /**
   * Ajax callback to return a layout plugin configuration form.
   */
  public function buildLayoutConfigurationFormAjax(array $form, FormStateInterface $form_state) {

    $element = $form_state->getTriggeringElement();
    $parents = $element['#array_parents'];
    $parents = array_splice($parents, 0, -2);
    $parents = array_merge($parents, ['layout_plugin_form']);
    if ($layout_plugin_form = NestedArray::getValue($form, $parents)) {
      return $layout_plugin_form;
    }
    else {
      return [];
    }
  }

  /**
   * Field instance settings form.
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $form['always_show_options_form'] = [
      '#type' => 'checkbox',
      '#default_value' => $this->getSetting('always_show_options_form'),
      '#title' => $this->t('Always show layout options form'),
      '#description' => $this->t('Show options for additional classes and background color when adding or editing layouts, even if a layout plugin form exists. The preferred method is to rely on Layout Plugin configuration forms.'),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = [];
    if ($this->getSetting('always_show_options_form')) {
      $summary[] = t('Layout configuration: Show extra options form (deprecated).');
    }
    else {
      $summary[] = t('Layout configuration: Rely on Layout Plugins (preferred).');
    }
    return $summary;
  }

  /**
   * Default settings for widget.
   */
  public static function defaultSettings() {
    $defaults = parent::defaultSettings();
    $defaults += [
      'always_show_options_form' => FALSE,
    ];

    return $defaults;
  }

  /**
   * {@inheritdoc}
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state) {
    foreach ($values as $delta => $value) {
      unset($values[$delta]['actions']);
    }
    return $values;
  }

  /**
   * Retrieves the plugin form for a given layout.
   *
   * @param \Drupal\Core\Layout\LayoutInterface $layout
   *   The layout plugin.
   *
   * @return \Drupal\Core\Plugin\PluginFormInterface
   *   The plugin form for the layout.
   */
  protected function getLayoutPluginForm(LayoutInterface $layout) {
    if ($layout instanceof PluginWithFormsInterface) {
      return $this->pluginFormFactory->createInstance($layout, 'configure');
    }

    if ($layout instanceof PluginFormInterface) {
      return $layout;
    }

    return FALSE;
  }

}
