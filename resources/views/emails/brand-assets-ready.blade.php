<x-mail::message>
# 🎨 Your Brand DNA is Ready!

Hi {{ $user->name }},

Great news! We've successfully analyzed **{{ $pagesExtracted }} pages** from **{{ $customer->name }}** and extracted your brand's visual DNA.

## What We Found:

✅ **Primary Colors** - Your hex codes are locked and loaded  
✅ **Typography** - Fonts identified and ready to use  
✅ **Brand Voice** - Tone and messaging patterns extracted  
✅ **Visual Style** - Design elements captured

Your personalized dashboard is now populated with **on-brand ad campaigns** ready to review.

<x-mail::button :url="route('dashboard')">
View Your Brand Assets
</x-mail::button>

### What's Next?

1. **Review your brand profile** - Make sure everything looks accurate
2. **Generate more ads** - Create unlimited variations (it's free!)
3. **Run a CRO audit** - We'll analyze your landing pages for conversion killers

The best part? All of this is **completely free**. When you're ready to go live, upgrading takes just 2 clicks.

Questions? Just hit reply—we're here to help.

Thanks,<br>
The {{ config('app.name') }} Team

---

<small>P.S. Your free tier includes **3 landing page audits**. Want to see what's holding back your conversions? [Run an audit now →]({{ route('dashboard') }})</small>
</x-mail::message>
