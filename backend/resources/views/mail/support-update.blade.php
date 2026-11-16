<!doctype html>
<html lang="en">
<body style="margin:0;padding:24px;background:#f4f5f7;font-family:Arial,Helvetica,sans-serif;color:#1b1f27;">
  <div style="max-width:520px;margin:0 auto;background:#ffffff;border-radius:12px;padding:28px;">
    <p style="margin:0 0 16px;font-size:18px;font-weight:bold;">BrandMate support</p>
    @if ($direction === 'to_support')
      <p style="margin:0 0 12px;"><strong>{{ $from }}</strong> wrote on ticket #{{ $ticketId }}: <strong>{{ $ticketSubject }}</strong></p>
    @else
      <p style="margin:0 0 12px;">Our team replied to your ticket #{{ $ticketId }}: <strong>{{ $ticketSubject }}</strong></p>
    @endif
    <p style="margin:0 0 20px;padding:12px 14px;background:#f4f5f7;border-radius:8px;white-space:pre-wrap;">{{ $body }}</p>
    <p style="margin:0;">
      <a href="{{ $url }}" style="display:inline-block;background:#4f46e5;color:#ffffff;text-decoration:none;padding:12px 20px;border-radius:8px;font-weight:bold;">Open ticket</a>
    </p>
  </div>
</body>
</html>
