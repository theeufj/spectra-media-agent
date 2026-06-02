@extends('layouts.email')

@section('title', 'Your Sitemap Has Been Processed')

@section('content')
    <h1>Sitemap Processing Complete!</h1>
    <p>Hi {{ $userName }},</p>
    <p>We've finished crawling your sitemap — <strong>{{ $totalPages }} pages</strong> from <strong>{{ $sitemapUrl }}</strong> are now in your knowledge base. Your AI agents will use this content to write ads that sound exactly like your brand.</p>

    <h2 style="font-size: 16px; color: #2d3748; margin: 28px 0 12px;">What to do next</h2>

    <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom: 24px;">
        <tr>
            <td style="padding: 14px 16px; background: #f7fafc; border: 1px solid #e2e8f0; border-radius: 6px 6px 0 0; border-bottom: none;">
                <strong style="color: #ff4d00;">Step 1 &rarr;</strong> Review your pages
                <div style="font-size: 13px; color: #718096; margin-top: 4px;">Check that the right content was captured in your knowledge base.</div>
            </td>
        </tr>
        <tr>
            <td style="padding: 14px 16px; background: #f7fafc; border: 1px solid #e2e8f0; border-bottom: none;">
                <strong style="color: #ff4d00;">Step 2 &rarr;</strong> Create a campaign
                <div style="font-size: 13px; color: #718096; margin-top: 4px;">Launch a campaign — our AI will build ads from your site content automatically.</div>
            </td>
        </tr>
        <tr>
            <td style="padding: 14px 16px; background: #f7fafc; border: 1px solid #e2e8f0; border-radius: 0 0 6px 6px;">
                <strong style="color: #ff4d00;">Step 3 &rarr;</strong> Deploy your ads
                <div style="font-size: 13px; color: #718096; margin-top: 4px;">Approve and push to Google or Facebook in one click.</div>
            </td>
        </tr>
    </table>

    <p style="text-align: center; margin-top: 30px; margin-bottom: 30px;">
        <a href="{{ url('/campaigns/create') }}" style="background: linear-gradient(135deg, #ff4d00 0%, #cc3d00 100%); color: #ffffff; padding: 14px 28px; text-decoration: none; border-radius: 6px; font-weight: 700;">Create Your First Campaign</a>
    </p>

    <p style="text-align: center; font-size: 13px; color: #a0aec0;">
        Or <a href="{{ url('/knowledge-base') }}" style="color: #718096;">view your knowledge base</a> first.
    </p>
@endsection
