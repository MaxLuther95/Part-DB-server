<?php

declare(strict_types=1);

namespace App\Command;

use App\Services\Attachments\PrivateMediaMigration;
use App\Settings\SystemSettings\AttachmentsSettings;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'partdb:security:privatize-media', description: 'Offline: protect native media and disable anonymous permissions (dry run by default).')]
final class PrivatizeMediaCommand extends Command
{
    public function __construct(private readonly PrivateMediaMigration $migration, private readonly AttachmentsSettings $settings)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('apply', null, InputOption::VALUE_NONE)
            ->addOption('offline-confirmed', null, InputOption::VALUE_NONE, 'Confirm HTTP writers are stopped and a database/files backup exists.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $apply = true === $input->getOption('apply');
        if ($apply && (!$input->getOption('offline-confirmed') || !$this->settings->forcePrivateAttachments)) {
            $io->error('Applying requires --offline-confirmed and FORCE_PRIVATE_ATTACHMENTS=1. Stop HTTP writers and take a backup first.');

            return Command::INVALID;
        }
        $result = $this->migration->run($apply);
        $io->success(sprintf('%s: %d attachments, %d public files. Archive: %s', $apply ? 'Applied' : 'Dry run', $result['attachments'], $result['public_files'], $result['archive'] ?? '(not created)'));

        return Command::SUCCESS;
    }
}
