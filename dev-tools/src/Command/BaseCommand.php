<?php

namespace DevTools\Command;

use InvalidArgumentException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\Output;
use Symfony\Component\Console\Output\OutputInterface;

abstract class BaseCommand extends Command
{
    private array $commandVars = [];

    /**
     * @inheritDoc
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        if (!$input->getOption('quiet')) {
            $output->setVerbosity(Output::VERBOSITY_DEBUG);
        }
        $this->resetVars();
        $this->setVar('input', $input);
        $this->setVar('output', $output);
    }

    protected function resetVars(): void
    {
        $this->commandVars = [];
    }

    protected function getVar(string $varName): mixed
    {
        if (!array_key_exists($varName, $this->commandVars)) {
            throw new InvalidArgumentException("var '$varName' has not been set");
        }
        return $this->commandVars[$varName];
    }

    protected function setVar(string $varName, mixed $value): void
    {
        $this->commandVars[$varName] = $value;
    }
}
