<?php
namespace AppZap\Migrator\Command;

use SplFileInfo;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Exception;
use TYPO3\CMS\Core\Registry;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Class AbstractMigrateCommand
 *
 * @package AppZap\Migrator\Command
 */
class AbstractMigrateCommand extends Command
{

    /**
     * @var array
     */
    protected $extensionConfiguration;

    /**
     * @var string
     */
    protected $migrationDirectoryPath = '';

    /**
     * @var Registry
     */
    protected $registry;

    /**
     * @var string
     */
    protected $shellCommandTemplate = '%s --default-character-set=UTF8 -u"%s" -p"%s" -h "%s" -D "%s" -e "source %s" 2>&1';

    /**
     *
     */
    protected function configure()
    {
        $this->extensionConfiguration = $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['migrator'];
        $this->registry = GeneralUtility::makeInstance(Registry::class);

        $this->addOption(
            'dry-run',
            'd',
            InputOption::VALUE_NONE,
            'Run the entire migrate operation and show output, but don\'t actually make any changes to the system or database.'
        );
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);

        $pathFromConfig = null;
        if (empty($this->extensionConfiguration['migrationFolderPath'])) {
            $io->writeln(
                '<fg=yellow>The "sqlFolderPath" configuration is deprecated. Please use "migrationFolderPath" instead.</>'
            );
            $pathFromConfig = Environment::getPublicPath() . DIRECTORY_SEPARATOR . $this->extensionConfiguration['sqlFolderPath'];
        } else {
            $pathFromConfig = Environment::getPublicPath() . DIRECTORY_SEPARATOR . $this->extensionConfiguration['migrationFolderPath'];
        }
        $this->migrationDirectoryPath = realpath($pathFromConfig);

        if (!$this->migrationDirectoryPath) {
            GeneralUtility::mkdir_deep($pathFromConfig);
            $this->migrationDirectoryPath = realpath($pathFromConfig);
            if (!$this->migrationDirectoryPath) {
                $io->writeln(
                    sprintf(
                        '<fg=red>Migration folder not found. Please make sure "%s" exists!</>',
                        htmlspecialchars($pathFromConfig)
                    )
                );
            }
            return 1;
        }

        $io->writeln(sprintf('Migration path: %s', $this->migrationDirectoryPath));
        return 0;
    }

    /**
     * @param SplFileInfo $fileInfo
     * @param array $errors
     * @param string $output
     * @return bool
     */
    protected function migrateSqlFile(SplFileInfo $fileInfo, &$errors, &$output)
    {
        $filePath = $fileInfo->getPathname();

        $shellCommand = sprintf(
            $this->shellCommandTemplate,
            $this->extensionConfiguration['mysqlBinaryPath'],
            $GLOBALS['TYPO3_CONF_VARS']['DB']['username'] ? : $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default']['user'],
            $GLOBALS['TYPO3_CONF_VARS']['DB']['password'] ? : $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default']['password'],
            $GLOBALS['TYPO3_CONF_VARS']['DB']['host'] ? : $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default']['host'],
            $GLOBALS['TYPO3_CONF_VARS']['DB']['database'] ? : $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default']['dbname'],
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
     * @param SplFileInfo $fileInfo
     * @param array $errors
     * @param string $output
     * @return bool
     */
    protected function migrateTypo3CmsFile($fileInfo, &$errors, &$output)
    {
        $migrationContent = file_get_contents($fileInfo->getPathname());
        foreach (explode(PHP_EOL, $migrationContent) as $line) {
            $line = trim($line);
            if (!empty($line) && strpos($line, '#') !== 0 && strpos($line, '//') !== 0) {
                $outputLines = array();
                $status = null;
                $shellCommand =
                    ($this->extensionConfiguration['typo3cmsBinaryPath'] ? : './vendor/bin/typo3cms')
                    . ' '
                    . $line;
                exec($shellCommand, $outputLines, $status);
                $output = implode(PHP_EOL, $outputLines);
                if ($status !== 0) {
                    $errors[] = $output;
                    break;
                }
            }
        }
        return count($errors) === 0;
    }

    /**
     * @param SplFileInfo $fileInfo
     * @param array $errors
     * @param string $output
     * @return bool
     */
    protected function migrateShellFile($fileInfo, &$errors, &$output)
    {
        $command = $fileInfo->getPathname();
        $outputLines = array();
        $status = null;
        chdir(Environment::getPublicPath());
        exec($command, $outputLines, $status);
        $output = implode(PHP_EOL, $outputLines);
        if ($status !== 0) {
            $errors[] = $output;
        }
        return count($errors) === 0;
    }

    /**
     * @param int $executedFiles
     * @param array $errors
     * @param SymfonyStyle $io
     * @throws Exception
     */
    protected function outputMessages($executedFiles, $errors, $io)
    {

        if ($executedFiles === 0 && count($errors) === 0) {
            $io->writeln('Everything up to date. No migrations needed.');
        } else {
            if ($executedFiles) {
                $io->writeln(
                    sprintf(
                        'Migration of %d file%s completed.',
                        $executedFiles,
                        ($executedFiles > 1 ? 's' : '')
                    )
                );
            } else {
                $io->writeln('<fg=red>Migration failed</>');
            }
            if (count($errors)) {
                $io->writeln(sprintf('<fg=red>The following error%s occured:</>', (count($errors) > 1 ? 's' : '')));
                foreach ($errors as $filename => $error) {
                    $io->writeln(sprintf('File %s: ', $filename));
                    $io->writeln(sprintf('%s: ', implode(PHP_EOL, $error)));
                }
            }
        }
    }
}
