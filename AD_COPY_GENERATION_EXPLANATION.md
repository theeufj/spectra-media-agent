    # Ad Copy Generation Functionality Explanation

This document details the end-to-end process of how dynamic ad copy is generated, reviewed, and stored within the application.

## 1. Overview

The ad copy generation functionality allows users to create platform-specific ad headlines and descriptions based on a campaign's strategy. The process involves leveraging Google's Gemini AI for content generation, an internal monitoring service for programmatic and qualitative review, and automated regeneration until the ad copy meets predefined standards.

## 2. Components Involved

Here are the key components that work together to deliver this functionality:

-   **`resources/js/Pages/Campaigns/Collateral.jsx` (Frontend Component)**:
    -   Provides the user interface for viewing collateral and initiating ad copy generation.
    -   Features dynamic tab navigation based on the platforms defined in a campaign's strategies.
    -   Contains the "Generate Ad Copy" button that triggers the backend process.
    -   Displays the generated and approved ad copy (headlines and descriptions).

-   **`app/Http/Controllers/AdCopyController.php` (Backend Controller)**:
    -   Handles the HTTP POST request from the frontend to generate ad copy.
    -   Orchestrates the entire generation and review workflow, including the regeneration loop.
    -   Interacts with `GeminiService` and `AdminMonitorService`.
    -   Stores the final approved ad copy in the database.

-   **`app/Prompts/AdCopyPrompt.php` (Gemini Prompt Class)**:
    -   Constructs a highly specific prompt for the Gemini Generative Content API.
    -   Instructs Gemini to generate a set number of headlines (max 30 chars) and descriptions (max 90 chars) for a given platform.
    -   Explicitly requests the output in a JSON object format.

-   **`app/Prompts/AdCopyReviewPrompt.php` (Gemini Review Prompt Class)**:
    -   Constructs a prompt for the Gemini Generative Content API to review generated ad copy.
    -   Asks Gemini to provide an overall score (0-100) and specific feedback for headlines and descriptions based on platform requirements.
    -   Explicitly requests the output in a JSON object format.

-   **`app/Services/GeminiService.php` (Gemini API Wrapper Service)**:
    -   Encapsulates all direct interactions with the Google Gemini API.
    -   Provides generic methods like `generateContent(model, prompt)` for generative tasks and `embedContent(model, text)` for embedding tasks.
    -   Handles API key management, base URL, and basic error logging for Gemini API calls.

-   **`app/Services/AdminMonitorService.php` (Ad Copy Validation and Monitoring Service)**:
    -   Performs both programmatic and AI-driven qualitative review of generated ad copy.
    -   **Programmatic Validation**: Checks hard requirements like character limits, headline/description counts, and punctuation rules (e.g., no excessive exclamation marks) based on platform-specific rules defined within the service.
    -   **Qualitative Review**: Uses `GeminiService` and `AdCopyReviewPrompt` to get AI-generated feedback and an overall score.
    -   Returns a comprehensive review result, including an `overall_status` (approved/needs_revision).

-   **`app/Models/AdCopy.php` (Eloquent Model)**:
    -   Represents the `ad_copies` table in the database.
    -   Stores `strategy_id`, `platform`, `headlines` (JSON array), and `descriptions` (JSON array).
    -   Casts `headlines` and `descriptions` to arrays for easy access.

-   **`routes/web.php` (Web Routes)**:
    -   Defines the POST route `/campaigns/{campaign}/strategies/{strategy}/ad-copy` which maps to `AdCopyController@store`.

## 3. Workflow: Generating and Approving Ad Copy

1.  **User Initiates Generation**: On the `Collateral.jsx` page, the user navigates to a platform-specific tab (e.g., "Google Ads") and clicks the "Generate Ad Copy" button.

2.  **Frontend Request**: The frontend sends an Inertia POST request to the `campaigns.ad-copy.store` route, passing the `campaign.id`, `strategy.id`, and the `platform`.

3.  **Controller Orchestration (`AdCopyController@store`)**:
    -   The controller performs initial authorization and validation checks (user ownership, strategy belonging to campaign, strategy signed off, platform provided).
    -   It initializes `GeminiService` and `AdminMonitorService`.
    -   It enters a **regeneration loop** (currently set to a maximum of 3 attempts) to ensure approved ad copy is generated.

4.  **Ad Copy Generation (inside loop)**:
    -   An `AdCopyPrompt` is created using the strategy's `ad_copy_strategy` content and the target `platform`.
    -   `GeminiService::generateContent('gemini-2.5-pro', $adCopyPrompt)` is called to generate headlines and descriptions.
    -   The generated text is parsed as JSON. If generation or parsing fails, the loop continues to the next attempt.

5.  **Ad Copy Review (`AdminMonitorService`)**:
    -   A temporary `AdCopy` model instance (not yet saved to DB) is created with the generated ad copy.
    -   `AdminMonitorService::reviewAdCopy($tempAdCopy)` is called to evaluate the ad copy.
    -   The `AdminMonitorService` first performs **programmatic validation** (character limits, counts, punctuation rules) based on the `platform`.
    -   It then calls `GeminiService::generateContent('gemini-2.5-pro', $adCopyReviewPrompt)` to get **AI-driven qualitative feedback** and an `overall_score`.
    -   The service returns a combined review result, including an `overall_status` (either `approved` or `needs_revision`).

6.  **Approval Check (inside loop)**:
    -   If the `overall_status` from the `AdminMonitorService` is `approved`, the loop breaks, and the approved ad copy data is stored.
    -   If not approved, the feedback is logged, and the loop continues for another generation attempt.

7.  **Storing Approved Ad Copy**: Once an approved version is generated (or max attempts are reached):
    -   If approved, `AdCopy::updateOrCreate` saves the `headlines` and `descriptions` to the `ad_copies` table.
    -   If no approved copy is generated after all attempts, an error message is returned to the user.

8.  **Frontend Display**: Upon successful generation and storage, Inertia automatically re-renders the `Collateral.jsx` page. The `CollateralController@show` method (which is called on page load) fetches the newly saved `AdCopy` record and passes it to the component, which then displays the approved headlines and descriptions.

## 4. Key Features

-   **Platform-Specific Generation**: Ad copy is tailored to specific platforms based on the strategy and platform rules.
-   **Automated Regeneration**: The system automatically retries ad copy generation until an approved version is produced, reducing manual effort.
-   **Comprehensive Validation**: Ad copy undergoes both strict programmatic checks (e.g., character limits, punctuation) and AI-driven qualitative review.
-   **Centralized API Handling**: All Gemini API interactions are managed through the `GeminiService` for consistency and maintainability.
-   **Clear Feedback**: Detailed feedback from the `AdminMonitorService` helps understand why ad copy might need revision (though currently only logged, it can be displayed to the user).

## 5. Database Schema (`ad_copies` table)

-   `id`: Primary key.
-   `strategy_id`: Foreign key to the `strategies` table.
-   `platform`: String (e.g., 'Google Ads', 'Facebook Ads').
-   `headlines`: JSON array of strings.
-   `descriptions`: JSON array of strings.
-   `created_at`, `updated_at`: Timestamps.
