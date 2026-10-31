<?php

namespace Base\Estate\Command;

use Base\Estate\Service\PaymentWebhook;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Registers the payment provider's webhook on estate_payment_webhook and
 * keeps its secret sealed: run at each deploy, it does nothing when the
 * webhook is already there, and nothing on a machine no provider can reach.
 */
#[AsCommand(name: 'estate:payment:webhook', description: 'Register the payment provider\'s webhook for the rents paid online, its secret kept sealed')]
final class PaymentWebhookCommand extends Command
{
    public function __construct(private readonly PaymentWebhook $webhook)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Register it again (a new secret) even when it is there');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $gateway = $this->webhook->gateway() ?? '-';
        $done = $this->webhook->ensure((bool) $input->getOption('force'));
        $catchUp = 'estate:payment:check, scheduled every 15 minutes, records the rents paid online without it.';

        match ($done) {
            PaymentWebhook::OFF => $io->note('No online payment gateway is configured: no webhook to register.'),
            PaymentWebhook::UNSUPPORTED => $io->note(sprintf('The "%s" gateway cannot register a webhook from the application. %s', $gateway, $catchUp)),
            PaymentWebhook::CONFIGURED => $io->note(sprintf('A webhook_secret is configured for "%s" (an env value): it wins, nothing registered.', $gateway)),
            PaymentWebhook::UNREACHABLE => $io->note(sprintf('No webhook registered: %s %s', $this->webhook->unreachable(), $catchUp)),
            PaymentWebhook::NO_VAULT => $io->warning(sprintf('No webhook registered: the environment has no vault key pair to seal its secret (php bin/console secrets:generate-keys --env=%s, or SYMFONY_DECRYPTION_SECRET). %s', $_SERVER['APP_ENV'] ?? 'prod', $catchUp)),
            PaymentWebhook::KEPT => $io->success(sprintf('The "%s" webhook is registered on %s: nothing to do.', $gateway, $this->webhook->url())),
            PaymentWebhook::REGISTERED => $io->success(sprintf('The "%s" webhook is registered on %s (%s), its secret kept sealed.', $gateway, $this->webhook->url(), $this->webhook->registered()['id'])),
        };

        return Command::SUCCESS;
    }
}
