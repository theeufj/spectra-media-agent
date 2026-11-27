import { isFunnelGuest } from '@/hooks/useSignupFunnel';
import PageTitle from '@/Components/PageTitle';
import Header from '@/Components/Header';
import Footer from '@/Components/Footer';
import WebsiteAdPreview from '@/Components/Marketing/WebsiteAdPreview';
import { AD_PREVIEW_CTA } from '@/Components/Marketing/Hero';
import FeatureGrid from '@/Components/Marketing/FeatureGrid';
import GoogleManagementPlan from '@/Components/Marketing/GoogleManagementPlan';
import SampleCampaignReport from '@/Components/Marketing/SampleCampaignReport';
import FaqAccordion from '@/Components/Marketing/FaqAccordion';
import PublicMarketingResources from '@/Components/Marketing/PublicMarketingResources';
import { AdjustmentsHorizontalIcon, ChartBarIcon, DocumentCheckIcon } from '@heroicons/react/24/outline';

const previewCta = { ...AD_PREVIEW_CTA, href: '#build-my-ads' };
const steps = [
    { icon: DocumentCheckIcon, title: 'Check the ads', body: 'Preview copy from your website. In your workspace, confirm the offer, keywords, landing page and service area before launch.' },
    { icon: AdjustmentsHorizontalIcon, title: 'Approve your budget', body: 'Choose the daily amount to spend on Google. Your management subscription and advertising spend are billed separately.' },
    { icon: ChartBarIcon, title: 'Follow the enquiries', body: 'Test the action you want to track. After launch, review spend, tracked conversions and the activity history explaining changes to your campaign.' },
];

export default function Landing({ tenant, auth, publicContent, plans = [], faqs = [] }) {
    return (
        <>
            <PageTitle />
            <div className="min-h-screen bg-white">
                <Header auth={auth} guestCta={previewCta} />
                <main>
                    <WebsiteAdPreview measureFunnel={isFunnelGuest(tenant, auth)} eyebrow={publicContent.eyebrow} headline={publicContent.headline} sub={publicContent.intro}
                        secondaryCta={{ href: '#sample-report', label: 'See an example report' }} note="Free preview · No credit card required" />
                    <SampleCampaignReport />
                    <FeatureGrid title="What you control" items={steps} background="white" />
                    <GoogleManagementPlan plans={plans} />
                    {faqs.length > 0 && (
                        <section className="mx-auto max-w-3xl px-4 py-12 sm:px-6">
                            <h2 className="mb-6 text-2xl font-bold text-gray-900">Questions before you start</h2>
                            <FaqAccordion items={faqs} />
                        </section>
                    )}
                    <PublicMarketingResources content={publicContent} />
                </main>
                <Footer guestCta={previewCta} />
            </div>
        </>
    );
}
