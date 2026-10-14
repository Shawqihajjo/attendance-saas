<?php

declare(strict_types=1);

namespace App\Platform\TenantRegistry\Infrastructure\Cli;

use App\Platform\TenantRegistry\Application\DeviceKeyPublisher;
use App\Platform\TenantRegistry\Domain\InvalidTenantInputException;
use App\Platform\TenantRegistry\Domain\TenantLifecycleException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:device:publish-key', description: 'Store a terminal public key and create its tenant projection.')]
final class PublishDeviceKeyCommand extends Command
{
    public function __construct(private readonly DeviceKeyPublisher $publisher)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('device-id', InputArgument::REQUIRED);
        $this->addArgument('public-key', InputArgument::REQUIRED, 'Base64 Ed25519 public key');
        $this->addOption('timezone', null, InputOption::VALUE_REQUIRED, 'IANA timezone', 'Europe/Stockholm');
        $this->addOption('actor', null, InputOption::VALUE_REQUIRED, 'Platform actor id', 'cli');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $deviceId = $input->getArgument('device-id');
        $publicKey = $input->getArgument('public-key');
        $timezone = $input->getOption('timezone');
        $actor = $input->getOption('actor');
        try {
            $this->publisher->publish(
                is_string($deviceId) ? $deviceId : '',
                is_string($publicKey) ? $publicKey : '',
                is_string($timezone) ? $timezone : '',
                is_string($actor) ? $actor : 'cli',
            );
        } catch (TenantLifecycleException|InvalidTenantInputException $exception) {
            $output->writeln($exception->getMessage());

            return Command::FAILURE;
        }
        $output->writeln('published');

        return Command::SUCCESS;
    }
}
