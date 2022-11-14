<?php

namespace DevTools\Command\Env;

use DevTools\Command\BaseCommand;
use DevTools\Command\UsesPassword;
use DevTools\Utility\Config;
use DevTools\Utility\DockerService;
use DevTools\Utility\Environment;
use LogicException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

class Detach extends BaseCommand
{
    use UsesPassword;

    protected static $defaultName = 'env:detach';

    protected static $defaultDescription = 'Detaches an environment that was created with the "attach" command.';

    protected static bool $notifyOnCompletion = true;

    private Filesystem $filesystem;

    /**
     * @inheritDoc
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        parent::initialize($input, $output);
        if (!$this->getVar('env')->isAttachedEnv()) {
            throw new LogicException('Cannot detach from fully constructed environments. Run env:down instead.');
        }
        $this->filesystem = new Filesystem();
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
        /** @var Environment $env */
        $env = $this->getVar('env');

        // Make sure we're not _in_ the environment dir when we destroy it.
        if (Path::isBasePath($env->getBaseDir(), getcwd())) {
            $projectsDir = Path::join($env->getBaseDir(), '../');
            chdir($projectsDir);
        }

        // Pull down docker
        $failureCode = $this->pullDownDocker();
        if ($failureCode) {
            return $failureCode;
        }

        // Delete docker directory
        try {
            $io->writeln(self::STEP_STYLE . 'Removing docker directory</>');
            $this->filesystem->remove($env->getDockerDir());
        } catch (IOException $e) {
            $io->error('Couldn\'t delete docker directory: ' . $e->getMessage());
            return Command::FAILURE;
        }

        // Release suffix now that we aren't using it for the directory.
        // This way if we can't remove the hosts entry we can still re-use this suffix.
        Config::releaseSuffix($env->getSuffix());

        // Remove hosts entry
        $failureCode = $this->cleanUpHosts();
        if ($failureCode) {
            return $failureCode;
        }

        $io->success("Env {$env->getName()} successfully detached.");
        return Command::SUCCESS;
    }

    protected function pullDownDocker(): int|bool
    {
        /** @var SymfonyStyle $io */
        $io = $this->getVar('io');
        $dockerService = new DockerService($this->getVar('env'), $io);
        $io->writeln(self::STEP_STYLE . 'Taking down docker</>');

        $success = $dockerService->down();
        if (!$success) {
            $io->error('Problem occured while stopping docker containers.');
            return Command::FAILURE;
        }

        return false;
    }

    protected function cleanUpHosts(): int|bool
    {
        /** @var SymfonyStyle $io */
        $io = $this->getVar('io');
        /** @var Environment $environment */
        $environment = $this->getVar('env');
        $io->writeln(self::STEP_STYLE . 'Updating hosts file</>');

        $hadError = true;
        if ($password = $this->getVar('password')) {
            // Update hosts file
            $hostsContent = file_get_contents('/etc/hosts');
            $hostsContent = preg_replace(
                '/^' . preg_quote($environment->getIpAddress(), '/') . '\h+' . preg_quote($environment->getName(), '/') . '\..*$/m',
                '',
                $hostsContent
            );
            if ($hostsContent === null) {
                $hadError = true;
            } else {
                exec('echo "' . $password . '" | sudo -S bash -c \'echo "' . trim($hostsContent) . '" > /etc/hosts\' 2> /dev/null', $execOut, $hadError);
                if ($execOut) {
                    $io->writeln($execOut);
                }
            }
        }

        if ($hadError) {
            $io->error('Couldn\'t remove hosts entry. Please manually remove the relevant line in /etc/hosts');
            return Command::FAILURE;
        }

        return false;
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setAliases(['detach']);

        $desc = static::$defaultDescription;
        $this->setHelp(<<<HELP
        $desc
        Detaches from a project by pulling down the docker containers, network, and destroying the volumes,
        deleting the docker directory in the project directory, removing the entry in the hosts file, etc.
        HELP);
        $this->addArgument(
            'env-path',
            InputArgument::OPTIONAL,
            'The full path to the directory of the environment to destroy.',
            './'
        );
    }
}
