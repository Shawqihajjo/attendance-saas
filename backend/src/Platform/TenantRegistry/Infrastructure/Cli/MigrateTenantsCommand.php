<?php

declare(strict_types=1);

namespace App\Platform\TenantRegistry\Infrastructure\Cli;

use App\Platform\TenantRegistry\Application\MigrateTenantDatabases;
use App\Platform\TenantRegistry\Domain\TenantProvisioningException;
use App\TenantContext\Domain\TenantNotProvisionedException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:tenant:migrate', description: 'Migrate tenant databases one at a time.')]
final class MigrateTenantsCommand extends Command
{
    public function __construct(private readonly MigrateTenantDatabases $migrate)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('slug', null, InputOption::VALUE_REQUIRED);
        $this->addOption('all', null, InputOption::VALUE_NONE);
        $this->addOption('actor', null, InputOption::VALUE_REQUIRED, 'Platform actor id', 'cli');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $slug = $input->getOption('slug');
        $all = $input->getOption('all') === true;
        $actor = $input->getOption('actor');
        $actorId = is_string($actor) ? $actor : 'cli';
        $hasSlug = is_string($slug) && $slug !== '';
        if ($hasSlug === $all) {
            $output->writeln('Pass either --slug or --all.');

            return Command::FAILURE;
        }
        try {
            if ($all) {
                $count = $this->migrate->migrateAll($actorId);
                $output->writeln((string) $count);

                return Command::SUCCESS;
            }
            $this->migrate->migrateSlug(is_string($slug) ? $slug : '', $actorId);
        } catch (TenantProvisioningException|TenantNotProvisionedException $exception) {
            $output->writeln($exception->getMessage());

            return Command::FAILURE;
        }
        $output->writeln('migrated');

        return Command::SUCCESS;
    }
}
