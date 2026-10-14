<?php

declare(strict_types=1);

namespace App\Diagnostics\Infrastructure\Cli;

use App\Diagnostics\ReadinessChecker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:diagnostics', description: 'Report platform database, tenant connection, Redis, and RabbitMQ readiness.')]
final class DiagnosticsCommand extends Command
{
    public function __construct(private readonly ReadinessChecker $readiness)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $report = $this->readiness->report();
        $encoded = json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        $output->writeln($encoded);

        return $report->status === 'ok' ? Command::SUCCESS : Command::FAILURE;
    }
}
