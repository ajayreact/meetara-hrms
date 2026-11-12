Hello {{ $recipientName }},

{{ $companyName }} has a lead follow-up due for {{ $leadName }}.

Phone: {{ $leadPhone !== '' ? $leadPhone : '—' }}
Status: {{ $statusLabel }}
Follow-up date: {{ $followUpOn }}

Open the lead: {{ $url }}
