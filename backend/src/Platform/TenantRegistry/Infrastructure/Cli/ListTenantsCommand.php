<?php

declare(strict_types=1);

namespace App\Platform\TenantRegistry\Infrastructure\Cli;

use App\Platform\TenantRegistry\Infrastructure\Persistence\TenantRegistryStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:tenant:list', description: 'List tenants from the platform registry.')]
final class ListTenantsCommand extends Command
{
    public function __construct(private readonly TenantRegistryStore $store)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        foreach ($this->store->allTenants() as $tenant) {
            $domain = $this->store->findPrimaryDomain($tenant->id());
            $connection = $this->store->findConnection($tenant->id());
            $output->writeln(sprintf(
                '%s %s %s %s',
                $tenant->slug(),
                $tenant->status()->value,
                $domain?->hostname() ?? '-',
                $connection?->databaseName() ?? '-',
            ));
        }

        return Command::SUCCESS;
    }
}
