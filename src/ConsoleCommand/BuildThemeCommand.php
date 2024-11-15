<?php

declare(strict_types=1);

namespace ThemeManager\ConsoleCommand;

use DirectoryIterator;
use Laminas\Cli\Command\AbstractParamAwareCommand;
use Laminas\Cli\Input\ParamAwareInputInterface;
use Laminas\Config\Factory;
use Laminas\Config\Writer\PhpArray as ConfigWriter;
use Laminas\Filter\Exception;
use Laminas\Filter\File\Rename;
use SplFileInfo;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function copy;
use function count;
use function in_array;
use function is_dir;
use function mkdir;

final class BuildThemeCommand extends AbstractParamAwareCommand
{
    private const APP_DIR           = 'App';
    private const APP_CONFIG        = __DIR__ . '/../../../../../config/application.config.php';
    private const LAYOUT_PATH       = __DIR__ . '/../../../../../module/Application/view/layout';
    private const THEME_NAME        = 'default';
    private const ERROR_PATH        = __DIR__ . '/../../../../../module/Application/view/error';
    private const MODULE_PATH       = __DIR__ . '/../../../../../module';
    private const VIEW_PATH_SEGMENT = '/view';
    // asset paths
    private const PUBLIC_PATH         = __DIR__ . '/../../../../../public';
    private const PUBLIC_PATH_SEGMENT = '/theme';
    private const DATA_PATH         = __DIR__ . '/../../../../../data';
    private const DATA_TARGET       = '/app/settings';
    private const BACKUP_CONFIG     = __DIR__ . '/../../config/theme.settings.php';
    private const CONFIG_FILENAME   = 'theme.settings.php';
    private static $supportedAssets = ['css', 'img', 'js'];
    private static $globPattern     = '/{,*.}settings.php';

    public function __construct(
        /** @var array<string, mixed> $config */
        private array $config,
    ) {
        parent::__construct();
    }

    public function configure(): void
    {
        $this->setName('migrate-files');
        $this->setDescription('Migrate Files');
        $this->setHelp('This command restructures the applications view layer to what is required by ThemeManager');
    }

    /** @param ParamAwareInputInterface $input */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->migrateTheme($input, $output) === self::SUCCESS && self::copyConfig($output) === self::SUCCESS) {
            if ($this->migrateAssets($input, $output) === self::SUCCESS) {
                return $this->updateGlobPath($output);
            }
        } else {
            return self::FAILURE;
        }
    }

    private function migrateTheme(InputInterface $input, OutputInterface $output): int
    {
        foreach (new DirectoryIterator(self::MODULE_PATH) as $moduleInfo) {
            if ($moduleInfo->isDot() || ! $moduleInfo->isDir()) {
                continue;
            }
            $module = $moduleInfo->getFilename();
            foreach (
                new DirectoryIterator($moduleInfo->getRealPath()
                . self::VIEW_PATH_SEGMENT) as $moduleSourceInfo
            ) {
                if (
                    $moduleSourceInfo->isDot()
                    || ! $moduleSourceInfo->isDir()
                    && $moduleSourceInfo->getFileName()
                    !== self::THEME_NAME
                ) {
                    continue;
                }
                $moduleSource = $moduleSourceInfo->getFileName();
                $target = $moduleInfo->getRealPath() . self::VIEW_PATH_SEGMENT . '/' . self::THEME_NAME;
                if (is_dir($target) || mkdir($target, 0600)) {
                    $output->writeln('<info>' . $target . ' has been created successfully. starting move...' . '</info>');
                    $filter = new Rename($target);
                    try {
                        $moduleMoved = $filter->filter($moduleSourceInfo->getRealPath());
                        $output->writeln('<info>' . $moduleMoved . ' has been moved successfully' . '</info>');
                        if ($module === self::APP_DIR) {
                            $layoutMoved = $filter->filter(self::LAYOUT_PATH);
                            $output->writeln('<info>' . $layoutMoved . ' has been moved successfully' . '</info>');
                            $errorMoved  = $filter->filter(self::ERROR_PATH);
                            $output->writeln('<info>' . $errorMoved . ' has been moved successfully' . '</info>');
                        }
                    } catch (Exception\RuntimeException $e) {
                        $output->writeln('<error>' . $e->getMessage() . '</error>');
                        return self::FAILURE;
                    }
                }
            }
            continue;
        }
        $output->writeln('<info>View files moved successfully for all modules...</info>');
        return self::SUCCESS;
    }

    private function migrateAssets(InputInterface $input, OutputInterface $output): int
    {
        $docRoot = new SplFileInfo(self::PUBLIC_PATH);
        $target = $docRoot->getRealPath() . self::PUBLIC_PATH_SEGMENT . '/' . self::THEME_NAME;
        if (is_dir($target) || mkdir($target, 0755, true)) {
            $themeInfo = new SplFileInfo($target);
            if ($themeInfo->isDir() && $themeInfo->isWritable()) {
                $output->writeln('<info>' . $themeInfo->getRealPath() . ' has been created and is writable. Starting asset move...' . '</info>');
                foreach (new DirectoryIterator($docRoot->getRealPath()) as $assetInfo) {
                    if ($assetInfo->isDot() || ! $assetInfo->isDir() && $assetInfo->getFilename() !== 'theme') {
                        continue;
                    }
                    $assetDir = $assetInfo->getFilename();
                    $filter = new Rename($target);
                    try {
                        if (in_array($assetDir, static::$supportedAssets)) {
                            $assetMoved = $filter->filter($assetInfo->getRealPath());
                            $output->writeln('<info>' . $assetMoved . ' has been moved successfully' . '</info>');
                        }
                    } catch (Exception\RuntimeException $e) {
                        $output->writeln('<error>' . $e->getMessage() . '</error>');
                        return self::FAILURE;
                    }
                }
            } else {
                $output->writeln('<error>' . $themeInfo->getRealPath() . ' is not writable. Please check your logs!' . '</error>');
                return self::FAILURE;
            }
        } else {
            $output->writeln('<error>' . $target . ' could not be created!' . '</error>');
            return self::FAILURE;
        }
        $output->writeln('<info>All supported assets moved successfully.</info>');
        return self::SUCCESS;
    }

    public static function copyConfig(?OutputInterface $output = null): int
    {
        $returnCode = null;
        if (
            is_dir(self::DATA_PATH . self::DATA_TARGET)
            || mkdir(self::DATA_PATH . self::DATA_TARGET, 0750, true)
        ) {

            $targetInfo = new SplFileInfo(self::DATA_PATH . self::DATA_TARGET);
            if ($targetInfo->isDir() && $targetInfo->isReadable() && $targetInfo->isWritable()) {
                $source = new SplFileInfo(self::BACKUP_CONFIG);
                if ($source->isFile() && $source->isReadable()) {
                    if (! copy($source->getRealPath(), $targetInfo->getRealPath(). '/' . self::CONFIG_FILENAME)) {
                        $returnCode = self::FAILURE;
                    }
                    $output->writeln('<info>Config written to: ' . $targetInfo->getRealPath() . '</info>');
                    $returnCode = self::SUCCESS;
                } else {
                    $output->writeln('<error>Directory permission error: ' . $targetInfo->getRealPath() . '</error>');
                }

            } else {
                $output->writeln('<error>Config could not be written to: ' . $targetInfo->getRealPath() . '</error>');
                $returnCode = self::FAILURE;
            }
        } else {
            $output->writeln('<error>Applications /data/app/settings could not be found or created.</error>');
        }
        return $returnCode;
    }

    private function updateGlobPath(?OutputInterface $output = null): int
    {
        $configFile = new SplFileInfo(self::APP_CONFIG);
        $globPath = new SplFileInfo(self::DATA_PATH . self::DATA_TARGET);
        $appConfig = Factory::fromFile($configFile->getRealPath(), true)->toArray();
        $writeData = $globPath->getRealPath() . self::$globPattern;
        if (isset($appConfig['module_listener_options']['config_glob_paths'])) {
            if (
                count($appConfig['module_listener_options']['config_glob_paths']) > 1
                && in_array($writeData, $appConfig['module_listener_options']['config_glob_paths'])
            ) {
                $output->writeln('<info>glob path has already been updated in: ' . $configFile->getRealPath() . '</info>');
                return self::SUCCESS;
            }
            $appConfig['module_listener_options']['config_glob_paths'][] = $writeData;
            $writer = new ConfigWriter();
            $writer->setUseBracketArraySyntax(true);
            try {
                $writer->toFile(self::APP_CONFIG, $appConfig);
                $output->writeln('<info>glob path updated in: ' . $configFile->getRealPath() . '</info>');
                return self::SUCCESS;
            } catch (\Throwable $th) {
                $output->writeln('<error>Glob path could not be updated in '. $configFile->getRealPath() .'</error>');
                return self::FAILURE;
            }
        } else {
            $output->writeln('<error>Could not parse config file: '. $configFile->getRealPath() .'</error>');
            return self::FAILURE;
        }
    }
}
