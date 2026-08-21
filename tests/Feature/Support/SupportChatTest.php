<?php

namespace Tests\Feature\Support;

use App\Mail\SupportTicketCreated;
use App\Models\Customer;
use App\Models\Role;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\GeminiService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SupportChatTest extends TestCase
{
    use DatabaseTransactions;

    private User $user;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Mail::fake();
        Cache::flush();
        Customer::unsetEventDispatcher();   // creating one provisions a real Google Ads account

        $this->customer = Customer::factory()->create();
        $this->user = User::factory()->create();
        $this->user->customers()->attach($this->customer->id, ['role' => 'owner']);
    }

    /**
     * Stand in for Gemini with a stub rather than a mock: the real constructor
     * wants API credentials, and a typed subclass keeps static analysis honest
     * where Mockery's shouldReceive() union does not.
     */
    private function fakeAi(string $reply = 'Here is a helpful answer.'): void
    {
        $this->app->instance(GeminiService::class, new class($reply) extends GeminiService
        {
            public function __construct(private readonly string $reply) {}

            public function generateContent(
                string $model,
                string $prompt,
                array $config = [],
                ?string $systemInstruction = null,
                bool $enableThinking = false,
                bool $enableGoogleSearch = false,
                ?int $maxRetries = null,
                ?string $imageBase64 = null,
                string $imageMimeType = 'image/jpeg',
                array $context = []
            ): array {   // narrower than the parent's ?array: this stub always answers
                return ['text' => $this->reply];
            }

            // With a customer in session the controller takes the tool-calling
            // path, so a stub that only covers generateContent would silently
            // fall through to the real HTTP client and look like an AI failure.
            public function generateWithFunctionCalling(
                string $model,
                string $systemInstruction,
                string $prompt,
                array $tools,
                callable $toolHandler,
                array $config = [],
                array $context = [],
                int $maxToolCalls = 15
            ): array {
                return ['text' => $this->reply];
            }
        });
    }

    private function brokenAi(): void
    {
        $this->app->instance(GeminiService::class, new class extends GeminiService
        {
            public function __construct() {}

            public function generateContent(
                string $model,
                string $prompt,
                array $config = [],
                ?string $systemInstruction = null,
                bool $enableThinking = false,
                bool $enableGoogleSearch = false,
                ?int $maxRetries = null,
                ?string $imageBase64 = null,
                string $imageMimeType = 'image/jpeg',
                array $context = []
            ): ?array {
                throw new \RuntimeException('Gemini is down');
            }

            public function generateWithFunctionCalling(
                string $model,
                string $systemInstruction,
                string $prompt,
                array $tools,
                callable $toolHandler,
                array $config = [],
                array $context = [],
                int $maxToolCalls = 15
            ): ?array {
                throw new \RuntimeException('Gemini is down');
            }
        });
    }

    private function send(array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user)
            ->withSession(['active_customer_id' => $this->customer->id])
            ->postJson(route('support.chat.send'), $payload);
    }

    private function makeAdmins(int $count = 3): void
    {
        $role = Role::unguarded(fn () => Role::firstOrCreate(['name' => 'admin']));

        foreach (range(1, $count) as $i) {
            User::factory()->create()->roles()->attach($role);
        }
    }

    // ── The core promise ─────────────────────────────────────────────────────

    public function test_a_message_creates_a_ticket_and_returns_an_ai_reply(): void
    {
        $this->fakeAi('You can change your budget under Campaign settings.');

        $response = $this->send(['message' => 'How do I change my campaign budget?']);

        $response->assertOk()
            ->assertJsonPath('reply', 'You can change your budget under Campaign settings.')
            ->assertJsonPath('closed', false);

        $ticket = SupportTicket::latest('id')->first();
        $this->assertSame('chatbot', $ticket->source);
        $this->assertSame($this->user->id, $ticket->user_id);
        $this->assertSame($this->customer->id, $ticket->customer_id);
        $this->assertSame('How do I change my campaign budget?', $ticket->description);
    }

    public function test_the_ticket_is_still_raised_when_the_ai_fails(): void
    {
        // The one failure this feature must not have: an AI outage silently
        // swallowing support requests. The ticket is written before the model
        // is called precisely so this holds.
        $this->brokenAi();

        $response = $this->send(['message' => 'My card was declined, what now?']);

        $response->assertOk();
        $this->assertNotEmpty($response->json('reply'), 'the customer was left with no reply at all');

        $ticket = SupportTicket::latest('id')->first();
        $this->assertNotNull($ticket, 'the support request was lost when the AI failed');
        $this->assertSame('My card was declined, what now?', $ticket->description);
    }

    public function test_the_full_transcript_is_recorded(): void
    {
        $this->fakeAi('Budgets live under Campaign settings.');

        $this->send(['message' => 'Where are budgets?'])->assertOk();

        $ticket = SupportTicket::latest('id')->first();
        $transcript = $ticket->transcript;

        // Both sides, in order — the assistant answers customers unsupervised,
        // so its replies have to be reviewable after the fact.
        $this->assertCount(2, $transcript);
        $this->assertSame('customer', $transcript[0]['role']);
        $this->assertSame('Where are budgets?', $transcript[0]['text']);
        $this->assertSame('assistant', $transcript[1]['role']);
        $this->assertSame('Budgets live under Campaign settings.', $transcript[1]['text']);
        $this->assertArrayHasKey('at', $transcript[0]);
    }

    public function test_the_assistant_is_offered_account_tools_and_they_resolve(): void
    {
        \App\Models\Campaign::factory()->count(3)->create(['customer_id' => $this->customer->id]);

        $seen = [];

        // Capture what the controller actually hands the model, and prove the
        // handler it wires up really reaches this customer's data — the tools
        // being correct in isolation is worth nothing if they are not connected.
        $this->app->instance(GeminiService::class, new class($seen) extends GeminiService
        {
            public function __construct(public array &$seen) {}

            public function generateWithFunctionCalling(
                string $model,
                string $systemInstruction,
                string $prompt,
                array $tools,
                callable $toolHandler,
                array $config = [],
                array $context = [],
                int $maxToolCalls = 15
            ): array {
                $this->seen['tools'] = array_column($tools, 'name');
                $this->seen['overview'] = $toolHandler('get_account_overview', []);

                return ['text' => 'Based on your account, here is what I would look at.'];
            }
        });

        $this->send(['message' => 'What could I do better with my ads?'])->assertOk();

        $this->assertContains('get_performance_summary', $seen['tools']);
        $this->assertContains('get_account_overview', $seen['tools']);
        $this->assertSame($this->customer->name, $seen['overview']['account_name']);
        $this->assertSame(3, $seen['overview']['campaigns_total']);
    }

    // ── Admin notification ───────────────────────────────────────────────────

    public function test_every_admin_is_emailed_once_when_a_conversation_opens(): void
    {
        $this->makeAdmins(3);
        $this->fakeAi();

        $this->send(['message' => 'Hello, I need help'])->assertOk();

        Mail::assertQueued(SupportTicketCreated::class, 3);
    }

    public function test_follow_up_messages_do_not_re_email_the_admins(): void
    {
        $this->makeAdmins(3);
        $this->fakeAi();

        $first = $this->send(['message' => 'First question'])->assertOk();
        $ticketId = $first->json('ticket_id');

        $this->send(['message' => 'A follow up', 'ticket_id' => $ticketId])->assertOk();

        // Three, not six: re-alerting the whole team per line typed would make
        // the alert worthless and is an abuse vector in itself.
        Mail::assertQueued(SupportTicketCreated::class, 3);
    }

    public function test_a_follow_up_appends_to_the_same_ticket(): void
    {
        $this->fakeAi();

        $ticketId = $this->send(['message' => 'First question'])->json('ticket_id');
        $this->send(['message' => 'Second question', 'ticket_id' => $ticketId])->assertOk();

        $this->assertSame(1, SupportTicket::where('source', 'chatbot')->count());
        $this->assertCount(4, SupportTicket::find($ticketId)->transcript);
    }

    // ── Abuse harness ────────────────────────────────────────────────────────

    public function test_a_ticket_belonging_to_someone_else_cannot_be_appended_to(): void
    {
        $this->fakeAi();
        $theirs = SupportTicket::create([
            'user_id' => User::factory()->create()->id,
            'subject' => 'Theirs', 'description' => 'Theirs',
            'priority' => 'normal', 'category' => 'general',
            'source' => 'chatbot', 'status' => 'open',
        ]);

        $this->send(['message' => 'Let me into your ticket', 'ticket_id' => $theirs->id])
            ->assertStatus(422);

        $this->assertEmpty($theirs->fresh()->transcript ?? []);
    }

    public function test_the_daily_ai_budget_stops_model_calls_but_not_tickets(): void
    {
        config(['support_chat.max_ai_replies_per_day' => 2]);
        $this->fakeAi('generated answer');

        $this->send(['message' => 'One'])->assertOk();
        $this->send(['message' => 'Two'])->assertOk();
        $third = $this->send(['message' => 'Three'])->assertOk();

        // Cost is capped; support coverage is not. The third answer is the
        // holding message, but the question is still on record.
        $this->assertNotSame('generated answer', $third->json('reply'));
        $this->assertSame(3, SupportTicket::where('source', 'chatbot')->count());
    }

    public function test_too_many_open_conversations_is_refused_without_losing_the_ticket_queue(): void
    {
        config(['support_chat.max_open_tickets_per_user' => 2]);
        $this->fakeAi();

        $this->send(['message' => 'One'])->assertOk();
        $this->send(['message' => 'Two'])->assertOk();

        $third = $this->send(['message' => 'Three'])->assertOk();

        $this->assertTrue($third->json('closed'));
        $this->assertSame('too_many_tickets', $third->json('reason'));
        $this->assertSame(2, SupportTicket::where('source', 'chatbot')->count());
    }

    public function test_a_long_conversation_is_closed_off(): void
    {
        config(['support_chat.max_messages_per_conversation' => 1]);
        $this->fakeAi();

        $ticketId = $this->send(['message' => 'First'])->json('ticket_id');

        $next = $this->send(['message' => 'Second', 'ticket_id' => $ticketId])->assertOk();

        $this->assertTrue($next->json('closed'));
        $this->assertSame('conversation_full', $next->json('reason'));
    }

    public function test_the_kill_switch_refuses_the_endpoint(): void
    {
        config(['support_chat.enabled' => false]);
        $this->fakeAi();

        $response = $this->send(['message' => 'Anyone there?'])->assertOk();

        $this->assertTrue($response->json('closed'));
        $this->assertSame(0, SupportTicket::count());
    }

    public function test_a_banned_user_cannot_open_tickets(): void
    {
        $this->fakeAi();

        // banned_at is not fillable, so it has to be forced — update() would
        // silently do nothing and the test would pass for the wrong reason.
        $this->user->forceFill(['banned_at' => now()])->save();

        // CheckForBannedUser sits in the global web stack, so the request is
        // logged out and redirected before it reaches the controller. The
        // guard's own banned check is defence in depth for the day this
        // endpoint moves to a different middleware group — see
        // test_the_guard_refuses_a_banned_user.
        $this->send(['message' => 'Let me back in'])->assertRedirect('/login');

        $this->assertSame(0, SupportTicket::count());
    }

    public function test_the_guard_refuses_a_banned_user(): void
    {
        $this->user->forceFill(['banned_at' => now()])->save();

        $verdict = (new \App\Services\Support\SupportChatGuard)->check($this->user, null);

        $this->assertFalse($verdict['allowed']);
        $this->assertSame(\App\Services\Support\SupportChatGuard::BANNED, $verdict['reason']);
    }

    public function test_message_length_is_capped(): void
    {
        config(['support_chat.max_message_length' => 50]);
        $this->fakeAi();

        $this->send(['message' => str_repeat('a', 51)])->assertStatus(422);
        $this->assertSame(0, SupportTicket::count());
    }

    public function test_a_guest_cannot_reach_the_endpoint(): void
    {
        $this->postJson(route('support.chat.send'), ['message' => 'hello'])
            ->assertStatus(401);
    }

    // ── Triage ───────────────────────────────────────────────────────────────

    public function test_money_and_access_problems_are_raised_as_high_priority(): void
    {
        $this->fakeAi();

        $this->send(['message' => 'I think I was double charged this month, can I get a refund?']);

        $ticket = SupportTicket::latest('id')->first();

        // Triage is keyword-based on purpose: how fast a human looks at a
        // billing complaint must not depend on an external service being up.
        $this->assertSame('high', $ticket->priority);
        $this->assertSame('billing', $ticket->category);
    }

    public function test_the_subject_is_the_first_sentence_not_the_whole_message(): void
    {
        $this->fakeAi();

        $this->send(['message' => 'How do I add a keyword? I have been trying all morning and cannot find it.']);

        $this->assertSame('How do I add a keyword?', SupportTicket::latest('id')->first()->subject);
    }
}
