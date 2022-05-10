<?php

namespace DevTools\Command;

use DevTools\Utility\Config;
use DevTools\Utility\Environment;
use LogicException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProcessHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Process\Process;

class DestroyEnv extends BaseCommand
{
    use UsesPassword;

    protected static $defaultName = 'down';

    protected static $defaultDescription = 'Basically undoes the create-env command.';

    private Filesystem $filesystem;

    private ProcessHelper $processHelper;

    /**
     * @inheritDoc
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        parent::initialize($input, $output);
        $this->filesystem = new Filesystem();
        $this->processHelper = $this->getHelper('process');
        // Need password to update hosts - get that first so user can walk away while the rest processes.
        $this->setVar('password', $this->getPassword());
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $proposedPath = Path::makeAbsolute(Path::canonicalize($input->getArgument('env-path')), getcwd());
        try {
            $this->setVar('env', $environment = new Environment($proposedPath));
        } catch (LogicException $e) {
            $output->writeln($e->getMessage());
            return Command::INVALID;
        }

        // Pull down docker
        $failureCode = $this->pullDownDocker();
        if ($failureCode) {
            return $failureCode;
        }

        // Delete environment directory
        try {
            $output->writeln('Removing environment directory');
            $this->filesystem->remove($environment->getBaseDir());
        } catch (IOException $e) {
            $output->writeln('ERROR: Couldn\'t delete environment directory: ' . $e->getMessage());
            return Command::FAILURE;
        }

        // Release suffix now that we aren't using it for the directory.
        // This way if we can't remove the hosts entry we can still re-use this suffix.
        Config::releaseSuffix($environment->getName());

        // Remove hosts entry
        $failureCode = $this->cleanUpHosts();
        if ($failureCode) {
            return $failureCode;
        }

        $output->writeln("Env {$environment->getName()} successfully destroyed.");
        return Command::SUCCESS;
    }

    protected function pullDownDocker(): int|bool
    {
        $output = $this->getVar('output');
        /** @var Environment $environment */
        $environment = $this->getVar('env');
        $output->writeln('Taking down docker');

        $stopCommand = [
            'docker',
            'compose',
            'down',
            '-v',
        ];

        $originalDir = getcwd();
        chdir($environment->getDockerDir());
        $process = new Process($stopCommand);
        $process->setTimeout(null);
        $result = $this->processHelper->run($output, $process);
        chdir($originalDir ?: $environment->getBaseDir());

        if (!$result->isSuccessful()) {
            $output->writeln('ERROR: Problem occured while stopping docker containers.');
            return Command::FAILURE;
        }

        return false;
    }

    protected function cleanUpHosts(): int|bool
    {
        $output = $this->getVar('output');
        /** @var Environment $environment */
        $environment = $this->getVar('env');
        $output->writeln('Updating hosts file');

        $hadError = true;
        if ($password = $this->getVar('password')) {
            // Update hosts file
            $hostsContent = file_get_contents('/etc/hosts');
            preg_replace(
                '/^' . preg_quote($environment->getIpAddress(), '/') . '\h+' . preg_quote($environment->getName(), '/') . '\..*$/',
                '',
                $hostsContent
            );
            exec('echo "' . $password . '" | sudo -S bash -c \'echo "' . $hostsContent . '" > /etc/hosts\' 2> /dev/null', $execOut, $hadError);
            if ($execOut) {
                $output->writeln($execOut);
            }
        }

        if ($hadError) {
            $output->writeln('ERROR: Couldn\'t remove hosts entry. Please manually remove the relevant line in /etc/hosts');
            return Command::FAILURE;
        }

        return false;
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->filesystem = new Filesystem();

        $desc = static::$defaultDescription;
        $this->setHelp(<<<HELP
        $desc
        Removes a project by pulling down the docker containers, network, and destroying the volumes,
        deleting the directory in the project directory, removing the entry in the hosts file, etc.
        HELP);
        $this->addArgument(
            'env-path',
            InputArgument::OPTIONAL,
            'The full path to the directory of the environment to destroy.',
            './'
        );
    }
}
