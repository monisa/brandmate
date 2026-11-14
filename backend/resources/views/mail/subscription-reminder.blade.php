<!doctype html>
<html lang="en">
<body style="margin:0;padding:24px;background:#f4f5f7;font-family:Arial,Helvetica,sans-serif;color:#1b1f27;">
  <div style="max-width:520px;margin:0 auto;background:#ffffff;border-radius:12px;padding:28px;">
    <p style="margin:0 0 16px;font-size:18px;font-weight:bold;">BrandMate</p>
    <p style="margin:0 0 12px;">Hello {{ $name }},</p>

    @if ($kind === 'ending')
      <p style="margin:0 0 12px;">
        Your paid month {{ $forCompany ? 'for your company' : '' }} ends on <strong>{{ $endsOn }}</strong>.
        After that, phone numbers, e-mail addresses, channel names, full profiles and chat are locked again.
      </p>
      <p style="margin:0 0 20px;">Pay for the next month before then to keep everything unlocked. The new month is added to the end of the current one, so nothing is lost.</p>
    @else
      <p style="margin:0 0 12px;">
        Your paid month {{ $forCompany ? 'for your company' : '' }} ended on <strong>{{ $endsOn }}</strong>, so contact details, full profiles and chat are locked.
      </p>
      <p style="margin:0 0 20px;">Pay for a month to unlock them again straight away.</p>
    @endif

    <p style="margin:0 0 24px;">
      <a href="{{ $payUrl }}" style="display:inline-block;background:#4f46e5;color:#ffffff;text-decoration:none;padding:12px 20px;border-radius:8px;font-weight:bold;">Pay for next month</a>
    </p>
    <p style="margin:0;font-size:13px;color:#6b7280;">
      ₹999 + 18% GST a month (₹1,178.82). There are no automatic charges: you only pay when you choose to.
      @if ($forCompany) This subscription covers your whole team. @endif
    </p>
  </div>
</body>
</html>
