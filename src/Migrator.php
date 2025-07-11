<?php

/**
 * @file
 * Migrator service for CKEditor 4 to CKEditor 5 migration.
 */

namespace Drupal\iq_ckeditor4_to_ckeditor5_migration;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\ckeditor5\HTMLRestrictions;
use Drupal\ckeditor5\Plugin\CKEditor4To5UpgradePluginInterface;
use Drupal\filter\FilterFormatInterface;

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
   * @param \Drupal\ckeditor5\Plugin\CKEditor4To5UpgradePluginInterface $pluginManager
   *   The plugin manager for CKEditor 4 to 5 upgrade plugins.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager service.
   */
  public function __construct(
    protected ModuleInstallerInterface $moduleInstaller,
    protected ModuleHandlerInterface $moduleHandler,
    protected CKEditor4To5UpgradePluginInterface $pluginManager,
    protected EntityTypeManagerInterface $entityTypeManager,
    LoggerChannelFactoryInterface $loggerFactory
  ) {
    $this->logger = $loggerFactory->get('iq_ckeditor4_to_ckeditor5_migration');
  }

  public function migrate(): void {
    if (!$this->moduleHandler->moduleExists('ckeditor')) {
      return;
    }
  
    if (!$this->moduleHandler->moduleExists('ckeditor5')) {
      $this->moduleInstaller->install(['ckeditor5']);
    }
  
    $formats = $this->entityTypeManager->getStorage('filter_format')->loadMultiple();
  
    foreach ($formats as $format) {
      if ($format->getEditor() !== 'ckeditor') {
        continue;
      }
  
      $editor_settings = $format->getEditorSettings();
      $toolbar = $editor_settings['toolbar'] ?? [];
      $cke4_plugin_settings = $editor_settings['plugins'] ?? [];
  
      $cke4_buttons = array_column($toolbar, 'name');
      $restrictions = HTMLRestrictions::fromFilterFormat($format);
  
      $cke5_toolbar = [];
      $cke5_plugin_settings = [];
  
      foreach ($this->pluginManager->getDefinitions() as $plugin_id => $definition) {
        /** @var CKEditor4To5UpgradePluginInterface $plugin */
        $plugin = $this->pluginManager->createInstance($plugin_id);
        $cke4_buttons_supported = $definition['ckeditor4_buttons'] ?? [];
  
        foreach ($cke4_buttons_supported as $cke4_button) {
          if (!in_array($cke4_button, $cke4_buttons, true)) {
            continue;
          }
  
          try {
            $converted_buttons = $plugin->mapCKEditor4ToolbarButtonToCKEditor5ToolbarItem($cke4_button, $restrictions);
            if ($converted_buttons) {
              foreach ($converted_buttons as $btn) {
                if (!in_array($btn, $cke5_toolbar, true)) {
                  $cke5_toolbar[] = $btn;
                }
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
  
        // Conversion des settings CKEditor4 → CKEditor5
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
            // Log error.
            $this->logger->error('CKEditor 4 plugin @plugin could not be converted: @message', [
              '@plugin' => $cke4_plugin_id,
              '@message' => $e->getMessage(),
            ]);
          }
        }
      }
  
      $format->setEditor('ckeditor5');
      $format->setEditorSettings([
        'toolbar' => $cke5_toolbar,
        'plugins' => $cke5_plugin_settings,
      ]);
      $format->save();
    }

}
