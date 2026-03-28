# Spectra Media Agent — System Architecture

> A complete guide to the autonomous advertising platform: every agent, job, and service, and how they fit together.

## High-Level Overview

Spectra is an AI-powered advertising agency platform. Clients create campaigns, and the system autonomously deploys ads across Google and Facebook, monitors performance, optimises spend, heals broken ads, mines keywords, runs A/B tests, and handles billing — all without human intervention after initial setup.

```mermaid
flowchart TB
    subgraph Client["👤 Client Actions"]
        CC[Create Campaign]
        SO[Sign Off Strategy]
        DP[Deploy Campaign]
    end

    subgraph Admin["🔧 Admin Setup"]
        MCC[Register MCC Account]
        FB[Link Facebook BM]
        SET[Platform Settings]
    end

    subgraph Generation["🎨 Content Generation"]
        GS[GenerateStrategy Job]
        GC[GenerateCampaignCollateral Job]
        GAC[GenerateAdCopy Job]
        GI[GenerateImage Job]
        GV[GenerateVideo Job]
    end

    subgraph Deployment["🚀 Deployment Pipeline"]
        DCJ[DeployCampaign Job]
        ASBS[Ad Spend Billing<br/>7-day prepay via Stripe]
        DS[DeploymentService]
        GAEA[GoogleAds<br/>ExecutionAgent]
        FAEA[FacebookAds<br/>ExecutionAgent]
    end

    subgraph Monitoring["📊 Continuous Monitoring"]
        MCS[MonitorCampaignStatus<br/>Hourly]
        RHC[RunHealthChecks<br/>Every 6 hours]
        FPD[FetchPerformanceData<br/>Hourly]
    end

    subgraph Optimization["🧠 Autonomous Optimization"]
        OC[OptimizeCampaigns<br/>Daily]
        ACM[AutomatedMaintenance<br/>Daily 04:00]
        ABT[EvaluateABTests<br/>Daily 06:00]
        RCI[CompetitorIntelligence<br/>Weekly]
    end

    subgraph Billing["💰 Billing Cycle"]
        PDB[ProcessDailyBilling<br/>Daily 06:00]
        RAU[ReportAdSpendUsage<br/>Daily]
    end

    CC --> GS
    SO --> GC
    GC --> GAC & GI & GV
    DP --> DCJ
    DCJ --> ASBS --> DS
    DS --> GAEA & FAEA
    MCC -.->|MCC credentials| GAEA
    FB -.->|BM account| FAEA

    MCS --> RHC
    FPD --> OC
    OC --> ACM
    ACM --> ABT

    PDB --> RAU
```

---

## The Complete Workflow

### Phase 1: Campaign Creation & Content Generation

```mermaid
sequenceDiagram
    participant U as Client
    participant CC as CampaignController
    participant GS as GenerateStrategy Job
    participant AI as GeminiService (AI)
    participant GCC as GenerateCampaignCollateral
    participant AC as GenerateAdCopy
    participant IM as GenerateImage
    participant VD as GenerateVideo

    U->>CC: Create campaign (goals, budget, market)
    CC->>GS: Dispatch strategy generation
    GS->>AI: Send StrategyPrompt (brand, competitors, platform list)
    AI-->>GS: Returns strategies per platform (Google + Facebook)
    GS->>GS: Create Strategy records (one per platform)

    U->>CC: Sign off strategies
    CC->>GCC: Dispatch collateral generation
    GCC->>AC: Dispatch per-strategy ad copy generation
    GCC->>IM: Dispatch per-strategy image generation
    GCC->>VD: Dispatch per-strategy video generation
    AC->>AI: Generate platform-specific ad copy
    IM->>AI: Generate campaign images (Gemini)
    VD->>AI: Generate campaign videos (Veo)
```

### Phase 2: Deployment & Billing

```mermaid
sequenceDiagram
    participant U as Client
    participant DC as DeployCampaign Job
    participant ASBS as AdSpendBillingService
    participant S as Stripe
    participant DS as DeploymentService
    participant GA as GoogleAdsExecutionAgent
    participant FA as FacebookAdsExecutionAgent
    participant GAPI as Google Ads API
    participant FAPI as Facebook Ads API

    U->>DC: Click "Deploy"
    DC->>ASBS: Initialize credit account
    ASBS->>S: Charge 7 days upfront
    S-->>ASBS: Payment confirmed

    DC->>DC: Split daily budget evenly across strategies

    loop For each Strategy
        DC->>DS: Deploy strategy
        alt Google platform
            DS->>GA: execute(ExecutionContext)
            GA->>GA: validatePrerequisites()
            GA->>GA: Auto-provision sub-account under MCC (if needed)
            GA->>GA: AI generates ExecutionPlan
            GA->>GAPI: Create Search/Display/PMax campaigns
            GAPI-->>GA: Platform campaign IDs
        else Facebook platform
            DS->>FA: execute(ExecutionContext)
            FA->>FA: validatePrerequisites()
            FA->>FA: AI generates ExecutionPlan
            FA->>FAPI: Create campaigns + ad sets + creatives
            FAPI-->>FA: Platform campaign IDs
        end
    end
    DC->>U: Deployment notification (success/failure)
```

### Phase 3: Continuous Monitoring & Optimization

```mermaid
sequenceDiagram
    participant SCH as Scheduler
    participant MCS as MonitorCampaignStatus
    participant FPD as FetchPerformanceData
    participant HC as HealthCheckAgent
    participant OC as CampaignOptimizationAgent
    participant ACM as AutomatedMaintenance
    participant SH as SelfHealingAgent
    participant STM as SearchTermMiningAgent
    participant BI as BudgetIntelligenceAgent
    participant AI as GeminiService

    Note over SCH: Every hour
    SCH->>MCS: Check campaign statuses
    MCS->>MCS: Poll Google Ads API for status changes
    MCS->>MCS: Update local DB, notify users on changes

    SCH->>FPD: Fetch performance data
    FPD->>FPD: Pull clicks, impressions, cost, conversions

    Note over SCH: Every 6 hours
    SCH->>HC: Run health checks
    HC->>HC: Check API connectivity, tokens, delivery, anomalies
    HC->>AI: Generate recommendations for issues

    Note over SCH: Daily
    SCH->>OC: Optimize campaigns
    OC->>AI: Analyze performance → generate recommendations
    OC->>OC: Score confidence (≥85% auto-apply, <60% review queue)

    Note over SCH: Daily 04:00
    SCH->>ACM: Automated maintenance
    ACM->>SH: Self-heal (fix disapproved ads, delivery issues)
    SH->>AI: Regenerate compliant ad copy if needed
    ACM->>STM: Mine search terms
    STM->>STM: Promote winners → exact match keywords
    STM->>STM: Negative wasteful terms
    ACM->>BI: Adjust budgets
    BI->>BI: Apply time-of-day × day-of-week × seasonal multipliers
```

### Phase 4: Billing Cycle

```mermaid
sequenceDiagram
    participant SCH as Scheduler
    participant PDB as ProcessDailyBilling
    participant ASBS as AdSpendBillingService
    participant GAPI as Google Ads API
    participant FAPI as Facebook Ads API
    participant S as Stripe
    participant BI as BudgetIntelligenceAgent

    Note over SCH: Daily 06:00
    SCH->>PDB: Process billing for all customers
    PDB->>ASBS: processDailyBilling(customer)
    ASBS->>GAPI: Get yesterday's Google spend
    ASBS->>FAPI: Get yesterday's Facebook spend
    ASBS->>ASBS: Deduct total spend from credit balance

    alt Credit < 3 days remaining
        ASBS->>S: Auto-replenish (charge 7 more days)
    end

    alt Payment fails
        Note over ASBS: Escalation ladder
        ASBS->>ASBS: 1st fail → 24h grace period + warning email
        ASBS->>ASBS: 2nd fail → reduce budgets to 50%
        ASBS->>BI: applyBudgetMultiplier(0.5)
        ASBS->>ASBS: 3rd+ fail → pause ALL campaigns
    end

    alt Payment recovers
        ASBS->>S: Charge 7 days of average spend
        ASBS->>ASBS: Resume campaigns, restore budgets
    end
```

---

## Agent Inventory

### Deployment Agents

| Agent | Trigger | Purpose |
|-------|---------|---------|
| **GoogleAdsExecutionAgent** | `DeployCampaign` job | AI-powered Google Ads deployment — creates Search, Display, PMax, and Video campaigns. Auto-provisions sub-accounts under MCC. |
| **FacebookAdsExecutionAgent** | `DeployCampaign` job | AI-powered Facebook/Meta deployment — handles Dynamic Creative, Advantage+, carousel/video ads. |

### Optimization Agents

| Agent | Trigger | Purpose |
|-------|---------|---------|
| **CampaignOptimizationAgent** | Daily `OptimizeCampaigns` job | AI cross-platform optimization. Generates scored recommendations — auto-applies at ≥85% confidence, queues for review at <60%. |
| **BudgetIntelligenceAgent** | Daily 04:00 `AutomatedMaintenance` job | Rule-based budget adjustments using time-of-day, day-of-week, and seasonal multipliers from config. Also called by billing on payment failure. |
| **SearchTermMiningAgent** | Daily 04:00 `AutomatedMaintenance` job | Mines Google search term reports. Promotes high-CTR terms to exact match keywords, negates wasteful terms. |
| **SelfHealingAgent** | Daily 04:00 `AutomatedMaintenance` job | Detects and fixes ad issues: disapproved ads (AI-regenerates copy), budget problems, delivery issues. Retry with exponential backoff. |
| **ABTestingAgent** | Daily 06:00 `EvaluateABTests` job | Manages A/B tests on creative assets. Chi-squared significance testing at 95% confidence, auto-applies winners. |
| **CreativeIntelligenceAgent** | Manual | Analyses creative performance at asset level (headlines, descriptions, images). Identifies winners/losers and generates AI variations. |

### Intelligence Agents

| Agent | Trigger | Purpose |
|-------|---------|---------|
| **CompetitorIntelligenceAgent** | Weekly `RunCompetitorIntelligence` job | Orchestrator — coordinates discovery, analysis, auction insights, and counter-strategy generation. |
| **CompetitorDiscoveryAgent** | Called by CompetitorIntelligenceAgent | Uses Google Search API + Gemini to discover competitors based on customer's website and industry. |
| **CompetitorAnalysisAgent** | Called by CompetitorIntelligenceAgent | Scrapes competitor websites, extracts messaging/positioning/pricing via AI. |
| **AudienceIntelligenceAgent** | Manual | Manages Customer Match lists, audience segmentation, and lookalike audience recommendations. |

### Monitoring Agents

| Agent | Trigger | Purpose |
|-------|---------|---------|
| **HealthCheckAgent** | Every 6 hours `RunHealthChecks` job | Comprehensive health monitoring: API connectivity, token validity, campaign delivery, performance anomalies, budget pacing, creative fatigue, billing status. |
| **AccountAuditAgent** | Manual `RunAccountAudit` job | Full account audit scoring 0–100 with findings by severity and AI recommendations. |

---

## Scheduled Jobs Timeline

```mermaid
gantt
    title Daily Automation Schedule
    dateFormat HH:mm
    axisFormat %H:%M

    section Hourly
    MonitorCampaignStatus           :active, 00:00, 1h
    FetchPerformanceData            :active, 00:00, 1h

    section Every 6 Hours
    RunHealthChecks                 :crit, 00:00, 30min
    RunHealthChecks                 :crit, 06:00, 30min
    RunHealthChecks                 :crit, 12:00, 30min
    RunHealthChecks                 :crit, 18:00, 30min

    section Daily
    OptimizeCampaigns               :done, 00:00, 2h
    CheckCampaignPolicyViolations   :done, 00:00, 1h

    section Daily 04:00
    SelfHealingAgent                :04:00, 30min
    SearchTermMiningAgent           :04:30, 30min
    BudgetIntelligenceAgent         :05:00, 30min

    section Daily 06:00
    ProcessDailyAdSpendBilling      :06:00, 1h
    EvaluateABTests                 :06:00, 30min
    ReportAdSpendUsage              :06:00, 15min
```

---

## MCC Account Management

All Google Ads operations run through a platform-owned MCC (Manager) account. Admins manage MCC accounts via **Admin > MCC Accounts**.

```mermaid
flowchart TB
    subgraph Admin["Admin Panel"]
        MA[MCC Accounts Page]
    end

    subgraph DB["Database"]
        MCT[mcc_accounts table<br/>name, google_customer_id,<br/>refresh_token encrypted,<br/>is_active]
        ENV[.env fallback<br/>GOOGLE_ADS_MCC_CUSTOMER_ID<br/>GOOGLE_ADS_MCC_REFRESH_TOKEN]
    end

    subgraph Resolution["Credential Resolution"]
        GAM[MccAccount::getActive]
    end

    subgraph Services["All Google Ads Services"]
        BAS[BaseGoogleAdsService]
        GAEA2[GoogleAdsExecutionAgent]
        HCA[HealthCheckAgent]
        CMD[CreateGoogleAdsSubAccount]
    end

    MA -->|CRUD + Activate| MCT
    GAM -->|1. Check DB| MCT
    GAM -->|2. Fallback| ENV
    BAS --> GAM
    GAEA2 --> GAM
    HCA --> GAM
    CMD --> GAM
```

---

## Budget Flow

```mermaid
flowchart LR
    subgraph Campaign["Campaign"]
        TB[total_budget: $3000]
        DB[daily_budget: $100]
    end

    subgraph Split["DeployCampaign Job"]
        S1["Strategy: Google<br/>daily_budget: $50"]
        S2["Strategy: Facebook<br/>daily_budget: $50"]
    end

    subgraph Agents["Execution Agents"]
        GA[GoogleAdsExecutionAgent<br/>Uses strategy daily_budget]
        FA[FacebookAdsExecutionAgent<br/>Uses strategy daily_budget]
    end

    subgraph PostDeploy["Post-Deploy Optimization"]
        BI[BudgetIntelligenceAgent<br/>Applies time/day/seasonal multipliers]
        COA[CampaignOptimizationAgent<br/>AI recommends budget shifts]
    end

    TB --> DB
    DB -->|Even split| S1 & S2
    S1 --> GA
    S2 --> FA
    GA & FA --> BI
    BI --> COA
```

---

## Ad Spend Billing Lifecycle

```mermaid
stateDiagram-v2
    [*] --> Active: Stripe charges 7 days upfront
    Active --> Active: Daily deduction of actual spend
    Active --> LowBalance: Credit < 3 days remaining
    LowBalance --> Active: Auto-replenish 7 days via Stripe
    LowBalance --> GracePeriod: Replenishment fails 1st time
    GracePeriod --> Active: Payment recovers within 24h
    GracePeriod --> BudgetReduced: Payment fails again 2nd time
    BudgetReduced --> Active: Payment recovers and budgets restored
    BudgetReduced --> Paused: Payment fails again 3rd time
    Paused --> Active: Payment recovers and campaigns resumed
    Paused --> [*]: Account suspended
```

---

## Key Configuration

### `config/budget_rules.php`

Controls the rule-based automation thresholds used by `BudgetIntelligenceAgent`, `SearchTermMiningAgent`, and `SelfHealingAgent`:

| Section | Examples |
|---------|----------|
| Time-of-day multipliers | 0.5× overnight, 1.0× business hours, 1.3× evening prime |
| Day-of-week multipliers | 0.8× Sunday, 1.0× midweek, 1.3× Friday |
| Seasonal multipliers | 2.0× Black Friday, 1.8× Cyber Monday |
| Self-healing rules | Max 3 fix attempts, 24h retry delay, min 0.5% CTR |
| Search term mining | Min 100 impressions, promote at 5% CTR, negative at $20 cost + 0 conversions |
| Budget reallocation | Min ROAS 1.5, max 20% shift per cycle, min 7 days data |

### `config/seasonal_strategies.php`

Presets for seasonal campaign adjustments used by `ApplySeasonalStrategyShift`:

| Season | Budget Multiplier | Bidding Strategy | Copy Theme |
|--------|-------------------|------------------|------------|
| Black Friday | 1.5× | TARGET_CPA | Urgency |
| Summer Sale | 1.2× | MAXIMIZE_CONVERSIONS | Summer |
| Default | 1.0× | MAXIMIZE_CONVERSIONS | Evergreen |
