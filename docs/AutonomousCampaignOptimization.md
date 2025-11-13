# Autonomous Campaign Optimization Loop

This document outlines the architecture for a closed-loop system where the AI can autonomously analyze campaign performance and adjust its own strategies and collateral to improve results.

This system is composed of three core components: the **Strategy Agent**, the **Analysis Agent**, and the **Deployment Services**.

## How It Works

The process is a continuous cycle of strategy, deployment, analysis, and refinement.

### Step 1: Initial Strategy Generation (The `Strategy Agent`)

1. **Enhanced Prompt:** The `StrategyPrompt` is updated to be "aware" of the specific, programmatic tools it has at its disposal. Instead of just giving a high-level strategy, it will now be instructed to choose a concrete bidding strategy from a list of available options (e.g., `MaximizeConversions`, `TargetCpa`).
2. **Structured Output:** The AI's response will now include a new key, `bidding_strategy`, with the name of the chosen class and its required parameters.

    **Example JSON from the Strategy Agent:**
    ```json
    {
      "platform": "Google Ads (SEM)",
      "ad_copy_strategy": "Focus on high-intent keywords...",
      "imagery_strategy": "Use clean, professional images of the product...",
      "bidding_strategy": {
        "name": "TargetCpa",
        "parameters": {
          "targetCpaMicros": 50000000
        }
      }
    }
    ```

### Step 2: Deployment (The `Deployment Services`)

1. **Strategy Execution:** A new `DeployCampaign` job will be created. This job will read the strategy JSON from the AI.
2. **Dynamic Instantiation:** It will dynamically instantiate the correct bidding strategy class (e.g., `new TargetCpa(50000000)`) based on the `bidding_strategy` object.
3. **Service Call:** It will then pass this object to the `GoogleAdsService`, which will create the campaign with the exact settings the AI specified.

### Step 3: Performance Analysis (The `Analysis Agent`)

1. **Data Fetching:** A new, recurring job (e.g., `AnalyzeCampaignPerformance`) will run periodically (e.g., daily or weekly).
2. **API Calls:** This job will use the `GoogleAdsService` to fetch performance data for the active campaigns (e.g., impressions, clicks, conversions, cost-per-acquisition).
3. **Performance Review Prompt:** It will then pass this raw data to a new prompt, `PerformanceReviewPrompt`, which will ask a text model (like Gemini Flash) to analyze the data and provide a summary of what's working and what's not.

    **Example Analysis from the AI:**
    `"The Target CPA is too high, leading to low impression share. The click-through-rate on Headline Group B is underperforming. Recommend lowering the Target CPA and refreshing the ad copy."`

### Step 4: Strategy Refinement (The `Strategy Agent` again)

1.  **Feedback Loop:** The analysis from the `Analysis Agent` is then fed back into the `StrategyPrompt` as a new input.
2.  **New Prompt:** The `StrategyPrompt` will now look something like this:

    `"Based on the original brief, the knowledge base, AND the following performance analysis, generate an updated strategy..."`
3.  **Optimized Strategy:** The `Strategy Agent` will then generate a new, optimized strategy. It might choose to:
    *   **Change the Bidding:** Switch from `TargetCpa` to `MaximizeConversions`.
    *   **Update the Collateral:** Generate a new `ad_copy_strategy` to replace the underperforming ads.
4.  **The Loop Repeats:** The new strategy is then deployed, and the cycle of analysis and refinement continues.

This creates a powerful, autonomous system where the AI is not just creating campaigns, but actively managing and optimizing them based on real-world performance data.
