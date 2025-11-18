# Frontend Component Architecture

## 1. Introduction

This document outlines the component-based architecture for the Spectra frontend. The components are organized by page to provide a clear development roadmap. This is a living document and will evolve as the application grows.

---

## 2. Core / Shared Components

These are foundational UI elements that will be used across multiple pages to ensure a consistent user experience.

- **`AppLayout`**: The main application shell, including the primary navigation sidebar and top bar. [Built]
- **`Navbar`**: Top navigation bar, containing user profile/logout, and global notifications. [Built]
- **`Sidebar`**: Main navigation sidebar for accessing different sections like Dashboard, Campaigns, and Billing. [Built]
- **`Card`**: A flexible container for displaying content sections. [Built]
- **`Button`**: A customizable button component with variants for primary, secondary, and destructive actions. [Built]
- **`Modal`**: A dialog component for alerts, confirmations, or displaying forms. [Built]
- **`Spinner`**: A loading indicator for asynchronous operations. [Built]
- **`DataTable`**: A reusable table for displaying lists of data (e.g., campaigns, invoices). [Built]

---

## 3. Pages & Components

### 3.1. Authentication

- **Login Page** [Built]
  - `LoginForm`: A form for email and password authentication. [Built]
  - `SocialLoginButtons`: Buttons for OAuth providers (Google, etc.).
- **Registration Page** [Built]
  - `RegistrationForm`: A multi-step form for new user sign-up. [Built]
- **Forgot Password Page** [Built]
  - `ForgotPasswordForm`: A form to request a password reset link. [Built]

### 3.2. Dashboard

- **Dashboard Page** [Built]
  - `DateRangePicker`: Allows users to filter dashboard data by a specific time period. [Built]
  - `PerformanceSummary`: A set of cards displaying high-level metrics (Total Spend, Total Revenue, Overall ROAS) from `DashboardDataService`. [Built]
  - `CampaignOverviewChart`: A chart visualizing the performance of the top 5 campaigns. [Built]
  - `ActionableInsights`: A section highlighting pending recommendations and unresolved conflicts.
  - `RecentActivityFeed`: A list of recent events (e.g., "Strategy generated for Campaign X", "Recommendation approved"). [Built]

### 3.3. Campaign Management

- **Campaign List Page** [Built]
  - `CampaignTable`: Displays a list of all user campaigns with key metrics and status. [Built]
  - `CreateCampaignButton`: A primary action button that initiates the campaign creation flow. [Built]
- **Campaign Detail Page** [Built]
  - `CampaignHeader`: Displays the campaign name, status, and primary actions (e.g., Pause, Rollback). [Built]
  - `StrategyDetail`: A component to display the ad copy, imagery, and bidding strategy for a specific platform. [Built]
  - `PerformanceChart`: A detailed chart showing the performance trend (ROAS, Spend) for this specific campaign over time. [Built]
  - `RecommendationCard`: Displays a pending recommendation, allowing the user to approve or reject it. [Built]
  - `ConflictCard`: Displays an unresolved conflict, providing context and options for resolution. [Built]
  - `RollbackModal`: A modal for selecting a previous version to roll back to, showing version history.
- **Campaign Creation Page** [Built]
  - `CampaignForm`: A multi-step form for creating a new campaign. [Built]
- **Collateral Viewer Page** [Built]
  - `CollateralGrid`: Displays the generated ad copy, images, and videos for a specific strategy. [Built]
  - `RefineImageModal`: A modal for providing a prompt to refine a generated image. [Built]

### 3.4. Knowledge Base

- **Knowledge Base List Page** [Built]
  - `KnowledgeBaseTable`: Displays a list of all the user's knowledge base articles. [Built]
- **Knowledge Base Create Page** [Built]
  - `KnowledgeBaseForm`: A form for creating a new knowledge base article. [Built]

### 3.5. User Profile

- **Profile Edit Page**
  - `UpdateProfileInformationForm`: A form for updating the user's name and email address. [Built]
  - `UpdatePasswordForm`: A form for updating the user's password. [Built]
  - `DeleteUserForm`: A form for deleting the user's account. [Built]

### 3.6. Billing & Subscriptions

- **Billing Page** [Built]
  - `SubscriptionTierSelector`: Allows users to choose and switch between different subscription plans. [Built]
  - **`StripeCheckoutForm`**: A secure, embedded form using **Stripe Elements** for capturing payment information. This is a critical component for handling credit card data securely. [Built]
  - `InvoiceHistory`: A table listing past invoices with links to download them. [Built]

### 3.7. Google Tag Manager Setup

- **GTM Setup Page**
  - `GTMStatusCard`: Displays the current GTM detection status and container information.
  - `GTMPathSelector`: Automatically routes users to Path A (existing GTM) or Path B (new GTM) based on detection results.
  - `GTMLinkForm`: Form for linking an existing GTM container with OAuth authentication.
  - `GTMCreateForm`: Form and instructions for creating a new GTM container.
  - `GTMTagConfiguration`: Interface for configuring conversion tags (Google Ads, Facebook Pixel, GA4).
  - `GTMInstallationInstructions`: Step-by-step guide for installing GTM snippet on website.
  - `GTMVerificationStatus`: Shows the verification status of GTM installation and tag firing.
  - `GTMRescanButton`: Allows users to manually trigger a website re-scan for GTM detection.

### 3.8. Public Pages

- **Welcome Page** [Built]
  - `HeroSection`: The main landing page hero section.
  - `FeatureGrid`: A grid of features and benefits.
- **Pricing Page** [Built]
  - `PricingTable`: A table of subscription tiers and their features. [Built]

### 3.9. Admin Pages

- **Admin Dashboard** [Built]
  - `AdminStatsOverview`: High-level platform statistics.
  - `UserManagementTable`: List of all users with admin actions.
  - `CustomerManagementTable`: List of all customers with admin actions.
- **Admin Notification Center** [Built]
  - `NotificationComposer`: Form for sending platform-wide or targeted notifications.

---

## 4. Email Components

These are transactional email templates that need to be designed and built. They are not frontend components in the traditional sense but are a key part of the user experience.

- **`WelcomeEmail`**: Sent to new users upon registration.
- **`PasswordResetEmail`**: Contains a secure link for resetting a password.
- **`InvoicePaidEmail`**: A receipt sent after a successful subscription payment, often generated by **Stripe's billing webhooks**.
- **`RecommendationApprovalEmail`**: Notifies the user that a new recommendation is ready for their review.
- **`CampaignReportEmail`**: A weekly or monthly summary of campaign performance.
- **`ConflictDetectedEmail`**: Alerts users when a conflict between AI recommendations and manual changes is detected.
- **`GTMSetupCompleteEmail`**: Confirms successful GTM integration and conversion tracking setup.
- **`BudgetAllocationEmail`**: Notifies users of automated budget reallocation across campaigns.

---

## 5. UX Workflows

This section details the key user journeys within the application.

### 5.1. Onboarding

1. **User Registration**: User signs up via the `RegistrationForm`.
2. **Welcome Email**: A `WelcomeEmail` is dispatched.
3. **First Login & Profile Setup**: User is prompted to complete their profile (e.g., company name, website).
4. **Website Scraping**: System automatically scrapes the website and detects GTM presence.
5. **Subscription Selection**: User is directed to the **Billing Page** to select a subscription plan using the `SubscriptionTierSelector`.
6. **Payment**: User completes payment using the **`StripeCheckoutForm`**.

### 5.2. GTM Setup (New Workflow)

1. **Automatic Detection**: Upon customer creation or website update, the system scrapes the website and detects GTM.
2. **Navigate to GTM Setup**: User is prompted or navigates to the GTM setup page.
3. **Path Routing**:
   - **Path A (GTM Detected)**: 
     - System shows detected GTM container ID
     - User clicks "Link Existing Container"
     - OAuth flow authenticates with Google
     - System verifies access to container
     - User selects conversion events to track
     - System generates and adds tags via GTM API
     - Confirmation shown with verification status
   - **Path B (No GTM Detected)**:
     - System shows installation instructions
     - User can choose to create new container
     - System generates GTM snippet
     - User installs snippet on website
     - System verifies installation
     - Conversion tags are added once verified
4. **Tag Configuration**: User reviews and approves default conversion tags (Google Ads, Facebook Pixel, GA4).
5. **Verification**: System confirms tags are firing correctly.
6. **Completion Email**: `GTMSetupCompleteEmail` is sent.

### 5.3. Campaign Creation

1. **Initiate Creation**: User clicks the `CreateCampaignButton` from the **Campaign List Page**.
2. **Campaign Brief Form**: User fills out a multi-step form detailing the campaign goals, budget, target market, etc.
3. **AI Strategy Generation**: Upon submission, a `GenerateStrategy` job is dispatched. The UI shows a loading state.
4. **Notification**: User is notified (e.g., via email and an in-app notification) when the strategy and initial collateral are ready for review.

### 5.4. Collateral Review

1. **Navigate to Campaign**: User navigates to the **Campaign Detail Page** from the notification or the **Campaign List Page**.
2. **Review Strategies**: User reviews the AI-generated ad copy, imagery, and video concepts within the `StrategyDetail` components.
3. **Request Revisions**: User can provide feedback to request refinements to the creative assets.
4. **Approve Collateral**: User approves the collateral for each platform, triggering the next step in the process.

### 5.5. Campaign Publishing

1. **Final Review**: User reviews the complete, approved campaign.
2. **Subscription Check**: Before publishing, the system verifies that the user has a valid, active subscription. If not, they are redirected to the **Billing Page**.
3. **Conversion Tracking Check**: System verifies GTM is installed and conversion tags are configured.
4. **Publish Action**: User clicks a "Publish" button.
5. **Publishing Job**: A job is dispatched to push the campaign and its assets to the respective ad platforms (Google Ads, Facebook Ads, etc.).
6. **Confirmation**: The UI updates to a "Published" status, and the user receives a confirmation.

### 5.6. Ongoing Campaign Review

1. **Performance Monitoring**: User regularly visits the **Dashboard Page** and the **Campaign Detail Page** to monitor performance via the `PerformanceSummary` and `PerformanceChart` components.
2. **Receive Recommendations**: The system's `PortfolioOptimizationService` generates recommendations, which appear in the `ActionableInsights` section on the dashboard and as a `RecommendationCard` on the campaign detail page.
3. **Approve/Reject**: User reviews the rationale and decides to approve or reject the recommendation.
4. **Conflict Resolution**: If a recommendation conflicts with a manual change, a `ConflictCard` is displayed, prompting the user to resolve it.
5. **Rollback**: If a campaign is underperforming, the user can use a "Rollback" feature in the `CampaignHeader` to revert to a previous version of the strategy.

### 5.7. Conflict Resolution Workflow (New)

1. **User Makes Manual Change**: User directly modifies campaign budget, targeting, or other settings.
2. **Conflict Detection**: System detects a pending AI recommendation that conflicts with the manual change.
3. **Notification**: User receives an alert about the conflict via `ConflictCard` on the campaign page.
4. **Review Options**: User reviews:
   - The manual change they made
   - The AI recommendation and its rationale
   - Predicted impact of each option
5. **Resolution Decision**:
   - **Keep Manual Change**: User rejects the AI recommendation
   - **Accept AI Recommendation**: User reverts their manual change
   - **Defer**: User postpones the decision for later review
6. **Execution**: System executes the chosen action and logs the decision.
7. **Learning**: System records the outcome to improve future recommendations.

### 5.8. Budget Reallocation Workflow (New)

1. **Automatic Trigger**: `BudgetAllocationService` runs on schedule or when total budget changes.
2. **Performance Analysis**: System calculates ROAS for all active campaigns.
3. **Budget Calculation**: System distributes total budget based on performance weights.
4. **User Notification**: User receives `BudgetAllocationEmail` with details of the reallocation.
5. **Review Interface**: User can view the new allocations in the dashboard with justifications.
6. **Override Option**: User can manually adjust allocations if needed, which may trigger conflict detection.
7. **Platform Sync**: System updates campaign budgets on Google Ads and Facebook Ads.

### 5.9. Campaign Rollback Workflow (New)

1. **Performance Issue**: User notices campaign underperformance or adverse effects from a recent change.
2. **Initiate Rollback**: User clicks "Rollback" button in `CampaignHeader`.
3. **Version Selection**: `RollbackModal` displays campaign history with performance metrics for each version.
4. **Review Impact**: User sees what settings will be reverted (copy, targeting, budget, bidding).
5. **Confirmation**: User confirms rollback with optional reason.
6. **Execution**: `RollbackCampaignService` restores previous configuration.
7. **Platform Sync**: Changes are pushed to advertising platforms.
8. **Verification**: User receives confirmation and can monitor restored campaign performance.
9. **Audit Trail**: Rollback action is logged in campaign history for future reference.
