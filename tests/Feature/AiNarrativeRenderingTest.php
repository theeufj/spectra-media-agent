<?php

namespace Tests\Feature;

use App\Support\AiNarrative;
use Tests\TestCase;

/**
 * AI prose reaches the customer as formatted text, not as its own source.
 *
 * The executive report templates rendered the model's narrative through
 * nl2br(e(...)), which escapes HTML and converts newlines but leaves Markdown
 * syntax alone. Customers received "**Executive Summary: Weekly Performance**"
 * with the asterisks showing, in the one email intended to look considered.
 *
 * Models emit Markdown whether or not the prompt asks for it, so the fix is to
 * render it rather than to keep asking them not to.
 */
class AiNarrativeRenderingTest extends TestCase
{
    public function test_bold_becomes_bold_not_asterisks(): void
    {
        $html = AiNarrative::toEmailHtml('**Executive Summary**');

        $this->assertStringContainsString('<strong', $html);
        $this->assertStringNotContainsString('**', $html);
    }

    public function test_headings_and_lists_survive(): void
    {
        $html = AiNarrative::toEmailHtml("## Next Steps\n\n- Raise budgets\n- Add negatives");

        $this->assertStringContainsString('<h2', $html);
        $this->assertStringContainsString('<ul', $html);
        $this->assertStringContainsString('Raise budgets', $html);
    }

    public function test_every_rendered_tag_carries_inline_styles(): void
    {
        // Outlook ignores most stylesheet rules, so a class-based approach would
        // render as unstyled text for a large share of recipients.
        $html = AiNarrative::toEmailHtml("**Bold**\n\n- Item");

        $this->assertStringNotContainsString('<p>', $html, 'bare tags mean no styling in Outlook');
        $this->assertStringNotContainsString('<li>', $html);
        $this->assertStringContainsString('<p style=', $html);
        $this->assertStringContainsString('<li style=', $html);
    }

    public function test_model_output_cannot_inject_markup(): void
    {
        // This is model output rendered unescaped into an email sent on a
        // customer's behalf, so the tag allowlist is the only thing between a
        // prompt injection and a script tag in their inbox.
        $html = AiNarrative::toEmailHtml('Summary <script>alert(1)</script> and <img src=x onerror=y>');

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('onerror', $html);
    }

    public function test_empty_input_renders_nothing(): void
    {
        $this->assertSame('', AiNarrative::toEmailHtml(null));
        $this->assertSame('', AiNarrative::toEmailHtml('   '));
    }

    public function test_a_summary_that_stops_mid_sentence_is_detectable(): void
    {
        // The reported symptom: a narrative that ends on "focusing" reads as a
        // considered report that simply stops.
        $this->assertTrue(AiNarrative::looksTruncated('This week marked a critical phase, focusing'));
        $this->assertFalse(AiNarrative::looksTruncated('This week marked a critical phase.'));
        $this->assertFalse(AiNarrative::looksTruncated('Did spend rise?'));
    }
}
