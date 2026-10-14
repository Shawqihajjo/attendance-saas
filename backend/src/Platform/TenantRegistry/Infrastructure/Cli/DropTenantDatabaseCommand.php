<?php

declare(strict_types=1);

namespace App\Platform\TenantRegistry\Infrastructure\Cli;

use App\Platform\TenantRegistry\Application\TenantLifecycle;
use App\Platform\TenantRegistry\Domain\TenantLifecycleException;
use App\TenantContext\Domain\TenantNotProvisionedException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:tenant:drop-database', description: 'Drop the database of a decommissioned tenant.')]
final class DropTenantDatabaseCommand extends Command
{
    public function __construct(private readonly TenantLifecycle $lifecycle)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('slug', InputArgument::REQUIRED);
        $this->addOption('confirm', null, InputOption::VALUE_NONE, 'Confirm permanent removal of the tenant database');
        $this->addOption('actor', null, InputOption::VALUE_REQUIRED, 'Platform actor id', 'cli');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('confirm') !== true) {
            $output->writeln('Refusing to drop a tenant database without --confirm.');

            return Command::FAILURE;
        }
        $slug = $input->getArgument('slug');
        $actor = $input->getOption('actor');
        try {
            $this->lifecycle->dropDatabase(is_string($slug) ? $slug : '', is_string($actor) ? $actor : 'cli');
        } catch (TenantLifecycleException|TenantNotProvisionedException $exception) {
            $output->writeln($exception->getMessage());

            return Command::FAILURE;
        }
        $output->writeln('dropped');

        return Command::SUCCESS;
    }
}
