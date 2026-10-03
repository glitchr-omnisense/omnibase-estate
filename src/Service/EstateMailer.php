<?php

namespace Base\Estate\Service;

use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/** The e-mails to tenants, associates, contractors and the management: @Estate/email/<name>.html.twig. */
class EstateMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        #[Autowire('%estate.from_email%')] private readonly ?string $from = null,
    ) {
    }

    /** @param array<string, mixed> $context */
    public function send(string|array $to, string $subject, string $template, array $context = [], ?string $replyTo = null, array $attachments = []): void
    {
        $email = (new TemplatedEmail())
            ->subject($subject)
            ->htmlTemplate('@Estate/email/'.$template.'.html.twig')
            ->context($context + ['subject' => $subject]);
        foreach ((array) $to as $address) {
            if ($address) {
                $email->addTo($address instanceof Address ? $address : new Address((string) $address));
            }
        }
        if (!$email->getTo()) {
            return;
        }
        if ($this->from) {
            $email->from($this->from);
        }
        if ($replyTo) {
            $email->replyTo($replyTo);
        }
        foreach ($attachments as $name => $content) {
            $email->attach($content, $name, 'application/pdf');
        }
        $this->mailer->send($email);
    }
}
