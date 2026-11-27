import PublicMarketingResources from '@/Components/Marketing/PublicMarketingResources';
import PageTitle from '@/Components/PageTitle';
import Header from '@/Components/Header';
import Footer from '@/Components/Footer';
import Hero from '@/Components/Marketing/Hero';
import { FeatureSteps } from '@/Components/Marketing/FeatureGrid';
import { DocumentCheckIcon, AdjustmentsHorizontalIcon, ChartBarIcon } from '@heroicons/react/24/outline';

const steps = [
    {
        icon: DocumentCheckIcon,
        title: 'Preview the copy for your business',
        body: 'Start with your website and review the sample ads. When you create your workspace, confirm the business facts, the services you sell and where you can serve customers.',
        bullets: ['Check the offer and service area', 'Choose the page visitors should land on'],
        panel: { title: 'Business profile', detail: 'Your website, offer and customers' },
    },
    {
        icon: AdjustmentsHorizontalIcon,
        title: 'Approve your campaign and budget',
        body: 'Review the proposed ads and targeting, set the Google Ads budget and check that the action you want to measure works on your website. Google reviews the ads before they can serve.',
        bullets: ['Approve the campaign before launch', 'Review the monthly management fee and separate media budget'],
        panel: { title: 'Launch approval', detail: 'Your ads, destination, budget and conversion action' },
    },
    {
        icon: ChartBarIcon,
        title: 'Follow what happens after launch',
        body: 'Open your dashboard to review delivery, media spend and tracked conversions. The activity history records campaign changes. Check lead quality against the calls and forms your business actually receives.',
        bullets: ['Review changes and their reasons', 'Follow up on billing, consent or website actions that need your help'],
        panel: { title: 'Campaign report', detail: 'Spend, conversions and campaign activity' },
    },
];

export default function HowItWorks({ auth, publicContent }) {
    return (
        <>
            <PageTitle />
            <div className="min-h-screen bg-white">
                <Header auth={auth} />
                <main>
                    <Hero eyebrow={publicContent.eyebrow} headline={publicContent.headline} sub={publicContent.intro} />
                    <FeatureSteps items={steps} cta={{ href: '/register', label: 'Start free' }} note="No credit card required" />
                    <PublicMarketingResources content={publicContent} />
                </main>
                <Footer />
            </div>
        </>
    );
}
