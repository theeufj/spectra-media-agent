# Feature Inventory & Review

_Compiled 2026-08-08 from the codebase and **live production data**. Every "evidence" column is a real
measurement, not an estimate._

## How to read this

Each feature carries three numbers:

- **LOC** — lines of PHP + JSX matching that area. Rough, and areas overlap, but it's the maintenance cost.
- **Prod data** — actual row counts from the production database.
- **Verdict** — my read, for you to argue with.

Two facts frame everything below:

> **34 of 88 database tables have never held a single row.**
>
> **7 of the 17 jobs that have ever run have never once taken an action.**

Context for judging "unused": you have **9 customers, 29 users, 10 campaigns**, of which **1 was live**
(now paused). A feature with no data isn't necessarily bad — it may just be ahead of demand. But
ahead-of-demand code is where every fatal bug found on 2026-08-08 was hiding.

---

## Summary: the decision view

| Feature | LOC | Prod evidence | Verdict |
|---|---:|---|---|
| **Google Ads** (deploy/optimise/heal) | ~8,000 | 50 perf rows, only platform with spend | **Core — keep** |
| **Conversion tracking / GTM** | 4,210 / 3,462 | 48 conversion events, GTM installed | **Core — keep** |
| **Keyword analysis** | 3,671 | 50 keywords, 874 quality scores | **Keep** |
| **SEO** (audits, rankings) | 2,962 | **4,112 rankings**, 2 audits | **Keep — most-used secondary feature** |
| **Notifications / email** | 2,959 | 2,130 notifications | **Keep** |
| **Competitor analysis** | 2,794 | 115 competitors | **Keep, review depth** |
| **Health checks** | 2,702 | 116 runs, 89 actions | **Keep** |
| **Reports** (daily/weekly/monthly) | 2,715 | 367 email messages | **Keep** |
| **Creative generation** | 2,559 | 102 images, 17 videos, 9 ad copies | **Keep** |
| **Brand guidelines** | 2,486 | 4 records | **Keep — feeds creative** |
| **Video generation** | 2,390 | 17 videos | **Review — expensive, low volume** |
| **Budget intelligence** | 2,404 | 477 budget actions | **Keep** |
| **Billing / ad-spend credit** | 2,193 | **1 credit account**, 40 txns | **Keep but barely exercised** |
| **Deployment pipeline** | 1,968 | 10 campaigns | **Core — keep** |
| **Sandbox / demo** | 1,816 | — | **Question: is this sales-critical?** |
| **Audience intelligence** | 1,716 | **audiences table = 0** | **Cut or justify** |
| **Knowledge base** | 1,561 | 2,361 entries | **Keep — feeds everything** |
| **A/B testing** | 1,182 | 1 test, `campaign_experiments` = 0, **8 errors, 0 actions** | **Cut** |
| **Attribution** | 1,207 | **both tables = 0** | **Cut** |
| **Proposals** | 1,222 | 1 proposal | **Cut or finish** |
| **War Room** | 1,148 | competitor gap analysis | **Question: distinct from Competitor?** |
| **Email inbox** | 1,126 | 367 messages | **Keep** |
| **Image refinement** | 1,163 | 102 images | **Keep** |
| **Asset harvesting** | 1,003 | **`harvested_assets` = 0** | **Cut** |
| **Support tickets** | 1,096 | 1 ticket | **Cut — use email** |
| **Products / ecommerce** | 715 | **`products`, `product_feeds` = 0** | **Cut** |
| **Personas** | 689 | **`personas` = 0** | **Cut** |
| **CRM integrations** | 443 | **`crm_integrations` = 0** | **Cut** |
| **Invitations** | 156 | **`invitations` = 0** | **Cut or finish** |
| **Microsoft Ads** | 3,148 | **0 rows ever** | **Disabled — kept for roadmap** |
| **LinkedIn Ads** | 1,122 | **0 rows ever** | **Disabled — kept for roadmap** |

---

## The jobs, by whether they do anything

Measured from `agent_runs` — this is what your automation has *actually* done.

| Job | Runs | Actions | Errors | Read |
|---|---:|---:|---:|---|
| MonitorCampaignStatus | 505 | 2,020 | 0 | Working hard, earning its place |
| HourlyBudgetOptimization | 505 | 477 | 0 | Genuinely optimising |
| RunHealthChecks | 116 | 89 | 0 | Working |
| AutomatedCampaignMaintenance | 40 | 23 | 0 | Working |
| ReviewGoogleAdsRecommendations | 20 | 5 | 0 | Low yield, cheap |
| RunPerformanceAnomalyCheck | 231 | 42 | 0 | Low yield |
| OptimizeCampaigns | 38 | 7 | 0 | Low yield |
| RunAudienceIntelligence | 4 | 4 | 0 | Barely run |
| **RunSelfHealingChecks** | **230** | **1** | 0 | 230 runs, one action |
| **RunStrategicDiagnosis** | **154** | **1** | 0 | 154 runs, one action |
| **EvaluateABTests** | 40 | **0** | **8** | Never worked |
| **AutoStartABTests** | 20 | **0** | 0 | Never acted |
| **PauseWastefulAdGroups** | 19 | **0** | 0 | Never acted |
| **WeeklyBudgetRebalance** | 5 | **0** | 0 | Never acted |
| **DetectKeywordCannibalization** | 4 | **0** | 0 | Never acted |
| **DetectNegativeKeywordConflicts** | 5 | **0** | 0 | Never acted |
| **VerifyConversionTracking** | 4 | **0** | **4** | Only ever errored |

**Caveat before cutting on this basis:** several of these are *supposed* to do nothing most of the time.
`PauseWastefulAdGroups` finding nothing to pause is a healthy account, not a broken job. The ones that
should worry you are `EvaluateABTests` (8 errors, 0 successes) and `VerifyConversionTracking` (4 runs,
4 errors) — those aren't quiet, they're broken.

`RunSelfHealingChecks` at 230 runs / 1 action is the interesting one: either your ads are never
disapproved (plausible with one campaign), or it isn't detecting what it should.

---

## Feature detail

### Core — the actual product

**Google Ads deployment** — `GoogleAdsExecutionAgent` + 7 campaign-type executors (Search, Display,
Performance Max, Video, Demand Gen, Shopping, Local Services) + 6 collaborators. The only platform that
has ever spent money (A$1,152 lifetime).

- *Question:* you support 7 Google campaign types. How many have you actually deployed? If it's
  Search + PMax, the other five are ~600 lines of untested surface each.

**Conversion tracking + GTM** — 7,672 LOC combined. Container provisioning, tag creation, pixel setup,
offline conversion upload, server-side conversions via Data Manager. 48 conversion events recorded.

- *Note:* `offline_conversions` = 0. The upload path has never run.

**Deployment pipeline** — compliance pre-check, budget validation, credit capture, multi-platform
dispatch, post-deploy verification. Working.

**Billing / ad-spend credit** — 7-day prepay, daily reconciliation, dunning, pause/resume. **One credit
account exists.** This is the most safety-critical code in the app and the least exercised.

### Working and earning their keep

**SEO** — 4,112 ranking rows makes this your second-most-used feature after Google Ads. Audits, rank
tracking, sitemap crawling.

**Keyword analysis** — 3,671 LOC. Research, clustering, quality scores (874 rows), negative keywords,
cannibalisation detection, search-term mining. Note the *detection* jobs have never acted.

**Knowledge base** — 2,361 entries, pgvector embeddings. Feeds strategy, ad copy, DSA. Quietly load-bearing.

**Competitor analysis** — 115 competitors. Three agents: `CompetitorDiscoveryAgent`,
`CompetitorAnalysisAgent`, `CompetitorIntelligenceAgent`.

- *Question:* three agents for one job. Is that a pipeline or accreted duplication?

**War Room** — competitor gap analysis, stored on `customers.war_room_*`.

- *Question:* how is this different from Competitor Intelligence? From the outside they look like the
  same feature with two UIs.

### Never used — the cut list

Each of these has **zero production rows**:

| Feature | LOC | Notes |
|---|---:|---|
| Attribution | 1,207 | Touchpoints + conversions, HMAC tracking endpoints, multi-touch models |
| Asset harvesting | 1,003 | Scrape brand assets from customer sites |
| Products / ecommerce | 715 | Merchant Center feed sync, Shopping campaigns |
| Personas | 689 | AI-generated audience personas |
| CRM integrations | 443 | HubSpot/Salesforce conversion sync |
| Audiences | 1,716 | `audiences` table empty despite `RunAudienceIntelligence` running |

**~5,773 LOC with no evidence of ever being used.**

### Judgement calls

**A/B testing** (1,182) — 1 test, 0 experiments, 40 runs with 8 errors and no successes. Either fix or
remove; leaving a broken automation running daily is the worst option.

**Sandbox / demo** (1,816) — no data, but this may be a *sales* tool rather than a customer feature.
If it demos the product to prospects, that's a real justification. If not, cut.

**Proposals** (1,222) — 1 proposal generated. PDF generation via Browsershot. Same question: sales tool
or product feature?

**Support tickets** (1,096) — 1 ticket. You have 29 users. Email would do this.

**Video generation** (2,390) — 17 videos, via Vidu + Veo. Expensive per unit and low volume.
Worth confirming the videos actually got deployed to campaigns.

**Invitations** (156) — table empty, and the code had a security note about non-expiring tokens. Either
finish it or remove it.

---

## Cross-cutting observations

**The admin surface is large.** 12 admin controllers plus the 7 I split out of `AdminController`.
Admin tooling is legitimate, but it's built for a scale you don't have yet.

**Three "intelligence" agents overlap**: `AudienceIntelligenceAgent`, `CompetitorIntelligenceAgent`,
`CreativeIntelligenceAgent`, plus `BudgetIntelligenceAgent`. Worth checking whether these are genuinely
distinct or the same pattern applied four times.

**Multi-tenancy / verticals** — there's a tenant skin system (`RealEstateLanding`, `RealEstatePricing`,
`config/verticals.php`). That's a strategic bet worth reviewing on its own.

**Every AI feature currently returns 403** — GCP billing is disabled on `halogen-plasma-487509-e3`.
Any judgement about whether the AI features are valuable should wait until they can actually run.

---

## Suggested review method

Rather than going feature by feature, three questions sort most of this quickly:

1. **Has a customer ever asked for it?** If no, and it has zero rows, cut it.
2. **Would you demo it?** Sales tools (Sandbox, Proposals) get judged on whether they close deals,
   not on row counts.
3. **Does it run unattended?** Anything automated and broken (`EvaluateABTests`,
   `VerifyConversionTracking`) is worse than nothing — it produces noise and false confidence.

The cheapest first move is the six zero-row features (~5,773 LOC). None of them can be missed by anyone,
because none of them has ever produced data.

---

## Open questions for you

1. Which of the 7 Google campaign types have you actually deployed?
2. Is Sandbox a sales tool or a product feature?
3. Is War Room distinct from Competitor Intelligence, or the same thing twice?
4. Are the 17 generated videos live in campaigns, or experiments?
5. Is the vertical/tenant skin system an active strategy or a parked one?
6. What's the real goal for the next quarter — more platforms, more customers on Google, or depth
   on what exists? That answer sorts this list faster than any of the analysis above.
