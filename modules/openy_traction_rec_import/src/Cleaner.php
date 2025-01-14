<?php

namespace Drupal\openy_traction_rec_import;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drush\Drush;

/**
 * Clean up old sessions data.
 */
class Cleaner {

  /**
   * Active database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Logger for traction_rec.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * The importer service.
   *
   * @var \Drupal\openy_traction_rec_import\Importer
   */
  protected $importer;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * Cleaner constructor.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_channel_factory
   *   Logger factory.
   * @param \Drupal\openy_traction_rec_import\TractionRecImporterInterface $importer
   *   The traction rec Importer.
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file system service.
   */
  public function __construct(Connection $database, EntityTypeManagerInterface $entity_type_manager, LoggerChannelFactoryInterface $logger_channel_factory, TractionRecImporterInterface $importer, FileSystemInterface $file_system) {
    $this->database = $database;
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger_channel_factory->get('sessions_cleaner');
    $this->importer = $importer;
    $this->fileSystem = $file_system;
  }

  /**
   * Cleans JSON files from backup folder.
   */
  public function cleanBackupFiles() {
    if (PHP_SAPI !== 'cli') {
      return;
    }

    // We want to store only latest backups of JSON files.
    if ($this->importer->isBackupEnabled()) {
      $backup_dir = $this->fileSystem->realpath(Importer::BACKUP_DIRECTORY);
      $backup_limit = $this->importer->getJsonBackupLimit();
      $backup_limit++;

      $command = "cd $backup_dir";
      $command .= '&&';
      $command .= 'ls -t';
      $command .= '|';
      $command .= "tail -n +$backup_limit";
      $command .= '|';
      $command .= "xargs -d '\n' rm -rf";

      $process = Drush::shell($command);
      $process->run();
      if (!$process->isSuccessful()) {
        $this->logger->warning('Impossible to remove JSON files: ' . $process->getErrorOutput());
      }
    }
  }

  /**
   * Starts database clean up.
   */
  public function cleanDatabase($limit = 5000) {
    $this->deleteOrphanedParagraphs($limit);
  }

  /**
   * Removes session_time paragraphs not associated with nodes.
   */
  protected function deleteOrphanedParagraphs($limit = 5000) {
    try {
      $query = $this->database->select('paragraphs_item_field_data', 'pifd');
      $query->addField('pifd', 'id');
      $query->isNull('pifd.parent_id');
      $query->isNull('pifd.parent_type');
      $query->range(0, $limit);
      $result = $query->execute()->fetchCol();

      if (empty($result)) {
        return FALSE;
      }

      $storage = $this->entityTypeManager->getStorage('paragraph');
      foreach (array_chunk($result, 50) as $chunk) {
        $entities = $storage->loadMultiple($chunk);
        $storage->delete($entities);
      }
    }
    catch (\Exception $e) {
      $this->logger->error('SF paragraphs clean up error: ' . $e->getMessage());
    }
  }

}
