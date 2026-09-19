<?php

namespace Tests\Feature;

use App\Prompts\AdCopyPrompt;
use App\Services\AdminMonitorService;
use Tests\TestCase;

/**
 * The copy loop has to say what is wrong, and keep what is right.
 *
 * Three faults, found together on one campaign that ended with no ad copy at
 * all — and therefore with creatives composited with no headline, because the
 * headline is drawn from approved copy.
 *
 * 1. The character limits reached the model as json_encode() of the rules
 *    array: keys and numbers under a "PLATFORM RULES" heading with no sentence
 *    telling it to obey them. Counting characters is what a language model is
 *    worst at, and it was left to infer the requirement from
 *    "description_max_length": 90. It produced 98, 102 and 99.
 *
 * 2. overall_status fails on programmatic validation OR a subjective score
 *    above 75, but only the programmatic feedback was handed back. A
 *    score-only rejection therefore told the model "REJECTED, you MUST fix the
 *    following errors" followed by an empty list — ten times — while the notes
 *    that would have fixed it sat unread in the reviewer's own feedback.
 *
 * 3. On the tenth attempt the copy was programmatically valid; the reviewer's
 *    own notes said every line was within its limit. It scored 62, so it was
 *    thrown away and the job failed. Copy a rubric finds unexciting is worth
 *    more than no copy.
 */
class AdCopyComplianceLoopTest extends TestCase
{
    private function googleRules(): array
    {
        return AdminMonitorService::getRulesForPlatform('Google Ads (SEM)');
    }

    public function test_the_limits_are_stated_as_instructions_not_as_config(): void
    {
        $rules = $this->googleRules();

        $this->assertSame(30, $rules['headline_max_length']);
        $this->assertSame(90, $rules['description_max_length']);

        $prompt = (new AdCopyPrompt('Sell perks to founders.', 'Google Ads (SEM)', $rules))->getPrompt();

        // The numbers were always present. What was missing was the imperative
        // and the unit — "counting spaces" — next to them.
        $this->assertStringContainsString('30 characters', $prompt);
        $this->assertStringContainsString('90 characters', $prompt);
        $this->assertStringContainsString('counting spaces and punctuation', $prompt);
        $this->assertStringContainsString('rejected automatically', $prompt);

        // And the raw dump is gone, so the limits cannot be buried in it again.
        $this->assertStringNotContainsString('"description_max_length"', $prompt);
        $this->assertStringNotContainsString('description_max_length:', $prompt);
    }

    public function test_a_rule_added_to_config_still_reaches_the_model(): void
    {
        // The formatter names the rules it knows how to phrase; anything else
        // must still be passed through rather than silently dropped.
        $prompt = (new AdCopyPrompt('Strategy.', 'Google Ads (SEM)', [
            'headline_count' => 5,
            'headline_max_length' => 30,
            'some_future_rule' => 'no puns about banks',
        ]))->getPrompt();

        $this->assertStringContainsString('no puns about banks', $prompt);
    }

    public function test_the_reviewers_own_notes_are_handed_back_on_a_retry(): void
    {
        /*
           A score-only rejection leaves programmatic feedback empty. If that
           empty array is all the retry receives, ten attempts run blind.
        */
        $feedback = [
            'rule_violations' => ['headlines' => [], 'descriptions' => [], 'general' => []],
            'reviewer_notes' => [
                'headlines' => ['No headlines include a direct call to action.'],
                'descriptions' => ['Descriptions are clear but lack urgency.'],
            ],
            'reviewer_score' => '62/100 — 76 or above is required',
        ];

        $prompt = (new AdCopyPrompt('Strategy.', 'Google Ads (SEM)', $this->googleRules(), $feedback))->getPrompt();

        $this->assertStringContainsString('No headlines include a direct call to action.', $prompt);
        $this->assertStringContainsString('62/100', $prompt);
    }
}
