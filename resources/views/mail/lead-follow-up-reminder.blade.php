@include('mail.partials.frame-start', ['title' => 'Lead follow-up reminder'])
<p style="margin:0 0 16px;">Hello {{ $recipientName }},</p>
<p style="margin:0 0 16px;">{{ $companyName }} has a lead follow-up due for <strong>{{ $leadName }}</strong>.</p>
<p style="margin:0 0 8px;"><strong>Phone:</strong> {{ $leadPhone !== '' ? $leadPhone : '—' }}</p>
<p style="margin:0 0 8px;"><strong>Status:</strong> {{ $statusLabel }}</p>
<p style="margin:0 0 16px;"><strong>Follow-up date:</strong> {{ $followUpOn }}</p>
<p style="margin:0 0 20px;">
    <a href="{{ $url }}" style="display:inline-block;background:#2563eb;color:#ffffff;text-decoration:none;border-radius:8px;padding:12px 18px;font-weight:600;">Open lead</a>
</p>
<p style="margin:0;">Or copy this link into your browser:<br><a href="{{ $url }}" style="color:#2563eb;word-break:break-all;">{{ $url }}</a></p>
@include('mail.partials.frame-end')
