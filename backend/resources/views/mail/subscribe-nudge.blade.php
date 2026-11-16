<!doctype html>
<html lang="en">
<body style="margin:0;padding:24px;background:#f4f5f7;font-family:Arial,Helvetica,sans-serif;color:#1b1f27;">
  <div style="max-width:520px;margin:0 auto;background:#ffffff;border-radius:12px;padding:28px;">
    <p style="margin:0 0 16px;font-size:18px;font-weight:bold;">BrandMate</p>
    <p style="margin:0 0 12px;">Hello {{ $name }},</p>
    <p style="margin:0 0 12px;">
      Thank you for joining BrandMate. Your account is ready, but it is not subscribed yet, so phone numbers,
      e-mail addresses, channel names, full profiles and chat are still hidden.
    </p>
    <p style="margin:0 0 20px;">
      One payment of ₹999 + 18% GST (₹1,178.82) unlocks everything for a month{{ $forCompany ? ' for your whole team' : '' }}.
    </p>
    <p style="margin:0 0 24px;">
      <a href="{{ $payUrl }}" style="display:inline-block;background:#4f46e5;color:#ffffff;text-decoration:none;padding:12px 20px;border-radius:8px;font-weight:bold;">Unlock BrandMate</a>
    </p>
    <p style="margin:0;font-size:13px;color:#6b7280;">There are no automatic charges: you only pay when you choose to.</p>
  </div>
</body>
</html>
