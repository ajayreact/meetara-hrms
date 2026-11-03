@include('mail.partials.frame-start', ['title' => 'Set your password'])
<p style="margin:0 0 16px;">Hello {{ $employeeName }},</p>
<p style="margin:0 0 16px;">{{ $companyName }} invited you to Meetara HRMS. Use the button below to set your password and activate your account.</p>
<p style="margin:0 0 20px;">
    <a href="{{ $url }}" style="display:inline-block;background:#2563eb;color:#ffffff;text-decoration:none;border-radius:8px;padding:12px 18px;font-weight:600;">Set your password</a>
</p>
<p style="margin:0 0 12px;">Or copy this link into your browser:<br><a href="{{ $url }}" style="color:#2563eb;word-break:break-all;">{{ $url }}</a></p>
<p style="margin:0;">This link expires in {{ $hours }} hours, on {{ $expiresAt }}. It can be used once.</p>
@include('mail.partials.frame-end')
