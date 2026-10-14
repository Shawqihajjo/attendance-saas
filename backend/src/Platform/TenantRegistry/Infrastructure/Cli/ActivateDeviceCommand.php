<?php

declare(strict_types=1);

namespace App\Platform\TenantRegistry\Infrastructure\Cli;

use App\Platform\TenantRegistry\Application\DeviceEnrollment;
use App\Platform\TenantRegistry\Domain\TenantLifecycleException;
use App\Platform\TenantRegistry\Domain\TenantQuotaExceededException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:device:activate', description: 'Activate a registered device.')]
final class ActivateDeviceCommand extends Command
{
    public function __construct(private readonly DeviceEnrollment $enrollment)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('device-id', InputArgument::REQUIRED);
        $this->addOption('actor', null, InputOption::VALUE_REQUIRED, 'Platform actor id', 'cli');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $deviceId = $input->getArgument('device-id');
        $actor = $input->getOption('actor');
        try {
            $device = $this->enrollment->activate(is_string($deviceId) ? $deviceId : '', is_string($actor) ? $actor : 'cli');
        } catch (TenantLifecycleException|TenantQuotaExceededException $exception) {
            $output->writeln($exception->getMessage());

            return Command::FAILURE;
        }
        $output->writeln($device->tenantId());

        return Command::SUCCESS;
    }
}