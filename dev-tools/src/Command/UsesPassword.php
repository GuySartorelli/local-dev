<?php

namespace DevTools\Command;

use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

trait UsesPassword
{
    private function getPassword(InputInterface $input, OutputInterface $output): string
    {
        /** @var QuestionHelper $helper */
        $helper = $this->getHelper('question');
        $user = get_current_user();
        $question1 = new Question("[sudo] password for $user: ");
        $question1->setHidden(true);

        do {
            $password = $helper->ask($input, $output, $question1);
        } while (!$password);
        return $password;
    }
}
