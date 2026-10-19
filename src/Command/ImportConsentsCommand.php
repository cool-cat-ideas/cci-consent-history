<?php

declare(strict_types=1);

namespace Module\CciConsentHistory\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'cci:consent-history:import', description: 'Start or continue a checkpointed account-consent import.')]
final class ImportConsentsCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('shop', null, InputOption::VALUE_REQUIRED, 'Destination shop ID.')
            ->addOption('language', null, InputOption::VALUE_REQUIRED, 'Destination fallback language ID.')
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'Registered importer ID shown in the source integration list.')
            ->addOption('job', null, InputOption::VALUE_REQUIRED, 'Continue this job instead of starting another.')
            ->addOption('backup-confirmed', null, InputOption::VALUE_NONE, 'A current database backup has been saved.')
            ->addOption('apply-wording', null, InputOption::VALUE_NONE, 'Apply the source wording only after all batches succeed.')
            ->addOption('resume', null, InputOption::VALUE_NONE, 'Explicitly resume a paused or failed job.')
            ->addOption('status', null, InputOption::VALUE_NONE, 'Only read the job status.')
            ->addOption('max-batches', null, InputOption::VALUE_REQUIRED, 'Worker budget; use a bounded value in cron.', '100')
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Events per transaction (1 to 500).', '250');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $shop = filter_var($input->getOption('shop'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $budget = filter_var($input->getOption('max-batches'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000000]]);
        $size = filter_var($input->getOption('batch-size'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 500]]);
        if (!$shop || !\Shop::getShop($shop) || !$budget || !$size) {
            $output->writeln('Supply a valid --shop, --max-batches and --batch-size.');

            return Command::INVALID;
        }
        \Shop::setContext(\Shop::CONTEXT_SHOP, $shop);
        \Context::getContext()->shop = new \Shop($shop);
        $module = \Module::getInstanceByName('cci_consent_history');
        if (!$module instanceof \Cci_Consent_History || !$module->active) {
            $output->writeln('CCI Consent History must be installed and enabled.');

            return Command::FAILURE;
        }
        $module->prepareModernAdminPage();
        $manager = $module->getImportManager();

        try {
            $id = (string) $input->getOption('job');
            if ($id === '' && $input->getOption('status')) {
                $job = $manager->latest($shop);
                $output->writeln(json_encode($job, JSON_THROW_ON_ERROR));

                return Command::SUCCESS;
            }
            $job = $id !== '' ? $manager->status($id, $shop) : $manager->start(
                (string) $input->getOption('source'), $shop, (int) $input->getOption('language'),
                $input->getOption('backup-confirmed'), $input->getOption('apply-wording')
            );
            if (!$input->getOption('status') && $input->getOption('resume') && $job['can_resume']) {
                $job = $manager->control($job['job_id'], $shop, 'resume');
            }
            $output->writeln(json_encode($job, JSON_THROW_ON_ERROR));
            for ($i = 0; !$input->getOption('status') && $job['can_continue'] && $i < $budget; ++$i) {
                $job = $manager->step($job['job_id'], $shop, $size);
                $output->writeln(json_encode($job, JSON_THROW_ON_ERROR));
                if (!empty($job['locked'])) {
                    break; // Another browser or worker already owns the next batch.
                }
            }

            return $job['status'] === 'failed' ? Command::FAILURE : Command::SUCCESS;
        } catch (\Module\CciConsentHistory\Import\ImportException $exception) {
            $output->writeln($exception->reason);

            return Command::FAILURE;
        }
    }
}
