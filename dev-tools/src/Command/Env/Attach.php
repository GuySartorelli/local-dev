<?php

namespace DevTools\Command\Env;

use Composer\Semver\Semver;
use DevTools\Command\BaseCommand;
use DevTools\Command\UsesPassword;
use DevTools\Utility\Config;
use DevTools\Utility\DockerService;
use DevTools\Utility\Environment;
use DevTools\Utility\PHPService;
use DevTools\Utility\ProcessOutputter;
use LogicException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProcessHelper;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Twig\Environment as TwigEnvironment;
use Twig\Loader\FilesystemLoader;

class Attach extends BaseCommand
{
    use UsesPassword;

    protected static $defaultName = 'env:attach';

    protected static $defaultDescription = 'Sets up a new docker environment with a webhost onto an existing project.';

    /**
     * Characters that cannot be used for an environment name
     */
    protected static string $invalidEnvNameChars = ' !@#$%^&*()"\',.<>/?:;\\';

    protected static bool $notifyOnCompletion = true;

    protected Filesystem $filesystem;

    protected ProcessHelper $processHelper;

    protected ProcessOutputter $outputter;

    /**
     * @inheritDoc
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        parent::initialize($input, $output);
        $this->filesystem = new Filesystem();
        $this->setVar('project-path', $projectPath = Path::makeAbsolute(Path::canonicalize($input->getArgument('project-path')), getcwd()));
        if (!$this->filesystem->exists($projectPath) || !is_dir($projectPath)) {
            throw new LogicException("Project path doesn't exist or isn't a directory: $projectPath");
        }
        if ($this->filesystem->exists(Path::join($projectPath, Environment::ATTACHED_ENV_FILE))) {
            throw new LogicException('Project has already been attached.');
        }
        $this->processHelper = $this->getHelper('process');
        $this->outputter = new ProcessOutputter($output);
        $twigLoader = new FilesystemLoader(Config::getTemplateDir());
        $this->setVar('twig', new TwigEnvironment($twigLoader));
        // Need password to update hosts - get that first so user can walk away while the rest processes.
        $this->setVar('password', $this->getPassword());
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var SymfonyStyle $io */
        $io = $this->getVar('io');
        $suffix = Config::getNextAvailableSuffix();
        $envName = $this->getEnvName() . '_' . $suffix;
        $projectPath = $this->getVar('project-path');

        $this->filesystem->mkdir(Path::join($projectPath, 'logs/apache2'));
        $this->filesystem->dumpFile(
            $attachedEnvFile = Path::join($projectPath, Environment::ATTACHED_ENV_FILE),
            $envName,
        );

        $this->setVar('env', $environment = new Environment($projectPath, true));

        // Take this first, so that if there's an uncaught exception it doesn't prevent creating
        // the next environment.
        Config::takeSuffix($suffix);

        // Docker stuff
        $failureCode = $this->spinUpDocker();
        if ($failureCode) {
            Config::releaseSuffix($environment->getSuffix());
            $this->filesystem->remove($attachedEnvFile);
            $this->filesystem->remove($environment->getDockerDir());
            return $failureCode;
        }

        // Prepare webroot (adds necessary config files)
        $failureCode = $this->prepareWebRoot();
        if ($failureCode) {
            Config::releaseSuffix($environment->getSuffix());
            $this->filesystem->remove($attachedEnvFile);
            $this->filesystem->remove($environment->getDockerDir());
            return $failureCode;
        }

        // Update hosts file
        $this->updateHosts();

        // Run dev/build
        $this->buildDatabase();

        $io->success('Attached successfully.');
        $url = $environment->getBaseURL() . '/';
        $io->writeln(self::STEP_STYLE . "Navigate to <href=$url>$url</></>");
        return Command::SUCCESS;
    }

    protected function buildDatabase(): bool
    {
        /** @var SymfonyStyle $io */
        $io = $this->getVar('io');
        /** @var Environment $environment */
        $environment = $this->getVar('env');

        // run vendor/bin/sake dev/build in the docker container
        $io->writeln(self::STEP_STYLE . 'Building database.</>');
        /** @var BaseCommand $sake */
        $sake = $this->getApplication()->find('sake');
        $sake->setIsSubCommand(true);
        $args = [
            '--env-path' => $environment->getBaseDir(),
            'task' => ['dev/build'],
        ];
        $sakeReturn = $sake->run(new ArrayInput($args), $this->getVar('output'));

        if ($sakeReturn !== Command::SUCCESS) {
            $url = "{$environment->getBaseURL()}/dev/build";

            // Can't use $io->warning() because it escapes the link into plain text
            $io->block([
                'Unable to build the db.',
                "Build the db by going to <href=$url>$url</>",
                'Or run: dev-tools sake dev/build -p ' . $environment->getBaseDir(),
            ], 'WARNING', 'fg=black;bg=yellow', ' ', true, false);
        }
        return $sakeReturn === Command::SUCCESS;
    }

    protected function prepareWebRoot(): int|bool
    {
        /** @var SymfonyStyle $io */
        $io = $this->getVar('io');
        /** @var Environment $environment */
        $environment = $this->getVar('env');
        $webDir = $environment->getWebRoot();

        $this->setPHPVersion();

        if ($githubToken = Config::getEnv('DT_GITHUB_TOKEN')) {
            $io->writeln(self::STEP_STYLE . 'Adding github token to composer</>');
            $failureCode = $this->runDockerCommand(
                "composer config -g github-oauth.github.com $githubToken",
                $this->getVar('output'),
                suppressMessages: !$io->isVerbose()
            );
            if ($failureCode) {
                return $failureCode;
            }
        }

        try {
            // Setup environment-specific web files
            $io->writeln(self::STEP_STYLE . 'Preparing extra webroot files</>');
            // Copy files that don't rely on variables
            $this->filesystem->mirror(Path::join(Config::getCopyDir(), 'webroot'), $webDir, options: ['override' => true]);
            // Render twig templates for anything else
            $templateRoot = Path::join(Config::getTemplateDir(), 'webroot');
            $this->renderTemplateDir($templateRoot, $webDir);
        } catch (IOException $e) {
            $io->error('Couldn\'t set up webroot files: ' . $e->getMessage());
            if ($io->isVeryVerbose()) {
                $io->writeln($e->getTraceAsString());
            }
            return Command::FAILURE;
        }

        return false;
    }

    protected function setPHPVersion()
    {
        /** @var SymfonyStyle $io */
        $io = $this->getVar('io');
        /** @var InputInterface $input */
        $input = $this->getVar('input');
        /** @var Environment $env */
        $env = $this->getVar('env');
        $io->writeln(self::STEP_STYLE . 'Setting appropriate PHP version.</>');
        if ($phpVersion = $input->getOption('php-version')) {
            if (PHPService::versionIsAvailable($phpVersion)) {
                $this->usePHPVersion($phpVersion);
            } else {
                $io->warning("PHP $phpVersion is not available. Using default.");
            }
            return;
        }

        // Get the php version for the existing project
        $composerJson = $env->getComposerJson(true);
        if (!isset($composerJson['require']['php'])) {
            $io->warning("Project doesn't have an explicit PHP dependency to check against. Using default.");
            return;
        }
        $constraint = $composerJson['require']['php'];
        if ($io->isVerbose()) {
            $io->writeln("Constraint for PHP is $constraint.");
        }

        // Try each installed PHP version against the allowed versions
        foreach (PHPService::getAvailableVersions() as $phpVersion) {
            if (!Semver::satisfies($phpVersion, $constraint)) {
                if ($io->isVerbose()) {
                    $io->writeln("PHP $phpVersion doesn't satisfy the constraint. Skipping.");
                }
                continue;
            }
            $this->usePHPVersion($phpVersion);
            return;
        }

        $io->warning('Could not set PHP version. Using default.');
    }

    /**
     * Swap to a specific PHP version.
     * Note that because this restarts apache it sometimes results in the docker container exiting with non-0
     */
    private function usePHPVersion(string $phpVersion): int
    {
        /** @var SymfonyStyle $io */
        $io = $this->getVar('io');
        /** @var Environment $environment */
        $environment = $this->getVar('env');
        $dockerService = new DockerService($environment, $this->getVar('output'));

        // Docker containers sometimes take a bit to get started up, so wait for them.
        $ready = false;
        while (!$ready) {
            $containers = $dockerService->getContainersStatus();
            if (empty($containers)) {
                throw new LogicException('There are no docker containers for this environment');
            }

            $numReady = 0;
            foreach ($containers as $container => $status) {
                if ($status === 'running') {
                    $numReady++;
                } else {
                    $io->writeln("$container is $status");
                }
            }
            if ($numReady >= count($containers)) {
                $ready = true;
            } else {
                // Give docker a few seconds to catch up
                $io->writeln(self::STEP_STYLE . 'Docker containers still booting - waiting 5 seconds</>');
                sleep(5);
            }
        }

        /** @var BaseCommand $phpConfig */
        $phpConfig = $this->getApplication()->find('php');
        $phpConfig->setIsSubCommand(true);
        $args = [
            '--php-version' => $phpVersion,
            'env-path' => $environment->getBaseDir(),
        ];
        return $phpConfig->run(new ArrayInput($args), $this->getVar('output'));
    }

    protected function spinUpDocker(): int|bool
    {
        /** @var SymfonyStyle $io */
        $io = $this->getVar('io');
        /** @var Environment $environment */
        $environment = $this->getVar('env');

        try {
            // Setup docker files
            $dockerDir = $environment->getDockerDir();
            $io->writeln(self::STEP_STYLE . 'Preparing docker directory</>');
            $copyFrom = Path::join(Config::getCopyDir(), 'docker');
            // Copy files that don't rely on variables
            $this->filesystem->mirror($copyFrom, $dockerDir, options: ['override' => true]);
            // Render twig templates for anything else
            $templateRoot = Path::join(Config::getTemplateDir(), 'docker');
            $this->renderTemplateDir($templateRoot, $dockerDir);
        } catch (IOException $e) {
            $io->error('Couldn\'t set up docker or webroot files: ' . $e->getMessage());
            if ($io->isVeryVerbose()) {
                $io->writeln($e->getTraceAsString());
            }
            return Command::FAILURE;
        }

        $io->writeln(self::STEP_STYLE . 'Spinning up docker</>');
        $dockerService = new DockerService($environment, $io);
        $success = $dockerService->up();
        if (!$success) {
            $io->error('Couldn\'t start docker containers.');
            return Command::FAILURE;
        }

        return false;
    }

    protected function updateHosts(): int|bool
    {
        /** @var SymfonyStyle $io */
        $io = $this->getVar('io');
        /** @var Environment $environment */
        $environment = $this->getVar('env');
        $hostname = $environment->getHostName();
        $hostsEntry = "{$environment->getIpAddress()}    $hostname";
        $hadError = true;

        $io->writeln(self::STEP_STYLE . 'Updating hosts file</>');
        if ($password = $this->getVar('password')) {
            // Update hosts file
            exec('echo "' . $password . '" | sudo -S bash -c \'echo "' . $hostsEntry . '" >> /etc/hosts\' 2> /dev/null', $execOut, $hadError);
            if ($execOut) {
                $io->writeln($execOut);
            }
        }
        if ($hadError) {
            $io->warning([
                'Couldn\'t add to hosts entry. Please manually add the following line to /etc/hosts',
                $hostsEntry,
            ]);
            return Command::FAILURE;
        }
        return false;
    }

    /**
     * Render all templates in some directory into some other directory.
     */
    protected function renderTemplateDir(string $templateRoot, string $renderTo): void
    {
        $dirs = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($templateRoot));
        foreach ($dirs as $file) {
            /** @var SplFileInfo $file */
            if ($file->isDir()){
                continue;
            }
            $template = Path::makeRelative($file->getPathname(), Config::getTemplateDir());
            $templateRelative = preg_replace('/(.*)\.twig$/', '$1', Path::makeRelative($file->getPathname(), $templateRoot));
            $outputPath = Path::makeAbsolute($templateRelative, $renderTo);
            $this->filesystem->dumpFile($outputPath, $this->renderTemplate($template));
        }
    }

    /**
     * Render a template
     */
    protected function renderTemplate(string $template): string
    {
        /** @var Environment $environment */
        $environment = $this->getVar('env');
        /** @var TwigEnvironment $twig */
        $twig = $this->getVar('twig');
        /** @var InputInterface $input */
        $input = $this->getVar('input');

        // Prepare template variables
        $hostname = $environment->getHostName();
        $ipParts = explode('.', $environment->getIpAddress());
        array_pop($ipParts);
        $ipPrefix = implode('.', $ipParts);
        $hostParts = explode('.', $hostname);

        $variables = [
            'projectName' => $environment->getName(),
            'suffix' => $environment->getSuffix(),
            'hostName' => $hostname,
            'hostSuffix' => array_pop($hostParts),
            'ipPrefix' => $ipPrefix,
            'database' => $input->getOption('db'),
            'dbVersion' => $input->getOption('db-version'),
            'attached' => true,
        ];

        return $twig->render($template, $variables);
    }

    /**
     * Gets the environment name based on the project dir
     */
    protected function getEnvName(): string
    {
        $invalidCharsRegex = '/[' . preg_quote(static::$invalidEnvNameChars, '/') . ']/';

        // Create env name based on project path
        $dir = basename($this->getVar('project-path'));
        return preg_replace($invalidCharsRegex, '-', $dir);
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setAliases(['attach']);

        $desc = static::$defaultDescription;
        $this->setHelp(<<<HELP
        $desc
        Attaches a working docker web environment to an existing project with a unique integer value.
        The integer value is used for a port for the database server.
        HELP);
        $this->addArgument(
            'project-path',
            InputArgument::OPTIONAL,
            'The path of the project to attach the environment to.',
            './'
        );
        $this->addOption(
            'php-version',
            'P',
            InputOption::VALUE_OPTIONAL,
            'The PHP version to use for this environment. Uses the lowest allowed version by default.'
        );
        $this->addOption(
            'db',
            null,
            InputOption::VALUE_REQUIRED,
            'The database type to be used. Must be one of "mariadb", "mysql", "postgres".',
            'mysql'
        );
        $this->addOption(
            'db-version',
            null,
            InputOption::VALUE_REQUIRED,
            'The version of the database docker image to be used.',
            'latest'
        );
    }
}
