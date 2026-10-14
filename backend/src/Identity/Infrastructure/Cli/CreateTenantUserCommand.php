<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Cli;

use App\Identity\Application\OpenAccounts;
use App\Platform\TenantRegistry\Domain\InvalidTenantInputException;
use App\Platform\TenantRegistry\Domain\TenantQuotaExceededException;
use App\TenantContext\Domain\TenantNotProvisionedException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:tenant:create-user', description: 'Create a tenant user and linked employee. The password is read from the option and is not stored in the repository.')]
final class CreateTenantUserCommand extends Command
{
    public function __construct(private readonly OpenAccounts $accounts)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('slug', InputArgument::REQUIRED);
        $this->addArgument('email', InputArgument::REQUIRED);
        $this->addArgument('display-name', InputArgument::REQUIRED);
        $this->addOption('password', null, InputOption::VALUE_REQUIRED, 'Password of at least 12 characters');
        $this->addOption('role', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Tenant role', []);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $password = $input->getOption('password');
        $roles = $input->getOption('role');
        $slug = $input->getArgument('slug');
        $email = $input->getArgument('email');
        $displayName = $input->getArgument('display-name');
        if (!is_string($password) || $password === '' || !is_string($slug) || !is_string($email) || !is_string($displayName) || !is_array($roles)) {
            $output->writeln('A password option is required.');

            return Command::FAILURE;
        }
        $roleList = [];
        foreach ($roles as $role) {
            if (!is_string($role)) {
                $output->writeln('Role is not a tenant role.');

                return Command::FAILURE;
            }
            $roleList[] = $role;
        }
        try {
            $account = $this->accounts->tenantUser($slug, $email, $displayName, $password, $roleList);
        } catch (InvalidTenantInputException|UniqueConstraintViolationException|TenantNotProvisionedException|TenantQuotaExceededException $exception) {
            $output->writeln($exception->getMessage());

            return Command::FAILURE;
        }
        $output->writeln($account->id);

        return Command::SUCCESS;
    }
}
