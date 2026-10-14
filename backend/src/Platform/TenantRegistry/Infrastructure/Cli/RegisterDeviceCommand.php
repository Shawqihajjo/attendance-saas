<?php

declare(strict_types=1);

namespace App\Platform\TenantRegistry\Infrastructure\Cli;

use App\Platform\TenantRegistry\Application\DeviceEnrollment;
use App\Platform\TenantRegistry\Domain\TenantLifecycleException;
use App\TenantContext\Domain\TenantNotProvisionedException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:device:register', description: 'Register a pending device against a tenant.')]
final class RegisterDeviceCommand extends Command
{
    public function __construct(private readonly DeviceEnrollment $enrollment)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('slug', InputArgument::REQUIRED);
        $this->addArgument('name', InputArgument::REQUIRED);
        $this->addOption('actor', null, InputOption::VALUE_REQUIRED, 'Platform actor id', 'cli');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $slug = $input->getArgument('slug');
        $name = $input->getArgument('name');
        $actor = $input->getOption('actor');
        try {
            $device = $this->enrollment->register(
                is_string($slug) ? $slug : '',
                is_string($name) ? $name : '',
                is_string($actor) ? $actor : 'cli',
            );
        } catch (TenantNotProvisionedException|TenantLifecycleException $exception) {
            $output->writeln($exception->getMessage());

            return Command::FAILURE;
        }
        $output->writeln($device->id());

        return Command::SUCCESS;
    }
}
