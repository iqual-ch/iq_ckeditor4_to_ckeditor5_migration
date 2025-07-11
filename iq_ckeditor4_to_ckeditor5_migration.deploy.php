<?php

/**
 * @file
 * Post deploy hook to trigger migration.
 */

/**
 * Trigger CKeditor 4 to 5 migration.
 */
function iq_ckeditor4_to_ckeditor5_migration_deploy_migrate() {

  /** @var \Drupal\ckeditor4_to_5_migrator\MigratorInterface $migrator */
  $migrator = \Drupal::service('iq_ckeditor4_to_ckeditor5_migration.migrator');
  $migrator->migrate();

  \Drupal::logger('iq_ckeditor4_to_ckeditor5_migration')->info('CKEditor migration completed successfully.');
}
