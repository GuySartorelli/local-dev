<?php

namespace DevTools\Command\Env;

use Composer\Semver\Semver;
use DevTools\Command\BaseCommand;
use DevTools\Command\UsesPassword;
use DevTools\Utility\ComposerJsonService;
use DevTools\Utility\Config;
use DevTools\Utility\DockerService;
use DevTools\Utility\Environment;
use DevTools\Utility\GitHubService;
use DevTools\Utility\PHPService;
use Gitonomy\Git\Exception\ProcessException;
use Gitonomy\Git\Repository;
use LogicException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Console\Command\Command;
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

class Up extends BaseCommand
{
    use UsesPassword;

    protected static $defaultName = 'env:up';

    protected static $defaultDescription = 'Sets up a new docker environment with a webhost.';

    /**
     * Used to define short names to easily select common recipes
     */
    protected static array $recipeShortcuts = [
        'sink' => 'silverstripe/recipe-kitchen-sink',
        'installer' => 'silverstripe/installer',
    ];

    /**
     * Characters that cannot be used for an environment name
     */
    protected static string $invalidEnvNameChars = ' !@#$%^&*()"\',.<>/?:;\\';

    protected static bool $notifyOnCompletion = true;

    protected Filesystem $filesystem;

    private array $composerArgs = [];

    /**
     * @inheritDoc
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        parent::initialize($input, $output);
        $this->filesystem = new Filesystem();
        $this->normaliseRecipe();
        if (str_contains($input->getOption('composer-args') ?? '', '--no-install') && !empty($input->getOption('pr'))) {
            $this->getVar('io')->warning('Composer --no-install has been set. Cannot checkout PRs.');
            $this->setVar('prs', []);
        } else {
            $this->setVar('prs', GitHubService::getPullRequestDetails($input->getOption('pr')));
        }
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
        $this->setVar('env', $environment = new Environment(Path::join($input->getOption('project-path'), $envName), true));

        // Take this first, so that if there's an uncaught exception it doesn't prevent creating
        // the next environment.
        Config::takeSuffix($suffix);

        // Raw environment dir setup
        $failureCode = $this->prepareEnvDir();
        if ($failureCode) {
            Config::releaseSuffix($environment->getSuffix());
            return $failureCode;
        }

        // Create webdir so that docker has it for use in its volume
        $this->filesystem->mkdir($environment->getWebRoot());

        // Docker stuff
        $failureCode = $this->spinUpDocker();
        if ($failureCode) {
            Config::releaseSuffix($environment->getSuffix());
            $this->filesystem->remove($environment->getBaseDir());
            return $failureCode;
        }

        // Prepare webroot (includes running composer commands)
        $failureCode = $this->prepareWebRoot();
        if ($failureCode) {
            return $failureCode;
        }

        // Checkout PRs if there are any
        $this->handlePRs();

        // Update hosts file
        $this->updateHosts();

        // Run dev/build
        $this->buildDatabase();

        $io->success('Completed successfully.');
        $url = $environment->getBaseURL() . '/';
        $io->writeln(self::STEP_STYLE . "Navigate to <href=$url>$url</></>");
        return Command::SUCCESS;
    }

    protected function buildDatabase(): bool
    {
        $input = $this->getVar('input');
        /** @var SymfonyStyle $io */
        $io = $this->getVar('io');
        /** @var Environment $environment */
        $environment = $this->getVar('env');

        if (!str_contains($input->getOption('composer-args') ?? '', '--no-install')) {
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
        } else {
            $sakeReturn = Command::INVALID;
        }
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

    protected function prepareEnvDir(): bool
    {
        /** @var SymfonyStyle $io */
        $io = $this->getVar('io');
        /** @var Environment $environment */
        $environment = $this->getVar('env');
        $envPath = $environment->getBaseDir();
        if (is_dir($envPath)) {
            $io->error('Environment path already exists: ' . $envPath);
            return Command::INVALID;
        }
        $io->writeln(self::STEP_STYLE . "Making directory '$envPath'</>");
        try {
            $logsDir = Path::join($envPath, 'logs');
            $this->filesystem->mkdir([$envPath, $logsDir, Path::join($logsDir, 'apache2')]);
        } catch (IOException $e) {
            $io->error('Couldn\'t create environment directory: ' . $e->getMessage());
            if ($io->isVeryVerbose()) {
                $io->writeln($e->getTraceAsString());
            }
            return Command::FAILURE;
        }
        return false;
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

        $failureCode = $this->buildComposerProject();
        if ($failureCode) {
            return $failureCode;
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
        $io->writeln(self::STEP_STYLE . 'Setting appropriate PHP version.</>');
        if ($phpVersion = $input->getOption('php-version')) {
            if (PHPService::versionIsAvailable($phpVersion)) {
                $this->usePHPVersion($phpVersion);
            } else {
                $io->warning("PHP $phpVersion is not available. Using default.");
            }
            return;
        }

        // Get the php version for the selected recipe and version
        $recipe = $input->getOption('recipe');
        $command = "composer show -a -f json {$recipe} {$input->getOption('constraint')}";
        $dockerReturn = $this->runDockerCommand($command, suppressMessages: !$io->isVerbose());
        if ($dockerReturn === Command::FAILURE) {
            $io->warning('Could not fetch PHP version from composer. Using default.');
            return;
        }
        // Rip out any composer nonsense before the JSON actually starts, then parse
        $composerJson = json_decode(preg_replace('/^[^{]*/', '', $dockerReturn), true);
        if (!isset($composerJson['requires']['php'])) {
            $io->warning("$recipe doesn't have an explicit PHP dependency to check against. Using default.");
            return;
        }
        $constraint = $composerJson['requires']['php'];
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
        /** @var Environment $environment */
        $environment = $this->getVar('env');
        /** @var BaseCommand $phpConfig */
        $phpConfig = $this->getApplication()->find('php');
        $phpConfig->setIsSubCommand(true);
        $args = [
            '--php-version' => $phpVersion,
            'env-path' => $environment->getBaseDir(),
        ];
        return $phpConfig->run(new ArrayInput($args), $this->getVar('output'));
    }

    protected function buildComposerProject(): int|bool
    {
        /** @var InputInterface $input */
        $input = $this->getVar('input');
        /** @var SymfonyStyle $io */
        $io = $this->getVar('io');
        $io->writeln(self::STEP_STYLE . 'Building composer project</>');

        $composerCommand = $this->prepareComposerCommand('create-project');

        // Run composer command
        $result = $this->runDockerCommand(implode(' ', $composerCommand), $this->getVar('output'), suppressMessages: !$io->isVerbose());
        if ($result === Command::FAILURE) {
            $io->error('Couldn\'t create composer project.');
            return $result;
        }

        // Install postgres if appropriate
        if ($input->getOption('db') === 'postgres') {
            $composerCommand = [
                'composer',
                'require',
                'silverstripe/postgresql',
                ...$this->prepareComposerArgs('require'),
            ];

            // Run composer command
            $result = $this->runDockerCommand(implode(' ', $composerCommand), $this->getVar('output'), suppressMessages: !$io->isVerbose());
            if ($result === Command::FAILURE) {
                $io->error('Couldn\'t require postgres module.');
                return $result;
            }
        }

        return false;
    }

    /**
     * Prepares arguments for a composer command that will result in installing dependencies
     */
    private function prepareComposerArgs(string $commandType): array
    {
        if (!$this->composerArgs) {
            /** @var InputInterface $input */
            $input = $this->getVar('input');
            // Prepare composer command
            $this->composerArgs = [
                '--no-interaction',
                ...explode(' ', $input->getOption('composer-args') ?? '')
            ];
            if ($input->getOption('prefer-source')) {
                $this->composerArgs[] = '--prefer-source';
            }
        }
        $args = $this->composerArgs;

        // Don't install on create-project if a PR has dependencies
        if ($commandType === 'create-project' && $input->getOption('pr-has-deps') && !empty($this->getVar('prs'))) {
            $args[] = '--no-install';
        }

        // composer install can't take --no-audit, but we don't want to include audits in other commands.
        if ($commandType !== 'install') {
            $args[] = '--no-audit';
        }

        // Make sure --no-install isn't in there twice.
        return array_unique($args);
    }

    /**
     * Prepares a composer install or create-project command
     *
     * @param string $commandType - should be install or create-project
     */
    private function prepareComposerCommand(string $commandType)
    {
        /** @var InputInterface $input */
        $input = $this->getVar('input');
        $composerArgs = $this->prepareComposerArgs($commandType);
        $command = [
            'composer',
            $commandType,
            ...$composerArgs
        ];
        if ($commandType === 'create-project') {
            $command[] = $input->getOption('recipe') . ':' . $input->getOption('constraint');
            $command[] = './';
        }
        return $command;
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

    protected function handlePRs(): int|bool
    {
        /** @var Environment $env */
        $env = $this->getVar('env');
        /** @var SymfonyStyle $io */
        $io = $this->getVar('io');
        /** @var InputInterface $input */
        $input = $this->getVar('input');
        $prs = $this->getVar('prs');
        if (empty($prs)) {
            return false;
        }

        if ($input->getOption('pr-has-deps')) {
            // Add prs to composer.json
            $io->writeln(self::STEP_STYLE . 'Adding PRs to composer.json</>');
            $composerService = new ComposerJsonService($env->getBaseDir());
            $composerService->addForks($prs);
            $composerService->addForkedDeps($prs);

            // Run composer install
            $io->writeln(self::STEP_STYLE . 'Running composer install now that dependencies have been defined</>');
            $composerCommand = $this->prepareComposerCommand('install');
            $result = $this->runDockerCommand(implode(' ', $composerCommand), $this->getVar('output'), suppressMessages: !$io->isVerbose());
            if ($result === Command::FAILURE) {
                $io->error('Couldn\'t run composer install.');
                return $result;
            }
            return false;
        }

        return $this->checkoutPRs($prs);
    }

    protected function checkoutPRs(array $prs): int|bool
    {
        /** @var SymfonyStyle $io */
        $io = $this->getVar('io');
        /** @var Environment $environment */
        $environment = $this->getVar('env');
        $returnVal = false;
        foreach ($prs as $composerName => $details) {
            $io->writeln(self::STEP_STYLE . 'Setting up PR for ' . $composerName . '</>');
            $io->writeln(self::STEP_STYLE . 'Setting remote ' . $details['remote'] . ' as "pr" and checking out branch ' . $details['prBranch'] . '</>');
            $prPath = Path::join($environment->getWebRoot(), 'vendor', $composerName);
            if (!$this->filesystem->exists($prPath)) {
                // Try composer require-ing it - and if that fails, toss out a warning about it and move on.
                $io->writeln(self::STEP_STYLE . $composerName . ' is not yet added as a dependency - requiring it.</>');
                $result = $this->runDockerCommand('composer require --prefer-source ' . $composerName, $this->getVar('output'), suppressMessages: !$io->isVerbose());
                if ($result) {
                    $this->failCheckout($io, $composerName, $returnVal);
                    continue;
                }
            }

            $subCommand = $this->getApplication()->find('git-set-remotes');
            $args = [
                'env-path' => $prPath,
                '--no-fetch',
                '--no-include-security',
            ];
            $subCommandReturn = $subCommand->run(new ArrayInput($args), $this->getVar('output'));
            if ($subCommandReturn !== Command::SUCCESS) {
                $this->failCheckout($io, $composerName, $returnVal);
                continue;
            }

            try {
                $gitRepo = new Repository($prPath);
                $gitRepo->run('remote', ['add', 'pr', $details['remote']]);
                $gitRepo->run('fetch', ['--all']);
                $gitRepo->getWorkingCopy()->checkout('pr/' . $details['prBranch']);
            } catch (ProcessException $e) {
                $this->failCheckout($io, $composerName, $returnVal);
                continue;
            }
        }
        return $returnVal;
    }

    private function failCheckout(SymfonyStyle $io, string $composerName, mixed &$returnVal): void
    {
        $io->warning('Could not check out PR for ' . $composerName . ' - please check out that PR manually.');
        $returnVal = Command::FAILURE;
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
            'attached' => false,
        ];

        return $twig->render($template, $variables);
    }

    /**
     * Normalises the recipe to be installed based on static::$recipeShortcuts
     */
    protected function normaliseRecipe(): void
    {
        $input = $this->getVar('input');
        $recipe = $input->getOption('recipe');
        if (isset(static::$recipeShortcuts[$recipe])) {
            $input->setOption('recipe', static::$recipeShortcuts[$recipe]);
        }
    }

    /**
     * Gets the environment name based on the input arguments and options
     */
    protected function getEnvName(): string
    {
        $input = $this->getVar('input');
        $invalidCharsRegex = '/[' . preg_quote(static::$invalidEnvNameChars, '/') . ']/';
        // Use env name if defined
        if ($name = $input->getArgument('env-name')) {
            if (preg_match($invalidCharsRegex, $name)) {
                throw new LogicException(
                    'env-name must not contain the following characters: ' . static::$invalidEnvNameChars
                );
            }
            return $name;
        }

        // Normalise recipe by replacing 'invalid' chars with hyphen
        $recipeParts = explode('-', preg_replace($invalidCharsRegex, '-', $input->getOption('recipe')));
        $recipe = end($recipeParts);
        // Normalise constraints to remove stability flags
        $constraint = preg_replace('/^(dev-|v(?=\d))|-dev|(#|@).*?$/', '', $input->getOption('constraint'));
        $constraint = preg_replace($invalidCharsRegex, '-', trim($constraint, '~^'));
        return $recipe . '_' . $constraint;
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setAliases(['up']);

        $desc = static::$defaultDescription;
        $this->setHelp(<<<HELP
        $desc
        Creates a new environment in the project path using the env name and a unique integer value.
        The integer value is used to ensure directories are unique, as well as for a port for the database server.
        The environment directory contains the docker-compose file, test artifacts, logs, web root, and .env file.
        HELP);
        $this->addArgument(
            'env-name',
            InputArgument::OPTIONAL,
            'The name of the environment. This will be used for the directory and the webhost. '
            . 'Defaults to a name generated based on the recipe and constraint. '
            . 'Must not contain the following characters: ' . static::$invalidEnvNameChars
        );
        $this->addOption(
            'project-path',
            'p',
            InputOption::VALUE_REQUIRED,
            'The path of the parent directory where the env directory will be created.',
            Config::getEnv('DT_DEFAULT_PROJECTS_PATH')
        );
        $recipeDescription = '';
        foreach (static::$recipeShortcuts as $shortcut => $recipe) {
            $recipeDescription .= "\"$shortcut\" ($recipe), ";
        }
        $this->addOption(
            'recipe',
            'r',
            InputOption::VALUE_REQUIRED,
            'The recipe to install. Options: ' . $recipeDescription . 'custom value (e.g. "silverstripe/recipe-cms")',
            Config::getEnv('DT_DEFAULT_INSTALL_RECIPE')
        );
        $this->addOption(
            'constraint',
            'c',
            InputOption::VALUE_REQUIRED,
            'The version constraint to use for the installed recipe.',
            Config::getEnv('DT_DEFAULT_INSTALL_VERSION')
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
        $this->addOption(
            'pr',
            null,
            InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY,
            <<<DESC
            Optional pull request URL or github referece, e.g. "silverstripe/silverstripe-framework#123" or "https://github.com/silverstripe/silverstripe-framework/pull/123"
            If included, the command will checkout out the PR branch in the appropriate vendor package.
            Multiple PRs can be included (for separate modules) by using `--pr` multiple times.
            DESC,
            []
        );
        $this->addOption(
            'pr-has-deps',
            null,
            InputOption::VALUE_NEGATABLE,
            'A PR from the --pr option has dependencies which need to be included in the first composer install.',
            false
        );
        $this->addOption(
            'composer-args',
            'a',
            InputOption::VALUE_OPTIONAL,
            'Any additional arguments to be passed to the composer create-project command.',
            ''
        );
        $this->addOption(
            'prefer-source',
            null,
            InputOption::VALUE_NEGATABLE,
            'Whether to use --prefer-source for composer commands.',
            Config::getEnv('DT_PREFER_SOURCE')
        );
    }
}
