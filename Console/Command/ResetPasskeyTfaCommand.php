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
    private const PAGE_SIZE = 500;

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
        $affectedUsers = $this->findConfiguredUsers();

        if (empty($affectedUsers)) {
            $output->writeln('<info>No admin users have passkey 2FA configured.</info>');
            return Command::SUCCESS;
        }

        $output->writeln(sprintf(
            '<comment>Found %d admin user(s) with passkey 2FA configured:</comment>',
            count($affectedUsers)
        ));
        foreach ($affectedUsers as $user) {
            $output->writeln('  - ' . $user['username']);
        }

        if (!$input->getOption('force')) {
            $question = new ConfirmationQuestion(
                '<question>Reset passkey 2FA for all listed users? [y/N]</question> ',
                false
            );
            if (!$this->getHelper('question')->ask($input, $output, $question)) {
                $output->writeln('<info>Aborted.</info>');
                return Command::SUCCESS;
            }
        }

        $resetCount = 0;
        foreach ($affectedUsers as $userId => $user) {
            foreach ($user['codes'] as $providerCode) {
                $this->userConfigManager->resetProviderConfig($userId, $providerCode);
                $resetCount++;
            }
        }

        $output->writeln(sprintf(
            '<info>Reset %d passkey configuration(s) for %d admin user(s).</info>',
            $resetCount,
            count($affectedUsers)
        ));

        return Command::SUCCESS;
    }

    /**
     * @return array<int, array{username: string, codes: string[]}>
     */
    private function findConfiguredUsers(): array
    {
        $affectedUsers = [];
        $collection = $this->userCollectionFactory->create();
        $collection->addFieldToSelect(['user_id', 'username'])->setPageSize(self::PAGE_SIZE);
        $lastPage = $collection->getLastPageNumber();

        for ($page = 1; $page <= $lastPage; $page++) {
            $collection->setCurPage($page)->clear();
            foreach ($collection as $user) {
                $userId = (int) $user->getId();
                $codes = array_values(array_filter(
                    [Engine::CODE],
                    fn (string $code): bool => isset(
                        $this->userConfigManager->getProviderConfig($userId, $code)['registration']
                    )
                ));
                if ($codes) {
                    $affectedUsers[$userId] = ['username' => (string) $user->getUserName(), 'codes' => $codes];
                }
            }
        }

        return $affectedUsers;
    }
}
