<?php

declare(strict_types=1);

namespace App\Platform\TenantRegistry\Infrastructure\Cli;

use App\Platform\TenantRegistry\Application\ProvisionTenant;
use App\Platform\TenantRegistry\Domain\ProvisionTenantRequest;
use App\Platform\TenantRegistry\Domain\TenantProvisioningException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:tenant:provision', description: 'Create a dedicated tenant database and register it.')]
final class ProvisionTenantCommand extends Command
{
    public function __construct(private readonly ProvisionTenant $provisionTenant)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('slug', InputArgument::REQUIRED);
        $this->addArgument('legal-name', InputArgument::REQUIRED);
        $this->addArgument('hostname', InputArgument::REQUIRED);
        $this->addArgument('timezone', InputArgument::REQUIRED);
        $this->addOption('locale', null, InputOption::VALUE_REQUIRED, 'Default locale', 'en');
        $this->addOption('actor', null, InputOption::VALUE_REQUIRED, 'Platform actor id', 'cli');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $locale = $input->getOption('locale');
        $actor = $input->getOption('actor');
        try {
            $tenant = $this->provisionTenant->execute(new ProvisionTenantRequest(
                $this->stringArgument($input, 'slug'),
                $this->stringArgument($input, 'legal-name'),
                $this->stringArgument($input, 'hostname'),
                $this->stringArgument($input, 'timezone'),
                is_string($locale) ? $locale : 'en',
                is_string($actor) ? $actor : 'cli',
            ));
        } catch (TenantProvisioningException $exception) {
            $output->writeln($exception->getMessage());

            return Command::FAILURE;
        }

        $output->writeln($tenant->id());

        return Command::SUCCESS;
    }

    private function stringArgument(InputInterface $input, string $name): string
    {
        $value = $input->getArgument($name);

        return is_string($value) ? $value : '';
    }
}
