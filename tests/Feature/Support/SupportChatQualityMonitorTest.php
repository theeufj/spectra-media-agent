<?php

namespace Tests\Feature\Support;

use App\Jobs\MonitorSupportChatQuality;
use App\Models\AgentRun;
use App\Models\Customer;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\Support\SupportChatAudit;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SupportChatQualityMonitorTest extends TestCase
{
    use DatabaseTransactions;

    private Customer $customer;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Customer::unsetEventDispatcher();
        DB::table('agent_runs')->delete();
        DB::table('support_tickets')->delete();

        $this->customer = Customer::factory()->create();
        $this->user = User::factory()->create();
    }

    /**
     * @param  list<mixed>  $transcript  deliberately loose: one test passes a malformed turn
     */
    private function chatTicket(array $transcript, bool $withCustomer = true): SupportTicket
    {
        return SupportTicket::create([
            'user_id' => $this->user->id,
            'customer_id' => $withCustomer ? $this->customer->id : null,
            'subject' => 'Chat', 'description' => 'Chat',
            'priority' => 'normal', 'category' => 'general',
            'source' => 'chatbot', 'status' => 'open',
            'transcript' => $transcript,
        ]);
    }

    private function review(): AgentRun
    {
        (new MonitorSupportChatQuality)->handle(new SupportChatAudit);

        return AgentRun::where('job', 'MonitorSupportChatQuality')->latest('id')->firstOrFail();
    }

    // ── Signal 1: did it consult the account? ────────────────────────────────

    public function test_an_account_question_answered_with_no_tool_call_is_an_error(): void
    {
        // Provable, not heuristic: the tool calls were recorded as they
        // happened, so "answered from the prompt alone" is a fact.
        $this->chatTicket([
            ['role' => 'customer', 'text' => 'What could I do better with my ads?'],
            ['role' => 'assistant', 'text' => 'Try broader keywords and better creative.', 'tools_used' => [], 'tool_numbers' => []],
        ]);

        $run = $this->review();

        $this->assertSame(1, $run->errors);
        $this->assertSame(1, $run->details['answered_without_consulting_account']);
        $this->assertEquals(0, $run->details['tool_use_rate_pct']);
    }

    public function test_a_tool_backed_answer_is_clean(): void
    {
        $this->chatTicket([
            ['role' => 'customer', 'text' => 'How are my ads performing?'],
            ['role' => 'assistant', 'text' => 'You spent 1234.56 across 2 campaigns.',
                'tools_used' => ['get_performance_summary'], 'tool_numbers' => [1234.56, 2.0]],
        ]);

        $run = $this->review();

        $this->assertSame(0, $run->errors);
        $this->assertSame(0, $run->warnings);
        $this->assertEquals(100, $run->details['tool_use_rate_pct']);
    }

    public function test_a_general_question_without_tools_is_not_penalised(): void
    {
        // "How do I reset my password" needs no account lookup. Flagging it
        // would bury the real signal.
        $this->chatTicket([
            ['role' => 'customer', 'text' => 'How do I reset my password?'],
            ['role' => 'assistant', 'text' => 'Use the reset link on the login page.', 'tools_used' => [], 'tool_numbers' => []],
        ]);

        $this->assertSame(0, $this->review()->errors);
    }

    public function test_a_user_with_no_account_is_not_penalised_for_missing_tools(): void
    {
        // No customer means no tools by design, so counting it against the
        // model would be measuring our own routing decision.
        $this->chatTicket([
            ['role' => 'customer', 'text' => 'How are my campaigns doing?'],
            ['role' => 'assistant', 'text' => 'I cannot see an account yet.', 'tools_used' => [], 'tool_numbers' => []],
        ], withCustomer: false);

        $this->assertSame(0, $this->review()->errors);
    }

    // ── Signal 2: are the figures traceable? ─────────────────────────────────

    public function test_a_figure_from_nowhere_is_a_warning_not_an_error(): void
    {
        $this->chatTicket([
            ['role' => 'customer', 'text' => 'How much did I spend?'],
            ['role' => 'assistant', 'text' => 'You spent about 8500.00 last month.',
                'tools_used' => ['get_performance_summary'], 'tool_numbers' => [1234.56]],
        ]);

        $run = $this->review();

        // A warning, deliberately: the reply is prose and the model may have
        // summed or converted something. It means "read this one", not "this is
        // wrong" — a monitor that cries wolf gets ignored.
        $this->assertSame(1, $run->warnings);
        $this->assertSame(0, $run->errors);
        $this->assertSame(1, $run->details['replies_with_unsourced_figures']);
    }

    public function test_sensible_rounding_of_a_real_figure_is_not_flagged(): void
    {
        $this->chatTicket([
            ['role' => 'customer', 'text' => 'How much did I spend?'],
            ['role' => 'assistant', 'text' => 'Roughly 1200 in the last 30 days.',
                'tools_used' => ['get_performance_summary'], 'tool_numbers' => [1234.56]],
        ]);

        $this->assertSame(0, $this->review()->warnings);
    }

    public function test_quoting_the_customers_own_number_back_is_not_fabrication(): void
    {
        $this->chatTicket([
            ['role' => 'customer', 'text' => 'I set a budget of 5000, is that right?'],
            ['role' => 'assistant', 'text' => 'A 5000 budget is reasonable for that goal.',
                'tools_used' => ['get_account_overview'], 'tool_numbers' => [3.0]],
        ]);

        $this->assertSame(0, $this->review()->warnings);
    }

    public function test_small_numbers_in_prose_are_ignored(): void
    {
        // "3 things", "last 7 days" — counting these would drown the signal.
        $this->chatTicket([
            ['role' => 'customer', 'text' => 'Any tips?'],
            ['role' => 'assistant', 'text' => 'Here are 3 things to try over the next 7 days.',
                'tools_used' => ['get_account_overview'], 'tool_numbers' => []],
        ]);

        $this->assertSame(0, $this->review()->warnings);
    }

    // ── The run trace ────────────────────────────────────────────────────────

    public function test_an_empty_window_records_a_no_op_rather_than_silence(): void
    {
        $run = $this->review();

        // no_op is the visible "ran but did nothing" state. If the widget stops
        // recording, this is what shows it — silence would look identical to
        // healthy.
        $this->assertSame(AgentRun::STATUS_NO_OP, $run->status);
        $this->assertSame(0, $run->details['replies_reviewed']);
    }

    public function test_flagged_examples_are_recorded_for_follow_up(): void
    {
        $ticket = $this->chatTicket([
            ['role' => 'customer', 'text' => 'How are my ads doing?'],
            ['role' => 'assistant', 'text' => 'Fine.', 'tools_used' => [], 'tool_numbers' => []],
        ]);

        $flagged = $this->review()->details['flagged'];

        $this->assertNotEmpty($flagged);
        $this->assertSame($ticket->id, $flagged[0]['ticket_id']);
        $this->assertSame('answered_without_consulting_account', $flagged[0]['issue']);
    }

    public function test_a_malformed_transcript_does_not_abort_the_review(): void
    {
        $this->chatTicket(['not-a-turn']);
        $this->chatTicket([
            ['role' => 'customer', 'text' => 'How are my ads?'],
            ['role' => 'assistant', 'text' => 'Fine.', 'tools_used' => [], 'tool_numbers' => []],
        ]);

        // One bad record must not cost the whole night's review.
        $this->assertSame(1, $this->review()->details['answered_without_consulting_account']);
    }

    public function test_old_conversations_are_outside_the_window(): void
    {
        $ticket = $this->chatTicket([
            ['role' => 'customer', 'text' => 'How are my ads?'],
            ['role' => 'assistant', 'text' => 'Fine.', 'tools_used' => [], 'tool_numbers' => []],
        ]);
        $ticket->forceFill(['created_at' => now()->subDays(30)])->save();

        $this->assertSame(0, $this->review()->details['replies_reviewed']);
    }
}
