import { ChevronDownIcon, QuestionMarkCircleIcon } from '@heroicons/react/24/outline';

export default function FindSitemapInstructions({ isOpen = false, onToggle }) {
    return (
        <div className="rounded-xl border border-air-superiority-blue/30 bg-gradient-to-br from-air-superiority-blue/5 to-delft-blue/5 overflow-hidden transition-all duration-300">
            <button
                type="button"
                onClick={onToggle}
                className="w-full flex items-center justify-between px-5 py-3.5 text-left hover:bg-air-superiority-blue/10 transition-colors"
            >
                <span className="flex items-center gap-2 text-sm font-medium text-delft-blue">
                    <QuestionMarkCircleIcon className="h-5 w-5 text-air-superiority-blue" />
                    Need help finding your sitemap?
                </span>
                <ChevronDownIcon
                    className={`h-4 w-4 text-delft-blue transition-transform duration-300 ${isOpen ? 'rotate-180' : ''}`}
                />
            </button>

            <div
                className={`grid transition-all duration-300 ease-in-out ${
                    isOpen ? 'grid-rows-[1fr] opacity-100' : 'grid-rows-[0fr] opacity-0'
                }`}
            >
                <div className="overflow-hidden">
                    <div className="px-5 pb-5 pt-1">
                        <ol className="space-y-3 text-sm text-jet">
                            <li className="flex gap-3">
                                <span className="flex-shrink-0 flex items-center justify-center w-6 h-6 rounded-full bg-delft-blue text-white text-xs font-bold">1</span>
                                <div>
                                    <strong className="text-delft-blue">Check Common Locations</strong>
                                    <p className="text-gray-600 mt-0.5">Most websites have their sitemap at a standard URL:</p>
                                    <div className="mt-1.5 flex flex-wrap gap-2">
                                        <code className="px-2.5 py-1 bg-white rounded-md text-xs border border-gray-200 text-delft-blue font-mono">yourwebsite.com/sitemap.xml</code>
                                        <code className="px-2.5 py-1 bg-white rounded-md text-xs border border-gray-200 text-delft-blue font-mono">yourwebsite.com/sitemap_index.xml</code>
                                    </div>
                                </div>
                            </li>
                            <li className="flex gap-3">
                                <span className="flex-shrink-0 flex items-center justify-center w-6 h-6 rounded-full bg-delft-blue text-white text-xs font-bold">2</span>
                                <div>
                                    <strong className="text-delft-blue">Check robots.txt</strong>
                                    <p className="text-gray-600 mt-0.5">
                                        Visit <code className="text-xs font-mono text-delft-blue">yourwebsite.com/robots.txt</code> — it often contains:
                                    </p>
                                    <code className="mt-1.5 block px-2.5 py-1 bg-white rounded-md text-xs border border-gray-200 text-delft-blue font-mono">
                                        Sitemap: https://yourwebsite.com/sitemap.xml
                                    </code>
                                </div>
                            </li>
                            <li className="flex gap-3">
                                <span className="flex-shrink-0 flex items-center justify-center w-6 h-6 rounded-full bg-delft-blue text-white text-xs font-bold">3</span>
                                <div>
                                    <strong className="text-delft-blue">Google Search Console</strong>
                                    <p className="text-gray-600 mt-0.5">Find submitted sitemaps under the "Sitemaps" section in the sidebar.</p>
                                </div>
                            </li>
                            <li className="flex gap-3">
                                <span className="flex-shrink-0 flex items-center justify-center w-6 h-6 rounded-full bg-delft-blue text-white text-xs font-bold">4</span>
                                <div>
                                    <strong className="text-delft-blue">CMS / Plugin Settings</strong>
                                    <p className="text-gray-600 mt-0.5">WordPress SEO plugins (Yoast, Rank Math) generate sitemaps automatically — check their settings.</p>
                                </div>
                            </li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>
    );
}
