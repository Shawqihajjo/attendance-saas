<?php

declare(strict_types=1);

namespace App\Platform\TenantRegistry\Infrastructure\Cli;

use App\Platform\TenantRegistry\Infrastructure\Persistence\TenantRegistryStore;
use App\TenantContext\Domain\TenantDatabaseSwitcher;
use App\TenantContext\Domain\TenantNotProvisionedException;
use App\TenantContext\Domain\TenantUnavailableException;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:tenant:check', description: 'Open one tenant database by slug, run a probe, and close it.')]
final class CheckTenantCommand extends Command
{
    public function __construct(
        private readonly TenantRegistryStore $store,
        private readonly TenantDatabaseSwitcher $switcher,
        private readonly ManagerRegistry $doctrine,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('tenant', null, InputOption::VALUE_REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $slug = $input->getOption('tenant');
        if (!is_string($slug) || $slug === '') {
            $output->writeln('Pass --tenant=slug.');

            return Command::FAILURE;
        }
        $tenant = $this->store->findTenantBySlug($slug);
        if ($tenant === null) {
            $output->writeln('Unknown tenant.');

            return Command::FAILURE;
        }
        try {
            $this->switcher->switchTo($tenant->id());
            $this->doctrine->getConnection('tenant')->executeQuery('SELECT 1')->fetchOne();
            $output->writeln($tenant->slug().' ok');
        } catch (TenantNotProvisionedException|TenantUnavailableException $exception) {
            $output->writeln($exception->getMessage());

            return Command::FAILURE;
        } finally {
            $this->switcher->reset();
        }

        return Command::SUCCESS;
    }
}
