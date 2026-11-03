@include('mail.partials.frame-start', ['title' => 'Reset your password'])
<p style="margin:0 0 16px;">Hello{{ $name !== '' ? ' '.$name : '' }},</p>
<p style="margin:0 0 16px;">A password reset was requested for your Meetara HRMS account. Use the button below to choose a new password.</p>
<p style="margin:0 0 20px;">
    <a href="{{ $url }}" style="display:inline-block;background:#2563eb;color:#ffffff;text-decoration:none;border-radius:8px;padding:12px 18px;font-weight:600;">Reset your password</a>
</p>
<p style="margin:0 0 12px;">Or copy this link into your browser:<br><a href="{{ $url }}" style="color:#2563eb;word-break:break-all;">{{ $url }}</a></p>
<p style="margin:0;">This link expires in {{ $minutes }} minutes and can be used once. If you did not request a reset, you can ignore this email.</p>
@include('mail.partials.frame-end')
