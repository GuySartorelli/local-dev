<?php

namespace DevTools\Command;

use DevTools\Utility\DockerService;
use DevTools\Utility\Environment;
use LogicException;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProcessHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Path;

// TODO either subclass the Docker command or call that command from here to make code more DRY
class PhpConfig extends BaseCommand
{
    protected static $defaultName = 'php';

    protected static $defaultDescription = 'Make changes to PHP config (e.g. change php version, toggle xdebug).';

    private ProcessHelper $processHelper;

    /**
     * @inheritDoc
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        $hasOne = false;
        foreach (['php-version', 'info', 'toggle-debug'] as $option) {
            if ($input->getOption($option)) {
                $hasOne = true;
                break;
            }
        }
        if (!$hasOne) {
            throw new RuntimeException('At least one option must be used.');
        }
        parent::initialize($input, $output);
        $this->processHelper = $this->getHelper('process');
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $proposedPath = Path::makeAbsolute(Path::canonicalize($input->getArgument('env-path')), getcwd());
        try {
            $this->setVar('env', new Environment($proposedPath));
        } catch (LogicException $e) {
            $output->writeln($e->getMessage());
            return Command::INVALID;
        }

        if ($phpVersion = $input->getOption('php-version')) {
            if ($input->getOption('values-only')) {
                $output->writeln('PHP version is ' . $this->getVersion());
            } else {
                $failureCode = $this->swapToVersion($phpVersion);
                if ($failureCode) {
                    return $failureCode;
                }
            }
        }

        if ($input->getOption('toggle-debug')) {
            if ($input->getOption('values-only')) {
                $onoff = $this->debugIsEnabled() ? 'on' : 'off';
                $output->writeln('Debug is ' . $onoff);
            } else {
                $failureCode = $this->toggleDebug();
                if ($failureCode) {
                    return $failureCode;
                }
            }
        }

        if ($input->getOption('info')) {
            $failureCode = $this->printPhpInfo();
            if ($failureCode) {
                return $failureCode;
            }
        }

        return Command::SUCCESS;
    }

    protected function swapToVersion(string $version): int|bool
    {
        $output = $this->getVar('output');
        $oldVersion = $this->getVersion();

        if ($oldVersion === $version) {
            $output->writeln("Already using version $version - skipping.");
            return false;
        }

        $output->writeln("Swapping PHP from $oldVersion to $version.");

        $command = <<<EOL
        rm /etc/alternatives/php && \\
        ln -s /usr/bin/php{$version} /etc/alternatives/php && \\
        rm /etc/apache2/mods-enabled/php{$oldVersion}.conf && \\
        rm /etc/apache2/mods-enabled/php{$oldVersion}.load && \\
        ln -s /etc/apache2/mods-available/php$version.conf /etc/apache2/mods-enabled/php$version.conf && \\
        ln -s /etc/apache2/mods-available/php$version.load /etc/apache2/mods-enabled/php$version.load && \\
        /etc/init.d/apache2 reload
        EOL;

        // Run the command
        return $this->runDockerCommand($command, true, null, true);
    }

    protected function getVersion(): string
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL);
        $failure = $this->runDockerCommand('realpath /etc/alternatives/php', false, $output);
        $versionFile = $output->fetch();
        if ($failure || $versionFile === '') {
            throw new RuntimeException('Error fetching PHP version');
        }
        return trim(str_replace('/usr/bin/php', '', $versionFile));
    }

    protected function toggleDebug(): int|bool
    {
        $output = $this->getVar('output');
        $value = 'zend_extension=xdebug.so';
        $version = $this->getVersion();
        $onOff = 'on';
        if ($this->debugIsEnabled($version)) {
            $onOff = 'off';
            $value = ';' . $value;
        }
        $path = $this->getDebugPath($version);
        $output->writeln("Turning debug $onOff");
        $command = "echo \"$value\" > \"{$path}\" && /etc/init.d/apache2 reload";
        return $this->runDockerCommand($command, true);
    }

    protected function debugIsEnabled(?string $phpVersion = null): bool
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE);
        $version ??= $this->getVersion();
        $path = $this->getDebugPath($version);
        $failure = $this->runDockerCommand("cat {$path}", false, $output);
        if ($failure) {
            throw new RuntimeException('Error fetching debug status');
        }
        $debug = $output->fetch();
        return $debug !== '' && !str_starts_with($debug, ';');
    }

    protected function getDebugPath(string $phpVersion): string
    {
        return "/etc/php/{$phpVersion}/mods-available/xdebug.ini";
    }

    protected function printPhpInfo(): int|bool
    {
        return $this->runDockerCommand('php -i');
    }

    protected function runDockerCommand(string $command, bool $asRoot = false, ?OutputInterface $output = null, bool $requiresRestart = false): int|bool
    {
        $quiet = false;
        if (!$output) {
            $quiet = true;
            $output = $this->getVar('output');
        }
        $dockerService = new DockerService($this->getVar('env'), $this->processHelper, $output);
        if ($quiet) {
            $output->writeln("Running command in docker container: '$command'");
        }

        $success = $dockerService->exec($command, $asRoot);
        if (!$success) {
            $this->getVar('output')->writeln('ERROR: Problem occured while running command in docker container.');
            return Command::FAILURE;
        }

        if ($requiresRestart) {
            sleep(1);
            $success = $dockerService->up(false);
            if (!$success) {
                $this->getVar('output')->writeln('ERROR: Could not restart container.');
                return Command::FAILURE;
            }
        }

        return false;
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setHelp(static::$defaultDescription);
        $this->addOption(
            'php-version',
            'v',
            InputOption::VALUE_OPTIONAL,
            'Swap to a specific PHP version.',
        );
        $this->addOption(
            'toggle-debug',
            'd',
            InputOption::VALUE_NONE,
            'Toggle xdebug on/off.',
        );
        $this->addOption(
            'info',
            'i',
            InputOption::VALUE_NONE,
            'Print out phpinfo (for webserver - assumed same for cli).',
        );
        $this->addOption(
            'values-only',
            'o',
            InputOption::VALUE_NONE,
            'Make no changes - only print out current values.',
        );
        $this->addArgument(
            'env-path',
            InputArgument::OPTIONAL,
            'The full path to the directory of the environment to destroy.',
            './'
        );
    }

}
