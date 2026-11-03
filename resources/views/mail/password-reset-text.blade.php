Hello{{ $name !== '' ? ' '.$name : '' }},

A password reset was requested for your Veltrix HRMS account. Open this link to choose a new password:

{{ $url }}

This link expires in {{ $minutes }} minutes and can be used once. If you did not request a reset, you can ignore this email.
