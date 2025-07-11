<?php

/**
 * @file
 * Migrator service for CKEditor 4 to CKEditor 5 migration.
 */

namespace Drupal\iq_ckeditor4_to_ckeditor5_migration;

use Drupal\ckeditor5\HTMLRestrictions;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\ckeditor5\Plugin\CKEditor4To5UpgradePluginManager;
use Drupal\filter\Plugin\FilterInterface;

/**
 * Class Migrator.
 *
 * Handles the migration of CKEditor 4 configurations to CKEditor 5.
 */
class Migrator {

  /**
   * The logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * Constructs a Migrator service.
   *
   * @param \Drupal\Core\Extension\ModuleInstallerInterface $moduleInstaller
   *   The module installer service.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler service.
   * @param \Drupal\ckeditor5\Plugin\CKEditor4To5UpgradePluginManager $pluginManager
   *   The plugin manager for CKEditor 4 to 5 upgrade plugins.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger channel factory service.
   */
  public function __construct(
    protected ModuleInstallerInterface $moduleInstaller,
    protected ModuleHandlerInterface $moduleHandler,
    protected CKEditor4To5UpgradePluginManager $pluginManager,
    protected EntityTypeManagerInterface $entityTypeManager,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get('iq_ckeditor4_to_ckeditor5_migration');
  }

  /**
   * Migrates CKEditor 4 configurations to CKEditor 5.
   *
   * Checks for the existence of the CKEditor and CKEditor 5 modules,
   * installs CKEditor 5 if necessary, and migrates all filter formats using
   * CKEditor 4 to use CKEditor 5 instead.
   */
  public function migrate() {
    if (!$this->moduleHandler->moduleExists('ckeditor')) {
      return;
    }

    if (!$this->moduleHandler->moduleExists('ckeditor5')) {
      $this->moduleInstaller->install(['ckeditor5']);
    }

    /** @var \Drupal\filter\FilterFormatInterface[] $formats */
    $formats = $this->entityTypeManager->getStorage('filter_format')->loadMultiple();
    $editorStorage = $this->entityTypeManager->getStorage('editor');

    foreach ($formats as $format) {
      /** @var \Drupal\editor\EditorInterface|null $editor */
      $editor = $editorStorage->load($format->id());
      if (!$editor || $editor->getEditor() !== 'ckeditor') {
        continue;
      }

      $editor_settings = $editor->getSettings();
      $toolbar = $editor_settings['toolbar'] ?? [];
      $cke4_plugin_settings = $editor_settings['plugins'] ?? [];

      $cke4_buttons = $this->extractCkeditor4ButtonItems($toolbar);

      $restrictions = NULL;
      $filters = $format->filters();
      foreach ($filters as $filter) {
        if ($filter instanceof FilterInterface) {
          $filter_restrictions = HTMLRestrictions::fromFilterPluginInstance($filter);
          $restrictions =
          $filter_restrictions ?
            ($restrictions instanceof HTMLRestrictions ?
              $restrictions->merge($filter_restrictions) :
              $filter_restrictions
            ) :
            $restrictions;
        }
      }

      $cke5_toolbar = [];
      $cke5_plugin_settings = [];

      foreach ($this->pluginManager->getDefinitions() as $plugin_id => $definition) {
        /** @var \Drupal\ckeditor5\Plugin\CKEditor4To5UpgradePluginInterface $plugin */
        $plugin = $this->pluginManager->createInstance($plugin_id);
        $cke4_buttons_supported = $definition['cke4_buttons'] ?? [];

        foreach ($cke4_buttons_supported as $cke4_button) {
          if (!in_array($cke4_button, $cke4_buttons, TRUE)) {
            continue;
          }

          try {
            $converted_buttons = $plugin->mapCKEditor4ToolbarButtonToCKEditor5ToolbarItem($cke4_button, $restrictions);
            foreach ((array) $converted_buttons as $btn) {
              if (!in_array($btn, $cke5_toolbar, TRUE)) {
                $cke5_toolbar[] = $btn;
              }
            }
          }
          catch (\OutOfBoundsException $e) {
            $this->logger->error('CKEditor 4 button @button could not be converted: @message', [
              '@button' => $cke4_button,
              '@message' => $e->getMessage(),
            ]);
          }
        }

        foreach ($cke4_plugin_settings as $cke4_plugin_id => $cke4_settings) {
          try {
            $converted_settings = $plugin->mapCKEditor4SettingsToCKEditor5Configuration($cke4_plugin_id, $cke4_settings);
            if ($converted_settings) {
              foreach ($converted_settings as $cke5_plugin_id => $cke5_settings) {
                $cke5_plugin_settings[$cke5_plugin_id] = $cke5_settings;
              }
            }
          }
          catch (\OutOfBoundsException $e) {
            $this->logger->error('CKEditor 4 plugin @plugin could not be converted: @message', [
              '@plugin' => $cke4_plugin_id,
              '@message' => $e->getMessage(),
            ]);
          }
        }
      }

      // Migration finale vers CKEditor5.
      $editor->setEditor('ckeditor5');
      $editor->setSettings([
        'toolbar' => [
          'items' => $cke5_toolbar,
        ],
        'plugins' => $cke5_plugin_settings,
      ]);
      $editor->save();
    }
  }

  /**
   * Extracts CKEditor 4 button items from the toolbar configuration.
   *
   * @param array $toolbar
   *   The toolbar configuration array.
   *
   * @return array
   *   An array of unique CKEditor 4 button items.
   */
  protected function extractCkeditor4ButtonItems(array $toolbar): array {
    $items = [];
    $it = new \RecursiveIteratorIterator(new \RecursiveArrayIterator($toolbar));
    foreach ($it as $key => $value) {
      if ($key !== 'name' && is_string($value)) {
        $items[] = $value;
      }
    }
    return array_unique($items);
  }

}
