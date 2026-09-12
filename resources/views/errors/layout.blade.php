<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} - {{ config('app.name') }}</title>
    <link rel="icon" type="image/x-icon" href="/favicon.ico?v=2">
    {{--
        Included rather than extended on purpose: @extends/@yield leaves an
        output buffer open when a response is captured in a test, which marks
        every test that touches an error page risky. A plain include takes
        variables and buffers nothing.

        These pages used to be dark navy with a purple gradient numeral and a
        purple button — a palette and typeface that appear nowhere else in the
        product. A customer who mistyped a URL or followed a stale link was
        shown something that looked like it belonged to someone else, with no
        navigation and a single "Back to Home" link out.
    --}}
    <style>
        :root {
            --brand: #ff4d00;
            --brand-dark: #cc3d00;
            --ink: #111827;
            --ink-soft: #4b5563;
            --ink-faint: #6b7280;
            --ground: #f9fafb;
            --line: #e5e7eb;
        }
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: Figtree, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--ground);
            color: var(--ink);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        header {
            border-bottom: 1px solid var(--line);
            background: #fff;
            padding: 1.1rem 1.5rem;
        }
        .brand {
            font-size: 1.25rem; font-weight: 700; color: var(--brand);
            text-decoration: none; letter-spacing: -.02em;
        }
        main { flex: 1; display: flex; align-items: center; justify-content: center; padding: 3rem 1.5rem; }
        .panel { max-width: 32rem; text-align: center; }
        .code {
            font-size: .8rem; font-weight: 600; letter-spacing: .14em;
            text-transform: uppercase; color: var(--brand); margin-bottom: .75rem;
        }
        h1 { font-size: 1.6rem; line-height: 1.25; margin-bottom: .6rem; }
        p { color: var(--ink-soft); line-height: 1.6; margin-bottom: 1.75rem; }
        .actions { display: flex; flex-wrap: wrap; gap: .6rem; justify-content: center; }
        a.button {
            display: inline-block; padding: .7rem 1.4rem; border-radius: .5rem;
            font-weight: 600; text-decoration: none; font-size: .95rem;
        }
        a.primary { background: var(--brand-dark); color: #fff; }
        a.primary:hover { background: #992e00; }
        a.secondary { background: #fff; color: var(--ink); border: 1px solid var(--line); }
        a.secondary:hover { background: var(--ground); }
        a:focus-visible { outline: 2px solid var(--brand); outline-offset: 2px; }
        footer { padding: 1.5rem; text-align: center; color: var(--ink-faint); font-size: .85rem; }
        footer a { color: var(--ink-faint); }
    </style>
</head>
<body>
    <header><a class="brand" href="/">{{ config('app.name') }}</a></header>

    <main>
        <div class="panel">
            <p class="code">{{ $code }}</p>
            <h1>{{ $title }}</h1>
            <p>{{ $message }}</p>
            <div class="actions">
                <a class="button primary" href="{{ auth()->check() ? url('/dashboard') : url('/') }}">
                    {{ auth()->check() ? 'Back to dashboard' : 'Back to home' }}
                </a>
            </div>
        </div>
    </main>

    <footer>
        Still stuck? <a href="{{ url('/support-tickets/create') }}">Tell us what happened</a>.
    </footer>
</body>
</html>
