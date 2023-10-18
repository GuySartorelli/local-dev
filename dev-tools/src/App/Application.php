<?php

namespace DevTools\App;

use ReflectionMethod;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Application extends ConsoleApplication
{
    public function doRun(InputInterface $input, OutputInterface $output): int
    {
        // Add shortcuts for common usage
        if ($input instanceof ArgvInput) {
            $command = $input->getFirstArgument();
            switch ($command) {
                case 'composer':
                    $this->addCommandFirst($input, 'docker', ['-i']);
                    break;
                case 'dev/build':
                    $this->addCommandFirst($input, 'sake');
                    break;
                case 'flush':
                    $this->setArgTokens($input, ['sake', 'dev', 'flush=1']);
                    break;
            }
        }
        return parent::doRun($input, $output);
    }

    private function setArgTokens(ArgvInput $input, array $tokens)
    {
        $reflectionMethod = new ReflectionMethod($input, 'setTokens');
        $reflectionMethod->invoke($input, $tokens);
    }

    private function addCommandFirst(ArgvInput $input, string $command, array $extraArgs = [])
    {
        // Get the arguments and remove the application itself
        $tokens = $argv ?? $_SERVER['argv'] ?? [];
        array_shift($tokens);
        // Add extra args - make sure they immediately follow the command
        foreach (array_reverse($extraArgs) as $arg) {
            array_unshift($tokens, $arg);
        }
        // Add correct command as the first argument
        array_unshift($tokens, $command);
        $this->setArgTokens($input, $tokens);
    }
}
