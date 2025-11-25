@extends('layouts.email')

@section('title', 'Your Sitemap Has Been Processed')

@section('content')
    <h1>Sitemap Processing Complete!</h1>
    <p>Hi {{ $userName }},</p>
    <p>Great news! We've finished crawling your sitemap and your knowledge base is ready to use.</p>
    
    <div style="background-color: #f0f9ff; border-left: 4px solid #0369a1; padding: 15px; margin: 20px 0;">
        <p style="margin: 0;"><strong>Sitemap URL:</strong> {{ $sitemapUrl }}</p>
        <p style="margin: 10px 0 0 0;"><strong>Pages Crawled:</strong> {{ $totalPages }}</p>
    </div>
    
    <p>Your website content has been analyzed and added to your knowledge base. You can now:</p>
    <ul>
        <li><strong>Create Campaigns:</strong> Our AI will use your site content to generate targeted campaigns</li>
        <li><strong>Generate Collateral:</strong> Create ads, copy, and creatives informed by your brand</li>
        <li><strong>View Your Knowledge Base:</strong> See what content we've extracted from your site</li>
    </ul>
    
    <p style="text-align: center; margin-top: 30px; margin-bottom: 30px;">
        <a href="{{ url('/knowledge-base') }}" style="background-color: #0369a1; color: #ffffff; padding: 12px 25px; text-decoration: none; border-radius: 5px; font-weight: bold;">View Your Knowledge Base</a>
    </p>
    
    <p>Ready to create your first campaign with this knowledge? Head to your dashboard to get started.</p>
    
    <p>Best,<br>The CVSEEYOU Team</p>
@endsection
