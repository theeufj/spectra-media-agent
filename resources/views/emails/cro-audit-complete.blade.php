<x-mail::message>
# ⚠️ We Found {{ $issuesFound }} Issues on Your Landing Page

Hi {{ $user->name }},

Our AI just finished auditing **{{ $audit->url }}**, and we discovered some conversion killers that could be costing you money.

## Your CRO Score: {{ $audit->overall_score }}/100

@if($audit->overall_score < 50)
**Critical:** Your page is losing more than half of your potential customers.
@elseif($audit->overall_score < 70)
**Warning:** There's significant room for improvement.
@else
**Good Start:** A few tweaks could push you to elite status.
@endif

### Top Issues We Found:

@if($audit->issues && count($audit->issues) > 0)
@foreach(array_slice($audit->issues, 0, 3) as $issue)
- ⚠️ {{ $issue['title'] ?? $issue }}
@endforeach
@endif

Want the full breakdown with **step-by-step fixes**?

<x-mail::button :url="route('subscription.pricing')">
Unlock Full CRO Report
</x-mail::button>

## Why This Matters

If you're spending money on ads right now, these issues are **bleeding your budget**. 

For every 100 clicks you buy:
- **{{ 100 - $audit->overall_score }} visitors** are bouncing due to fixable problems
- Potential lost revenue: **~${{ number_format((100 - $audit->overall_score) * 40, 0) }}/month** (based on industry averages)

Our Pro plan gives you:
✅ Unlimited CRO audits  
✅ Detailed fix instructions  
✅ Before/after projections  
✅ Automated A/B testing recommendations  

Starting at just **$99/month** - less than the cost of 10 clicks.

Not ready to upgrade? No problem—you still have **{{ 3 - $customer->landingPageAudits->count() }} free audits left**.

Questions? Hit reply.

Thanks,<br>
The {{ config('app.name') }} Team

---

<small>P.S. Fixing just ONE of these issues could pay for a year of Spectra. [See the full report →]({{ route('subscription.pricing') }})</small>
</x-mail::message>
