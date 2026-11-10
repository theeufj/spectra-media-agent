<?php

namespace Tests\Feature;

use App\Services\ResendSendingClient;
use Illuminate\Support\Facades\Mail;
use Resend\Client;
use Resend\Contracts\Transporter;
use Resend\Laravel\Facades\Resend;
use Resend\Laravel\Transport\ResendTransportFactory;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class ResendScopedTransportTest extends TestCase
{
    public function test_only_the_exact_real_property_ads_sender_domain_uses_the_optional_client(): void
    {
        $selector = app(ResendSendingClient::class);
        config(['services.resend.real_property_ads_key' => null]);
        $this->assertNull($selector->forSender('hello@realpropertyads.com'));

        config(['services.resend.real_property_ads_key' => 're_test_scoped']);
        $this->assertInstanceOf(Client::class, $selector->forSender('hello@RealPropertyAds.COM'));
        $this->assertNull($selector->forSender('hello@send.realpropertyads.com'));
        $this->assertNull($selector->forSender('hello@otherrealpropertyads.com'));
        $this->assertNull($selector->forSender('hello@realpropertyads.com.example.test'));
        $this->assertNull($selector->forSender('hello@sitetospend.com'));
    }

    public function test_the_scoped_mailer_routes_by_sender_and_preserves_the_provider_message_id(): void
    {
        config(['services.resend.real_property_ads_key' => 're_test_scoped']);
        $this->mock(ResendSendingClient::class, function ($selector) {
            $selector->shouldReceive('forSender')->once()->with('hello@RealPropertyAds.COM')
                ->andReturn($this->fakeClient('scoped-id'));
            $selector->shouldReceive('forSender')->once()->with('hello@sitetospend.com')->andReturnNull();
        });
        Resend::swap($this->fakeClient('default-id'));

        $transport = Mail::mailer('resend')->getSymfonyTransport();

        foreach (['hello@RealPropertyAds.COM' => 'scoped-id', 'hello@sitetospend.com' => 'default-id'] as $from => $id) {
            $email = (new Email)->from($from)->to('customer@example.test')->text('Test message');
            $message = $transport->send($email);

            $this->assertNotNull($message);
            $original = $message->getOriginalMessage();
            $this->assertInstanceOf(Email::class, $original);
            $this->assertSame($id, $original->getHeaders()->getHeaderBody('X-Resend-Email-ID'));
        }
    }

    public function test_resend_keeps_the_vendor_transport_when_the_scoped_key_is_absent(): void
    {
        config(['services.resend.real_property_ads_key' => null]);
        Resend::swap(new Client(self::createStub(Transporter::class)));

        $this->assertInstanceOf(ResendTransportFactory::class, Mail::mailer('resend')->getSymfonyTransport());
    }

    private function fakeClient(string $messageId): Client
    {
        $transporter = $this->createMock(Transporter::class);
        $transporter->expects($this->once())->method('request')->willReturn(['id' => $messageId]);

        return new Client($transporter);
    }
}
