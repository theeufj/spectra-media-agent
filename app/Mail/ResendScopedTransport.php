<?php

namespace App\Mail;

use App\Services\ResendSendingClient;
use Resend\Laravel\Transport\ResendTransportFactory;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

class ResendScopedTransport implements TransportInterface
{
    public function __construct(
        private readonly TransportInterface $defaultTransport,
        private readonly ResendSendingClient $scopedClient,
        private readonly array $options = [],
    ) {}

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        $envelope ??= Envelope::create($message);
        $client = $this->scopedClient->forSender($envelope->getSender()->getAddress());
        $transport = $client ? new ResendTransportFactory($client, $this->options) : $this->defaultTransport;

        return $transport->send($message, $envelope);
    }

    public function __toString(): string
    {
        return (string) $this->defaultTransport;
    }
}
