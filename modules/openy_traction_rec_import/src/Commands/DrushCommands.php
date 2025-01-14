<?php

namespace Drupal\openy_traction_rec_import\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\migrate_tools\Commands\MigrateToolsCommands;
use Drupal\openy_traction_rec_import\Cleaner;
use Drupal\openy_traction_rec_import\Importer;
use Drupal\openy_traction_rec_import\TractionRecFetcher;
use Drush\Commands\DrushCommands as DrushCommandsBase;

/**
 * OPENY Traction Rec import drush commands.
 */
class DrushCommands extends DrushCommandsBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The importer service.
   *
   * @var \Drupal\openy_traction_rec_import\Importer
   */
  protected $importer;

  /**
   * The OPENY sessions cleaner service.
   *
   * @var \Drupal\openy_traction_rec_import\Cleaner
   */
  protected $cleaner;

  /**
   * Migrate tool drush commands.
   *
   * @var \Drupal\migrate_tools\Commands\MigrateToolsCommands
   */
  protected $migrateToolsCommands;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * The Traction Rec import queue.
   *
   * @var \Drupal\Core\Queue\QueueInterface
   */
  protected $importQueue;

  /**
   * Traction Rec fetcher service.
   *
   * @var \Drupal\openy_traction_rec_import\TractionRecFetcher
   */
  protected $tractionRecFetcher;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * DrushCommands constructor.
   *
   * @param \Drupal\openy_traction_rec_import\Importer $importer
   *   The Traction Rec importer service.
   * @param \Drupal\openy_traction_rec_import\Cleaner $cleaner
   *   OPENY sessions cleaner.
   * @param \Drupal\migrate_tools\Commands\MigrateToolsCommands $migrate_tools_drush
   *   Migrate Tools drush commands service.
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file handler.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Queue\QueueFactory $queue_factory
   *   The queue factory.
   * @param \Drupal\openy_traction_rec_import\TractionRecFetcher $tr_fetch
   *   The OPENY TractionRec Fetcher.
   */
  public function __construct(
    Importer $importer,
    Cleaner $cleaner,
    MigrateToolsCommands $migrate_tools_drush,
    FileSystemInterface $file_system,
    EntityTypeManagerInterface $entity_type_manager,
    QueueFactory $queue_factory,
    TractionRecFetcher $tr_fetch
  ) {
    parent::__construct();
    $this->importer = $importer;
    $this->cleaner = $cleaner;
    $this->migrateToolsCommands = $migrate_tools_drush;
    $this->fileSystem = $file_system;
    $this->entityTypeManager = $entity_type_manager;
    $this->importQueue = $queue_factory->get('openy_trasnsaction_recimport');
    $this->tractionRecFetcher = $tr_fetch;
  }

  /**
   * Executes the Traction Rec import.
   *
   * @param array $options
   *   Additional options for the command.
   *
   * @command openy-tr-sf:import
   * @aliases y-sf:import
   *
   * @option sync Sync source and destination. Delete destination records that
   *   do not exist in the source.
   *
   * @return bool
   *   Execution status.
   *
   * @throws \Exception
   */
  public function import(array $options): bool {
    if (!$this->importer->isEnabled()) {
      $this->logger()->notice(
        dt('Traction Rec import is not enabled!')
      );
      return FALSE;
    }

    if (!$this->importer->acquireLock()) {
      $this->logger()->notice(
        dt('Can\'t run new import, another import process already in progress.')
      );
      return FALSE;
    }

    if (!$this->importer->checkMigrationsStatus()) {
      $this->logger()->notice(
        dt('One or more migrations are still running or stuck.')
      );
      return FALSE;
    }

    $this->output()->writeln('Starting Traction Rec migration');

    $dirs = $this->importer->getJsonDirectoriesList();
    if (empty($dirs)) {
      $this->logger()->info(dt('Nothing to import.'));
      return FALSE;
    }

    foreach ($dirs as $dir) {
      $this->importer->directoryImport($dir, $options);
    }

    $this->importer->releaseLock();
    $this->output()->writeln('Traction Rec migration done!');

    return TRUE;
  }

  /**
   * Executes the Traction Rec rollback.
   *
   * @command openy-tr-sf:rollback
   * @aliases y-sf:rollback
   */
  public function rollback() {
    try {
      $this->output()->writeln('Rollbacking Traction Rec migrations...');
      $options = ['group' => Importer::MIGRATE_GROUP];
      $this->migrateToolsCommands->rollback('', $options);
      $this->output()->writeln('Rollback done!');
    }
    catch (\Exception $e) {
      $this->logger()->error($e->getMessage());
    }
  }

  /**
   * Remove all sessions from the website.
   *
   * @command openy-tr-sf:session-flush
   * @aliases y-sf:session-flush
   */
  public function flushSessions() {
    $storage = $this->entityTypeManager->getStorage('node');

    $sessions = $storage->loadByProperties(['type' => 'session']);

    if ($sessions) {
      $storage->delete($sessions);
    }

  }

  /**
   * Resets the import lock.
   *
   * @command openy-tr-sf:reset-lock
   */
  public function resetLock() {
    $this->output()->writeln('Reset import status...');
    $this->importer->releaseLock();
  }

  /**
   * Clean up actions.
   *
   * @param array $options
   *   The array of command options.
   *
   * @command openy-tr-sf:clean-up
   */
  public function cleanUp(array $options) {
    $this->output()->writeln('Starting clean up...');
    $this->cleaner->cleanBackupFiles();
    $this->output()->writeln('Clean up finished!');
  }

  /**
   * Clean up actions.
   *
   * @param array $options
   *   The array of command options.
   *
   * @option limit Max number of entities to remove at one cron run. Default: 10000
   *
   * @command openy-tr-sf:db-clean-up
   */
  public function databaseCleanUp(array $options) {
    $this->output()->writeln('Starting database clean up...');
    $this->cleaner->cleanDatabase($options['limit']);
    $this->output()->writeln('Database clean up finished!');
  }

  /**
   * Run Traction Rec fetcher.
   *
   * @command openy-tr:tr-fetch-all
   * @aliases y-tr-fa
   */
  public function fetch() {
    if (!$this->tractionRecFetcher->isEnabled()) {
      $this->logger()->notice(dt('Fetcher is disabled!'));
      return FALSE;
    }

    $this->tractionRecFetcher->fetch();
  }

  /**
   * Clean up actions.
   *
   * @param array $options
   *   The array of command options.
   *
   * @command openy-tr-sf:queue-clean-up
   */
  public function addCleanUpToQueue(array $options) {
    $data = [
      'type' => 'cleanup',
    ];

    $this->importQueue->createItem($data);
  }

  /**
   * Add full-sync action to the queue.
   *
   * @param array $options
   *   The array of command options.
   *
   * @command openy-tr-sf:queue-import-sync
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function addSyncActionToQueue(array $options) {
    if (!$this->tractionRecFetcher->isEnabled()) {
      $this->logger()->notice(dt('Fetcher is disabled!'));
      return FALSE;
    }

    try {
      $this->tractionRecFetcher->fetchProgramAndCategories();
      $this->tractionRecFetcher->fetchClasses();
      $this->tractionRecFetcher->fetchSessions();

      $directory = $this->tractionRecFetcher->getJsonDirectory();

      $data = [
        'type' => 'traction_rec_sync',
        'directory' => $directory,
      ];

      $this->importQueue->createItem($data);
    }
    catch (\Exception $e) {
      $this->logger()->error($e->getMessage());
    }
  }

}
