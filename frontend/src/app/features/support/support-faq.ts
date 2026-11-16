/**
 * The help questions and answers. It is a plain FAQ, not a live chat: people search or tap a
 * question and read the answer; if that does not settle it they raise a support ticket.
 * Every question and answer is English text that goes through the translation pipe, so it follows
 * the chosen language once translations are added to locales/*.ts.
 *
 * `words` are extra search terms, so "payment failed" also finds the answers about paying.
 */
export interface Faq {
  id: string;
  question: string;
  answer: string;
  words: string[];
  audience: 'all' | 'brand' | 'creator';
  link?: { label: string; route: string };
}

export const FAQS: Faq[] = [
  {
    id: 'subscription',
    question: 'How much does it cost and what do I get?',
    answer:
      'A subscription is ₹999 + 18% GST (₹1,178.82) for one month. It unlocks phone numbers, e-mail addresses, channel names, full profiles and chat. There are no automatic charges: you only pay when you choose to. The Subscription page shows whether payment is needed right now.',
    words: ['price', 'cost', 'fee', 'subscription', 'subscribe', 'plan', 'charge', 'rupee', 'gst', '999', 'unlock', 'free'],
    audience: 'all',
    link: { label: 'Open Subscription', route: '/billing' },
  },
  {
    id: 'pay',
    question: 'How do I pay?',
    answer:
      'Open the Subscription page, press the pay button and complete the payment with UPI, card or net banking. Access is added as soon as the payment is confirmed. Paying again before the month ends adds a month to the end of the current one.',
    words: ['pay', 'payment', 'upi', 'card', 'netbanking', 'checkout', 'renew', 'razorpay', 'buy'],
    audience: 'all',
    link: { label: 'Open Subscription', route: '/billing' },
  },
  {
    id: 'paid-not-unlocked',
    question: 'I paid but nothing unlocked',
    answer:
      'Wait a minute and refresh the page: confirmation can take a moment. If it is still locked, e-mail us the e-mail you signed up with and the payment id or a screenshot of the payment, and we will unlock it by hand.',
    words: ['paid', 'unlocked', 'locked', 'not working', 'deducted', 'money', 'failed', 'refund', 'stuck', 'still'],
    audience: 'all',
  },
  {
    id: 'blurred',
    question: 'Why do I see XXXxx instead of details?',
    answer:
      'Phone numbers, e-mail addresses, channel names and descriptions are hidden until the account is subscribed. Subscribe to see them and to open full profiles.',
    words: ['xxx', 'xxxxx', 'blur', 'blurred', 'hidden', 'masked', 'see', 'contact', 'phone', 'number', 'email', 'details', 'view profile', 'disabled'],
    audience: 'all',
    link: { label: 'Open Subscription', route: '/billing' },
  },
  {
    id: 'approval',
    question: 'Why is my account waiting for approval?',
    answer:
      'New brands and creator profiles are reviewed by the BrandMate team before they go live. You will be notified when it is approved. Until then you can complete your profile or company details.',
    words: ['approval', 'approve', 'approved', 'pending', 'review', 'waiting', 'verify account', 'live', 'visible'],
    audience: 'all',
  },
  {
    id: 'verify-email',
    question: 'I did not get the verification e-mail',
    answer:
      'Check your spam folder, then use the resend option shown after you sign in. The link works for 60 minutes. Make sure the e-mail address you registered with is spelled correctly.',
    words: ['verification', 'verify', 'verification email', 'link', 'confirm', 'mail', 'email not', 'resend', 'spam'],
    audience: 'all',
  },
  {
    id: 'password',
    question: 'I forgot my password',
    answer:
      'On the sign-in page choose “Forgot password?”, enter your e-mail and follow the link we send you. To change it while signed in, open Settings. Changing it signs you out of your other devices.',
    words: ['password', 'forgot', 'reset', 'login', 'sign in', 'signin', 'cannot login', "can't login", 'locked out', 'change password'],
    audience: 'all',
    link: { label: 'Open Settings', route: '/settings' },
  },
  {
    id: 'find-creators',
    question: 'How do I find and compare creators?',
    answer:
      'Open Influencers, use the filters (platform, category, country, followers) and the grid or table view. Tick creators to compare them side by side, or add them to a shortlist to keep them for later.',
    words: ['find', 'search', 'discover', 'filter', 'influencer', 'creator', 'compare', 'shortlist', 'list'],
    audience: 'brand',
    link: { label: 'Open Influencers', route: '/influencers' },
  },
  {
    id: 'send-request',
    question: 'How do I send a collaboration request?',
    answer:
      'Open a creator’s profile and send a request, choosing one of their packages if they have any. You can see and withdraw your requests on the Requests page. Creators reply there or in chat.',
    words: ['request', 'collaboration', 'collab', 'hire', 'package', 'send', 'proposal', 'work with'],
    audience: 'brand',
    link: { label: 'Open Requests', route: '/requests' },
  },
  {
    id: 'team',
    question: 'How do I add my team?',
    answer:
      'Company admins can invite teammates from the Team page and choose their role. The invitation link is e-mailed and works for 7 days. One subscription covers the whole team.',
    words: ['team', 'invite', 'member', 'teammate', 'colleague', 'role', 'admin', 'add user', 'staff'],
    audience: 'brand',
    link: { label: 'Open Team', route: '/team' },
  },
  {
    id: 'creator-profile',
    question: 'How do I get listed and noticed as a creator?',
    answer:
      'Open My creator profile, fill in your details, add your social accounts (links on Instagram, YouTube or Facebook) and packages, then turn on your listing. Once the team approves it, brands can find you.',
    words: ['listed', 'listing', 'profile', 'visible', 'hide', 'accounts', 'social', 'instagram', 'youtube', 'facebook', 'package', 'get noticed', 'brands find'],
    audience: 'creator',
    link: { label: 'Open My creator profile', route: '/creator/profile' },
  },
  {
    id: 'requests-creator',
    question: 'How do I answer a brand’s request?',
    answer:
      'Open Requests to see what brands have sent. You can accept or decline each one. You will also get a notification when a new request arrives.',
    words: ['request', 'brand', 'accept', 'decline', 'reject', 'respond', 'reply', 'offer'],
    audience: 'creator',
    link: { label: 'Open Requests', route: '/creator/requests' },
  },
  {
    id: 'chat',
    question: 'How does chat work?',
    answer:
      'Chat is between a brand and a creator, and both need an approved profile (and a subscription when payment is required). Open Messages, pick a conversation or start one from a profile. You can edit or delete your own messages with the options on the message.',
    words: ['chat', 'message', 'messages', 'talk', 'conversation', 'edit', 'delete', 'send message', 'unread'],
    audience: 'all',
    link: { label: 'Open Messages', route: '/messages' },
  },
  {
    id: 'notifications',
    question: 'How do I get message notifications?',
    answer:
      'On the Messages page, allow notifications when your browser asks. You will then get a pop-up alert for new messages, even when BrandMate is closed. If you blocked it, allow notifications for this site in your browser settings.',
    words: ['notification', 'notifications', 'alert', 'push', 'notify', 'flash', 'desktop', 'browser'],
    audience: 'all',
    link: { label: 'Open Messages', route: '/messages' },
  },
  {
    id: 'language',
    question: 'Can I use BrandMate in my language?',
    answer:
      'Yes. Use the language menu on the sign-in page or in the app to switch between English, हिन्दी, தமிழ் and മലയാളം. Your choice is remembered on this device.',
    words: ['language', 'hindi', 'tamil', 'malayalam', 'english', 'translate', 'translation', 'bhasha'],
    audience: 'all',
  },
  {
    id: 'install',
    question: 'Can I install BrandMate on my phone?',
    answer:
      'Yes. Open BrandMate in Chrome (Android) or Safari (iPhone), then choose “Install app” or “Add to Home Screen” from the browser menu. It then opens like an app.',
    words: ['install', 'app', 'phone', 'mobile', 'android', 'iphone', 'home screen', 'download', 'pwa'],
    audience: 'all',
  },
  {
    id: 'delete-account',
    question: 'How do I close my account or change my details?',
    answer:
      'You can change your name in Settings and your company or creator details in their pages. To close your account or remove your data, e-mail us from your registered address and we will help.',
    words: ['delete', 'close', 'remove', 'account', 'deactivate', 'cancel', 'data', 'privacy', 'name', 'details'],
    audience: 'all',
    link: { label: 'Open Settings', route: '/settings' },
  },
];
