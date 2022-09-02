<?php

namespace DevTools\Command;

use Composer\Semver\Semver;
use Composer\Semver\VersionParser;
use DevTools\Utility\Config;
use DevTools\Utility\DockerService;
use DevTools\Utility\Environment;
use DevTools\Utility\PHPService;
use DevTools\Utility\ProcessOutputter;
use Github\AuthMethod;
use Github\Client as GithubClient;
use InvalidArgumentException;
use LogicException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProcessHelper;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Process\Process;

class Up extends BaseCommand
{
    use UsesPassword;

    protected static $defaultName = 'up';

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
    protected static string $invalidEnvNameChars = ' !@#$%^&*()"\',.<>/?:;';

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
        $this->processHelper = $this->getHelper('process');
        $this->outputter = new ProcessOutputter($output);
        $this->normaliseRecipe();
        if (str_contains($input->getOption('composer-args') ?? '', '--no-install')) {
            $this->getVar('io')->warning('Composer --no-install has been set. Cannot checkout PRs.');
            $this->setVar('prs', []);
        } else {
            $this->initializePRDetails($input->getOption('pr'));
        }
        // Need password to update hosts - get that first so user can walk away while the rest processes.
        $this->setVar('password', $this->getPassword());
    }

    private function initializePRDetails(array $rawPRs)
    {
        if (empty($rawPRs)) {
            $this->setVar('prs', []);
            return;
        }
        $client = new GithubClient();
        if ($token = Config::getEnv('DT_GITHUB_TOKEN')) {
            $client->authenticate($token, AuthMethod::ACCESS_TOKEN);
        }
        $prs = [];
        foreach ($rawPRs as $rawPR) {
            $parsed = $this->parsePr($rawPR);
            $prDetails = $client->pullRequest()->show($parsed['org'], $parsed['repo'], $parsed['pr']);
            $prs[$rawPR] = [
                'details' => array_merge($parsed, [
                    'from-org' => $prDetails['head']['user']['login'],
                    'remote' => $prDetails['head']['repo']['ssh_url'],
                    'branch' => $prDetails['head']['ref'],
                ]),
                'composerName' => $this->getComposerName($client, $parsed),
            ];
        }
        $this->setVar('prs', $prs);
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
        $this->checkoutPRs();

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
            $url = "{$environment->getBaseURL()}/dev/build>";
            $io->warning([
                'Unable to build the db.',
                "Build the db by going to <href=$url>$url</>",
                'Or run: dev-tools sake dev/build -p ' . $environment->getBaseDir(),
            ]);
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
        $failureCode = $this->buildComposerProject();
        if ($failureCode) {
            return $failureCode;
        }
        try {
            // Setup environment-specific web files
            $io->writeln(self::STEP_STYLE . 'Preparing extra webroot files</>');
            $this->filesystem->mirror(Path::join(Config::getCopyDir(), 'webroot'), $webDir, options: ['override' => true]);
            $filesWithPlaceholders = [
                '.env',
            ];
            foreach ($filesWithPlaceholders as $file) {
                $filePath = Path::join($webDir, $file);
                if (!$this->filesystem->exists($filePath)) {
                    $io->error("File '$file' doesn't exist!");
                    return Command::FAILURE;
                }
                $this->replacePlaceholders($filePath);
            }
        } catch (IOException $e) {
            $io->error('Couldn\'t set up webroot files: ' . $e->getMessage());
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
        $input = $this->getVar('input');
        /** @var SymfonyStyle $io */
        $io = $this->getVar('io');
        $io->writeln(self::STEP_STYLE . 'Building composer project</>');

        // Prepare composer command
        $composerArgs = [
            '--no-interaction',
            ...explode(' ', $input->getOption('composer-args') ?? '')
        ];
        if ($input->getOption('prefer-source')) {
            $composerArgs[] = '--prefer-source';
        }
        $composerCommand = [
            'composer',
            'create-project',
            '--no-audit',
            $input->getOption('recipe') . ':' . $input->getOption('constraint'),
            './',
            ...$composerArgs,
        ];

        // Run composer command
        $result = $this->runDockerCommand(implode(' ', $composerCommand), $this->getVar('output'), suppressMessages: !$io->isVerbose());
        if ($result === Command::FAILURE) {
            $io->error('Couldn\'t create composer project.');
            return $result;
        }

        return false;
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
            $copyFrom = Path::join(Config::getCopyDir(), 'docker/');
            $this->filesystem->mirror($copyFrom, $dockerDir, options: ['override' => true]);
            $filesWithPlaceholders = [
                'docker_apache_default',
                'docker-compose.yml',
                'entrypoint',
            ];
            foreach ($filesWithPlaceholders as $file) {
                $filePath = Path::join($dockerDir, $file);
                $this->replacePlaceholders($filePath);
            }
        } catch (IOException $e) {
            $io->error('Couldn\'t set up docker or webroot files: ' . $e->getMessage());
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

    protected function checkoutPRs(): int|bool
    {
        /** @var SymfonyStyle $io */
        $io = $this->getVar('io');
        /** @var Environment $environment */
        $environment = $this->getVar('env');
        $originalDir = getcwd();
        $prs = $this->getVar('prs');
        if (empty($prs)) {
            return false;
        }

        $returnVal = false;
        foreach ($prs as $pr) {
            $io->writeln(self::STEP_STYLE . 'Setting up PR for ' . $pr['composerName'] . '</>');
            $details = $pr['details'];
            $io->writeln(self::STEP_STYLE . 'Setting remote ' . $details['remote'] . ' as "pr" and checking out branch ' . $details['branch'] . '</>');
            $prPath = Path::join($environment->getWebRoot(), 'vendor', $pr['composerName']);
            if (!$this->filesystem->exists($prPath)) {
                // Try composer require-ing it - and if that fails, toss out a warning about it and move on.
                $io->writeln(self::STEP_STYLE . $pr['composerName'] . ' is not yet added as a dependency - requiring it.</>');
                $result = $this->runDockerCommand('composer require ' . $pr['composerName'], $this->getVar('output'), suppressMessages: !$io->isVerbose());
                if ($result) {
                    $io->warning('Could not check out PR for ' . $pr['composerName'] . ' - please check out that PR manually.');
                    $returnVal = Command::FAILURE;
                    continue;
                }
            }
            chdir($prPath);
            $commands = [
                // Add all our normal remotes (just in case) and fetch
                ['git-set-remotes'],
                // Add the PR remote (if this is a security or creative-commoners PR it will override that remote)
                [
                    'git',
                    'remote',
                    'add',
                    'pr',
                    $details['remote'],
                ],
                // Fetch the PR remote
                [
                    'git',
                    'fetch',
                    'pr',
                ],
                // Checkout the PR branch
                [
                    'git',
                    'checkout',
                    '--track',
                    'pr/' . $details['branch'],
                ],
            ];
            foreach ($commands as $command) {
                if ($io->isVerbose()) {
                    $io->writeln(self::STEP_STYLE . 'Running command: ' . implode(' ', $command) . '</>');
                }
                $this->outputter->startCommand();
                $this->processHelper->run(new NullOutput(), new Process($command), callback: [$this->outputter, 'output']);
                $this->outputter->endCommand();
            }
            chdir($originalDir ?: $environment->getBaseDir());
        }
        return $returnVal;
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
     * Replace context-specific placeholders with the relevant bits
     *
     * @throws IOException
     */
    protected function replacePlaceholders(string $filePath): void
    {
        /** @var Environment $environment */
        $environment = $this->getVar('env');
        $hostname = $environment->getHostName();
        $ipParts = explode('.', $environment->getIpAddress());
        array_pop($ipParts);
        $ipPrefix = implode('.', $ipParts);
        $hostParts = explode('.', $hostname);

        $content = file_get_contents($filePath);
        $content = str_replace('${PROJECT_NAME}', $environment->getName(), $content);
        $content = str_replace('${SUFFIX}', $environment->getSuffix(), $content);
        $content = str_replace('${HOST_NAME}', $hostname, $content);
        $content = str_replace('${HOST_SUFFIX}', array_pop($hostParts), $content);
        $content = str_replace('${IP_PREFIX}', $ipPrefix, $content);

        $this->filesystem->dumpFile($filePath, $content);
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
     * Parse a URL or github-shorthand PR reference into an array containing the org, repo, and pr components.
     */
    private function parsePr(string $prRaw): array
    {
        if (!preg_match('@(?<org>[a-zA-Z0-9_-]*)/(?<repo>[a-zA-Z0-9_-]*)(/pull/|#)(?<pr>[0-9]*)$@', $prRaw, $matches)) {
            throw new InvalidArgumentException("'$prRaw' is not a valid github PR reference.");
        }
        return $matches;
    }

    /**
     * Get the composer name of a project from the composer.json of a repo.
     */
    private function getComposerName(GithubClient $client, array $pr): string
    {
        $composerJson = $client->repo()->contents()->download($pr['org'], $pr['repo'], 'composer.json');
        return json_decode($composerJson, true)['name'];
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
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
