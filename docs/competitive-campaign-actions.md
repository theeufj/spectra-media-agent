# Competitor research and existing campaigns

`CompetitorCampaignContext` supplies the campaign optimiser with dated, customer-scoped
competitive strategy, gap analysis, recently analysed competitors and durable auction
signals. Website keyword themes are research hypotheses, not evidence of paid targeting.
Auction share changes are compared within the same campaign across weekly snapshots.

The model must cite supplied evidence IDs. The server resolves those IDs to saved evidence;
it drops competitor tests without valid provenance. The existing performance confidence
scorer still decides whether an adjustment can run automatically.

Reviews run after a new competitor report or gap analysis and during daily optimisation.
The report's **Review current campaigns** button only proposes actions. Only serving,
deployed campaigns are reviewed. Keyword and ad-variation tests always require approval.

## Execution and limits

`CompetitiveRecommendationService` stores the proposal, evidence and execution history in
`recommendations`, independently of the short-lived optimisation cache. Approving a proposal
queues `ApplyCompetitiveRecommendation`; approval alone never means it was applied.

Current execution support is Google Ads:

- Budget adjustments within the approved daily limit and existing adjustment bounds.
- Keyword CPC changes for a verified keyword in this campaign, within 25% of its current bid.
- Disabling Search Partners and Display expansion on Search campaigns.
- Up to three exact-match keywords in an existing Search ad group.
- One responsive Search ad variation alongside the existing ads, using the existing destination.

New tests use the existing budget and require manual approval. Unsupported changes, other
platforms and shared cross-platform budget changes remain visible as blocked review items.
Manual approval can execute when automatic optimisation is disabled, but does not bypass
campaign ownership, budget, evidence freshness or target validation.

Campaign locks, atomic status claims and proposal fingerprints prevent duplicate execution.
Existing matching keywords/ads are reused. An interrupted mutation is checked against
Google before any further action; it is never blindly replayed. Hourly verification reads
actual platform state before marking the change verified. That confirms configuration,
not policy approval or ad delivery.

## Results

Eight days after application, hourly verification compares seven complete days before the
change with seven complete days afterwards, excluding the change date. Both windows need
seven recorded days and at least 30 clicks to be labelled sufficient. The report explicitly
states when data is insufficient.

This is an observational campaign-level comparison. Ad variations are not randomized A/B
experiments; concurrent changes, seasonality and Google's delivery choices can affect results.
The report does not claim that a change caused an improvement.
