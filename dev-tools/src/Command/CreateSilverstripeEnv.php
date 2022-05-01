<?php

namespace DevTools\Command;

use DevTools\Utility\Config;
use DevTools\Utility\Path;
use LogicException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProcessHelper;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Process\Process;

class CreateSilverstripeEnv extends Command
{
    protected static $defaultName = 'env-create';

    protected static $defaultDescription = 'Sets up a new Silverstripe docker environment with a webhost.';

    /**
     * Used to define short names to easily select common recipes
     */
    protected static array $recipeShortcuts = [
        'sink' => 'silverstripe/recipe-kitchen-sink',
        'installer' => 'silverstripe/installer',
    ];

    /**
     * Characters that cannot be used for a project name
     */
    protected static string $invalidProjectNameChars = ' !@#$%^&*()"\',.<>/?:;';

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // TODO refactor this into smaller methods
        $this->normaliseRecipe($input);
        $suffix = Config::getNextAvailableSuffix();
        /** @var ProcessHelper $processHelper */
        $processHelper = $this->getHelper('process');

        // Prepare project dir
        $projectName = $this->getProjectName($input);
        $projectPath = Path::join([
            $input->getOption('project-path'),
            $projectName . '_' . $suffix,
        ]);
        // TODO check if directory exists - if it does, complain to user and return Command::FAILURE;
        // $success = mkdir($projectPath, recursive: true); TODO
        $success = true;
        if (!$success) {
            // TODO revert to original state.
            $output->writeln('ERROR: Couldn\'t create project directory.');
            return Command::FAILURE;
        }


        // Prepare composer command
        $composerArgs = ['--no-interaction'];
        if ($input->getOption('prefer-source')) {
            $composerArgs[] = '--prefer-source';
        }
        $composerCommand = [
            'composer',
            'create-project',
            $input->getOption('recipe') . ':' . $input->getOption('constraint'),
            Path::join([$projectPath, 'www']),
            ...$composerArgs,
        ];

        // Run composer command
        $output->writeln([
            'Running command:',
            implode(' ', $composerCommand),
        ]);
        // TODO
        // $process = $processHelper->run($output, $composerCommand);
        // $hadError = (bool) $process->getExitCode();
        $hadError = false;
        if ($hadError) {
            // TODO revert to original state.
            $output->writeln('ERROR: Couldn\'t create composer project.');
            return Command::FAILURE;
        }

        // TODO docker stuff
        // Lots of string replacements to do
        // copy(Path::join([Config::getBaseDir(), 'docker/']), Path::join([$projectPath, 'docker']));

        // Update hosts file
        // TODO verify this entry hasn't already been added
        $hostname = $projectName . '.' . $input->getOption('host-suffix');
        $ipAddress = "10.0.$suffix.50";
        $hostsEntry = "$hostname    $ipAddress";

        /** @var QuestionHelper $helper */
        $helper = $this->getHelper('question');
        $question1 = new Question('[sudo] password for gsartorelli: ');
        $question1->setHidden(true);

        $password = $helper->ask($input, $output, $question1);
        // TODO
        // exec('echo "' . $password . '" | sudo -S bash -c \'echo "' . $hostsEntry . '" >> /etc/hosts\' 2> /dev/null', $execOut, $hadError);
        // if ($execOut) {
        //     $output->writeln($execOut);
        // }
        unset($password);
        if ($hadError) {
            // TODO revert to original state?
            $output->writeln('ERROR: Couldn\'t add to hosts entry.');
            return Command::FAILURE;
        }



        // Config::takeSuffix($suffix); TODO
        $startCommand = [
            'docker-compose',
            'up',
            '-d',
        ];
        // $processHelper->run($output, $startCommand); TODO
        // docker-compose up -d TODO
        // $hadError = (bool) $process->getExitCode();
        if ($hadError) {
            $output->writeln('ERROR: Couldn\'t start docker containers.');
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Normalises the recipe to be installed based on static::$recipeShortcuts
     */
    protected function normaliseRecipe(InputInterface $input): void
    {
        $recipe = $input->getOption('recipe');
        if (isset(static::$recipeShortcuts[$recipe])) {
            $input->setOption('recipe', static::$recipeShortcuts[$recipe]);
        }
    }

    /**
     * Gets the project name based on the input arguments and options
     */
    protected function getProjectName(InputInterface $input): string
    {
        $invalidCharsRegex = '/[' . preg_quote(static::$invalidProjectNameChars, '/') . ']/';
        // Use project name if defined
        if ($name = $input->getArgument('project-name')) {
            // TODO validate against duplicate project names
            if (preg_match($invalidCharsRegex, $name)) {
                throw new LogicException(
                    'project-name must not contain the following characters: ' . static::$invalidProjectNameChars
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
        $desc = static::$defaultDescription;
        $this->setHelp(<<<HELP
        $desc
        Creates a new project in the project path using the project name and a unique integer value.
        The integer value is used to ensure directories are unique, as well as for a port for the database server.
        The project directory contains the docker-compose file, test artifacts, logs, web root, and .env file.
        HELP);
        $this->addArgument(
            'project-name',
            InputArgument::OPTIONAL,
            'The name of the project. This will be used for the directory and the webhost. '
            . 'Defaults to a name generated based on the recipe and constraint. '
            . 'Must not contain the following characters: ' . static::$invalidProjectNameChars
        );
        $this->addOption(
            'project-path',
            'p',
            InputOption::VALUE_REQUIRED,
            'The path of the parent directory where the env directory will be created.',
            Config::getEnv('DT_DEFAULT_PROJECTS_PATH')
        );
        $this->addOption(
            'host-suffix',
            's',
            InputOption::VALUE_REQUIRED,
            'The suffix for the site host.',
            Config::getEnv('DT_DEFAULT_HOST_SUFFIX')
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
            'prefer-source',
            null,
            InputOption::VALUE_NEGATABLE,
            'Whether to use --prefer-source for composer commands.',
            Config::getEnv('DT_PREFER_SOURCE')
        );
    }
}
