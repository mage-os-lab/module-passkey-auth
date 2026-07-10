<?php

declare(strict_types=1);

namespace MageOS\PasskeyAuth\Console\Command;

use MageOS\PasskeyAuth\Model\AdminTfa\Engine;
use Magento\TwoFactorAuth\Api\UserConfigManagerInterface;
use Magento\User\Model\ResourceModel\User\CollectionFactory as UserCollectionFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class ResetPasskeyTfaCommand extends Command
{
    private const PROVIDER_CODES = [
        Engine::PROVIDER_CODE_ALL,
        Engine::PROVIDER_CODE_HARDWARE,
    ];

    public function __construct(
        private readonly UserConfigManagerInterface $userConfigManager,
        private readonly UserCollectionFactory $userCollectionFactory
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('security:tfa:passkey:reset-all');
        $this->setDescription('Reset passkey 2FA configuration for all admin users');
        $this->addOption('force', 'f', InputOption::VALUE_NONE, 'Skip confirmation prompt');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $users = $this->userCollectionFactory->create();
        $resetCount = 0;
        $affectedUsers = [];

        foreach ($users as $user) {
            $userId = (int) $user->getId();
            foreach (self::PROVIDER_CODES as $providerCode) {
                $config = $this->userConfigManager->getProviderConfig($userId, $providerCode);
                if (!empty($config) && isset($config['registration'])) {
                    $affectedUsers[$userId] = $user->getUserName();
                }
            }
        }

        if (empty($affectedUsers)) {
            $output->writeln('<info>No admin users have passkey 2FA configured.</info>');
            return Command::SUCCESS;
        }

        $output->writeln(sprintf(
            '<comment>Found %d admin user(s) with passkey 2FA configured:</comment>',
            count($affectedUsers)
        ));
        foreach ($affectedUsers as $username) {
            $output->writeln('  - ' . $username);
        }

        if (!$input->getOption('force')) {
            $helper = $this->getHelper('question');
            $question = new ConfirmationQuestion(
                '<question>Reset passkey 2FA for all listed users? [y/N]</question> ',
                false
            );
            if (!$helper->ask($input, $output, $question)) {
                $output->writeln('<info>Aborted.</info>');
                return Command::SUCCESS;
            }
        }

        foreach (array_keys($affectedUsers) as $userId) {
            foreach (self::PROVIDER_CODES as $providerCode) {
                $config = $this->userConfigManager->getProviderConfig($userId, $providerCode);
                if (!empty($config) && isset($config['registration'])) {
                    $this->userConfigManager->resetProviderConfig($userId, $providerCode);
                    $resetCount++;
                }
            }
        }

        $output->writeln(sprintf(
            '<info>Reset %d passkey configuration(s) for %d admin user(s).</info>',
            $resetCount,
            count($affectedUsers)
        ));

        return Command::SUCCESS;
    }
}
