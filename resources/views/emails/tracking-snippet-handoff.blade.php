<x-mail::message>
# Tracking code for {{ $customer->name }}

{{ $requestedBy }} asked us to send you this. It is a standard Google Tag
Manager container, and it lets {{ $customer->name }} see which website visits
came from their advertising and which ones turned into enquiries or sales.

It is two snippets. Both go on every page of
@if ($customer->website)
[{{ $customer->website }}]({{ $customer->website }}).
@else
the website.
@endif

**1. Paste this inside `<head>`, as high up as possible:**

```html
{{ $snippet['head'] }}
```

**2. Paste this immediately after the opening `<body>` tag:**

```html
{{ $snippet['body'] }}
```

That is the whole job — nothing else to configure. We detect it automatically
once it is live, usually within a few minutes.

@if ($customer->gtm_container_id)
Container ID, if you need it: **{{ $customer->gtm_container_id }}**
@endif

If the site already has Google Tag Manager, send us the existing container ID
instead and we will work with that rather than adding a second one.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
