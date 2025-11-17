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

### 3.7. Public Pages

- **Welcome Page** [Built]
  - `HeroSection`: The main landing page hero section.
  - `FeatureGrid`: A grid of features and benefits.
- **Pricing Page** [Built]
  - `PricingTable`: A table of subscription tiers and their features. [Built]

---

## 4. Email Components

These are transactional email templates that need to be designed and built. They are not frontend components in the traditional sense but are a key part of the user experience.

- **`WelcomeEmail`**: Sent to new users upon registration.
- **`PasswordResetEmail`**: Contains a secure link for resetting a password.
- **`InvoicePaidEmail`**: A receipt sent after a successful subscription payment, often generated by **Stripe's billing webhooks**.
- **`RecommendationApprovalEmail`**: Notifies the user that a new recommendation is ready for their review.
- **`CampaignReportEmail`**: A weekly or monthly summary of campaign performance.

---

## 5. UX Workflows

This section details the key user journeys within the application.

### 5.1. Onboarding

1. **User Registration**: User signs up via the `RegistrationForm`.
2. **Welcome Email**: A `WelcomeEmail` is dispatched.
3. **First Login & Profile Setup**: User is prompted to complete their profile (e.g., company name, website).
4. **Subscription Selection**: User is directed to the **Billing Page** to select a subscription plan using the `SubscriptionTierSelector`.
5. **Payment**: User completes payment using the **`StripeCheckoutForm`**.

### 5.2. Campaign Creation

1. **Initiate Creation**: User clicks the `CreateCampaignButton` from the **Campaign List Page**.
2. **Campaign Brief Form**: User fills out a multi-step form detailing the campaign goals, budget, target market, etc.
3. **AI Strategy Generation**: Upon submission, a `GenerateStrategy` job is dispatched. The UI shows a loading state.
4. **Notification**: User is notified (e.g., via email and an in-app notification) when the strategy and initial collateral are ready for review.

### 5.3. Collateral Review

1. **Navigate to Campaign**: User navigates to the **Campaign Detail Page** from the notification or the **Campaign List Page**.
2. **Review Strategies**: User reviews the AI-generated ad copy, imagery, and video concepts within the `StrategyDetail` components.
3. **Request Revisions**: User can provide feedback to request refinements to the creative assets.
4. **Approve Collateral**: User approves the collateral for each platform, triggering the next step in the process.

### 5.4. Campaign Publishing

1. **Final Review**: User reviews the complete, approved campaign.
2. **Subscription Check**: Before publishing, the system verifies that the user has a valid, active subscription. If not, they are redirected to the **Billing Page**.
3. **Publish Action**: User clicks a "Publish" button.
4. **Publishing Job**: A job is dispatched to push the campaign and its assets to the respective ad platforms (Google Ads, Facebook Ads, etc.).
5. **Confirmation**: The UI updates to a "Published" status, and the user receives a confirmation.

### 5.5. Ongoing Campaign Review

1. **Performance Monitoring**: User regularly visits the **Dashboard Page** and the **Campaign Detail Page** to monitor performance via the `PerformanceSummary` and `PerformanceChart` components.
2. **Receive Recommendations**: The system's `PortfolioOptimizationService` generates recommendations, which appear in the `ActionableInsights` section on the dashboard and as a `RecommendationCard` on the campaign detail page.
3. **Approve/Reject**: User reviews the rationale and decides to approve or reject the recommendation.
4. **Conflict Resolution**: If a recommendation conflicts with a manual change, a `ConflictCard` is displayed, prompting the user to resolve it.
5. **Rollback**: If a campaign is underperforming, the user can use a "Rollback" feature in the `CampaignHeader` to revert to a previous version of the strategy.
