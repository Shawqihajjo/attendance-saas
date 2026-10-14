<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Cli;

use App\Identity\Application\OpenAccounts;
use App\Platform\TenantRegistry\Domain\InvalidTenantInputException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:platform:create-admin', description: 'Create a platform administrator. The password is read from the option and is not stored in the repository.')]
final class CreatePlatformAdminCommand extends Command
{
    public function __construct(private readonly OpenAccounts $accounts)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED);
        $this->addArgument('display-name', InputArgument::REQUIRED);
        $this->addOption('password', null, InputOption::VALUE_REQUIRED, 'Password of at least 12 characters');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $password = $input->getOption('password');
        $email = $input->getArgument('email');
        $displayName = $input->getArgument('display-name');
        if (!is_string($password) || $password === '' || !is_string($email) || !is_string($displayName)) {
            $output->writeln('A password option is required.');

            return Command::FAILURE;
        }
        try {
            $id = $this->accounts->platformAdmin($email, $displayName, $password);
        } catch (InvalidTenantInputException|UniqueConstraintViolationException $exception) {
            $output->writeln($exception->getMessage());

            return Command::FAILURE;
        }
        $output->writeln($id);

        return Command::SUCCESS;
    }
}
