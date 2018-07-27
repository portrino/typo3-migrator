<?php
namespace AppZap\Migrator\Command;

use AppZap\Migrator\DirectoryIterator\SortableDirectoryIterator;
use SplFileInfo;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Controller\CommandController;

class MigrationCommandController extends CommandController
{

    /**
     * @var \TYPO3\CMS\Core\Database\DatabaseConnection
     */
    protected $databaseConnection;

    /**
     * @var array
     */
    protected $extensionConfiguration;

    /**
     * @var string
     */
    protected $shellCommandTemplate = '%s --default-character-set=UTF8 -u"%s" -p"%s" -h "%s" -D "%s" -e "source %s" 2>&1';

    /**
     * @var \TYPO3\CMS\Core\Registry
     * @inject
     */
    protected $registry;

    /**
     *
     */
    protected function initialize()
    {
        $this->databaseConnection = $GLOBALS['TYPO3_DB'];
        $this->extensionConfiguration = unserialize($GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf']['migrator']);
    }

    /**
     * @deprecated Use migrateCommand instead
     */
    public function migrateSqlFilesCommand()
    {
        $this->flashMessage('The "migration:migrateSqlFiles" command is deprecated. Please use "migration:migrateAll" command instead.', 'Migration Command',
                FlashMessage::WARNING);
        $this->migrateAllCommand();
    }

    /**
     *
     */
    public function migrateAllCommand()
    {
        $this->initialize();
        $pathFromConfig = null;
        if (empty($this->extensionConfiguration['migrationFolderPath'])) {
            $this->flashMessage('The "sqlFolderPath" configuration is deprecated. Please use "migrationFolderPath" instead.',
                    'Migration Command', FlashMessage::WARNING);
            $pathFromConfig = PATH_site . $this->extensionConfiguration['sqlFolderPath'];
        } else {
            $pathFromConfig = PATH_site . $this->extensionConfiguration['migrationFolderPath'];
        }
        $migrationFolderPath = realpath($pathFromConfig);

        if (!$migrationFolderPath) {
            GeneralUtility::mkdir_deep($pathFromConfig);
            $migrationFolderPath = realpath($pathFromConfig);
            if (!$migrationFolderPath) {
                $message = 'Migration folder not found. Please make sure "' . htmlspecialchars($pathFromConfig) . '" exists!';
                $this->flashMessage($message, 'Migration Command', FlashMessage::ERROR);
            }
            return;
        }

        $this->flashMessage('Migration path: ' . $migrationFolderPath, 'Migration Command', FlashMessage::INFO);

        $iterator = new SortableDirectoryIterator($migrationFolderPath);

        $highestExecutedVersion = 0;
        $errors = array();
        $executedFiles = 0;
        foreach ($iterator as $fileinfo) {

            /** @var $fileinfo SplFileInfo */

            $fileVersion = (int)$fileinfo->getBasename('.' . $fileinfo->getExtension());

            if ($fileinfo->getType() != 'file') {
                continue;
            }

            $migrationStatus = $this->registry->get(
                    'AppZap\\Migrator',
                    'migrationStatus:' . $fileinfo->getBasename(),
                    array('tstamp' => null, 'success' => false)
            );


            if ($migrationStatus['success']) {
                // already successfully executed
                continue;
            }

            $this->flashMessage('execute ' . $fileinfo->getBasename(), 'Migration Command', FlashMessage::INFO);

            $migrationErrors = array();
            $migrationOutput = '';
            switch ($fileinfo->getExtension()) {
                case 'sql':
                    $success = $this->migrateSqlFile($fileinfo, $migrationErrors, $migrationOutput);
                    break;
                case 'typo3cms':
                    $success = $this->migrateTypo3CmsFile($fileinfo, $migrationErrors, $migrationOutput);
                    break;
                case 'sh':
                    $success = $this->migrateShellFile($fileinfo, $migrationErrors, $migrationOutput);
                    break;
                default:
                    // ignore other files
                    $success = true;
            }

            $this->flashMessage('done ' . $fileinfo->getBasename() . ' ' . ($success ? 'OK' : 'ERROR'), 'Migration Command', FlashMessage::INFO);

            $this->outputLine(trim($migrationOutput) . PHP_EOL);

            // migration stops on the 1st erroneous sql file
            if (!$success || count($migrationErrors) > 0) {
                $errors[$fileinfo->getFilename()] = $migrationErrors;
                break;
            }

            if ($success) {
                $executedFiles++;
                $highestExecutedVersion = max($highestExecutedVersion, $fileVersion);
            }

            $this->registry->set(
                    'AppZap\\Migrator',
                    'migrationStatus:' . $fileinfo->getBasename(),
                    array('tstamp' => time(), 'success' => $success)
            );
        }

        $this->enqueueFlashMessages($executedFiles, $errors);

        $this->sendAndExit(count($errors) > 0 ? 1 : 0);
    }

    /**
     * @param SplFileInfo $fileinfo
     * @param array $errors
     * @param string $output
     * @return bool
     */
    protected function migrateSqlFile(SplFileInfo $fileinfo, &$errors, &$output)
    {
        $filePath = $fileinfo->getPathname();

        $shellCommand = sprintf(
                $this->shellCommandTemplate,
                $this->extensionConfiguration['mysqlBinaryPath'],
                $GLOBALS['TYPO3_CONF_VARS']['DB']['username'],
                $GLOBALS['TYPO3_CONF_VARS']['DB']['password'],
                $GLOBALS['TYPO3_CONF_VARS']['DB']['host'],
                $GLOBALS['TYPO3_CONF_VARS']['DB']['database'],
                $filePath
        );

        $output = shell_exec($shellCommand);

        $outputMessages = explode("\n", $output);
        foreach ($outputMessages as $outputMessage) {
            if (trim($outputMessage) && strpos($outputMessage, 'ERROR') !== false) {
                $errors[] = $outputMessage;
            }
        }

        return count($errors) === 0;
    }

    /**
     * @param SplFileInfo $fileinfo
     * @param array $errors
     * @param string $output
     * @return bool
     */
    protected function migrateTypo3CmsFile($fileinfo, &$errors, &$output)
    {
        $migrationContent = file_get_contents($fileinfo->getPathname());
        foreach (explode(PHP_EOL, $migrationContent) as $line) {
            $line = trim($line);
            if (!empty($line) && substr($line, 0, 1) != '#' && substr($line, 0, 2) != '//') {
                $outputLines = array();
                $status = null;
                exec('./bin/typo3cms ' . $line, $outputLines, $status);
                $output = implode(PHP_EOL, $outputLines);
                if ($status != 0) {
                    $errors[] = $output;
                    break;
                }
            }
        }
        return count($errors) === 0;
    }

    /**
     * @param SplFileInfo $fileinfo
     * @param array $errors
     * @param string $output
     * @return bool
     */
    protected function migrateShellFile($fileinfo, &$errors, &$output)
    {
        $command = $fileinfo->getPathname();
        $outputLines = array();
        $status = null;
        chdir(PATH_site);
        exec($command, $outputLines, $status);
        $output = implode(PHP_EOL, $outputLines);
        if ($status != 0) {
            $errors[] = $output;
        }
        return count($errors) === 0;
    }

    /**
     * @param $message
     * @param $title
     * @param int $severity
     */
    protected function flashMessage($message, $title = '', $severity = FlashMessage::OK)
    {
        if (defined('TYPO3_cliMode')) {
            $severityText = '';
            if ($severity == FlashMessage::ERROR) {
                $severityText = 'ERROR: ';
            } elseif ($severity == FlashMessage::WARNING) {
                $severityText = 'WARNING: ';
            }
            $this->outputLine($title . ': ' . $severityText . strip_tags($message));
            return;
        }

        if (!isset($this->flashMessageService)) {
            $this->flashMessageService = GeneralUtility::makeInstance('TYPO3\\CMS\\Core\\Messaging\\FlashMessageService');
        }

        /** @var $defaultFlashMessageQueue \TYPO3\CMS\Core\Messaging\FlashMessageQueue */
        $defaultFlashMessageQueue = $this->flashMessageService->getMessageQueueByIdentifier();
        $defaultFlashMessageQueue->enqueue(
                GeneralUtility::makeInstance('TYPO3\\CMS\\Core\\Messaging\\FlashMessage', $message, $title, $severity)
        );
    }

    /**
     * @param $executedFiles
     * @param $errors
     */
    protected function enqueueFlashMessages($executedFiles, $errors)
    {
        $flashMessageTitle = 'Migration Command';
        if ($executedFiles === 0 && count($errors) === 0) {
            $this->flashMessage('Everything up to date. No migrations needed.', $flashMessageTitle,
                    FlashMessage::NOTICE);
        } else {
            if ($executedFiles) {
                $this->flashMessage('Migration of ' . $executedFiles . ' file' . ($executedFiles > 1 ? 's' : '') . ' completed.',
                        $flashMessageTitle, FlashMessage::OK);
            } else {
                $this->flashMessage('Migration failed.', $flashMessageTitle, FlashMessage::ERROR);
            }
            if (count($errors)) {
                $errorMessage = 'The following error' . (count($errors) > 1 ? 's' : '') . ' occured:';
                $errorMessage .= defined('TYPO3_cliMode') ? '' : '<ul>';
                foreach ($errors as $filename => $error) {
                    $errorMessage .= defined('TYPO3_cliMode') ? '' : '<li>';
                    $errorMessage .= 'File ' . $filename . ': ';
                    $errorMessage .= implode(defined('TYPO3_cliMode') ? PHP_EOL : '<br>', $error);
                    $errorMessage .= defined('TYPO3_cliMode') ? PHP_EOL : '</li>';
                }
                $errorMessage .= defined('TYPO3_cliMode') ? '' : '</ul>';
                $this->flashMessage($errorMessage, $flashMessageTitle, FlashMessage::ERROR);
            }
        }
    }
}
