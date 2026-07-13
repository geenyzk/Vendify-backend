{{ $heading ?? 'Vendify notification' }}

@isset($intro)
{{ $intro }}

@endisset
@isset($body)
{{ $body }}

@endisset
@isset($details)
@foreach($details as $label => $value)
{{ $label }}: {{ $value }}
@endforeach

@endisset
@if(!empty($actionText) && !empty($actionUrl))
{{ $actionText }}: {{ $actionUrl }}

@endif
Need help? Contact {{ \App\Support\MailDeliverability::supportAddress() }}.

{{ $footerNote ?? 'This email was sent by Vendify.' }}
(c) {{ date('Y') }} Vendify. All rights reserved.
