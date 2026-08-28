@php
    // text/plain alternative for emails/notification.blade.php. Raw output
    // ({!! !!}) on purpose: entity-escaping belongs to HTML, and this part
    // is plain text. Markdown emphasis markers are stripped, not rendered.
    $plain = fn ($line) => preg_replace('/\*\*(.*?)\*\*/s', '$1', (string) $line);
@endphp
@if (! empty($greeting)){!! $plain($greeting) !!}

@endif
@foreach ($introLines ?? [] as $line){!! $plain($line) !!}

@endforeach
@if (! empty($actionText)){!! $plain($actionText) !!}: {!! $actionUrl !!}

@endif
@foreach ($outroLines ?? [] as $line){!! $plain($line) !!}

@endforeach
@if (! empty($salutation)){!! $plain($salutation) !!}
@endif
