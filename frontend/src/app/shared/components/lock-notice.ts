import { Component, input } from '@angular/core';
import { RouterLink } from '@angular/router';
import { TPipe } from '../../core/i18n/t.pipe';

/** "Subscribe to unlock": shown wherever contact details are hidden or a profile cannot be opened. */
@Component({
  selector: 'app-lock-notice',
  imports: [TPipe, RouterLink],
  template: `
    <section class="lock-notice" role="note">
      <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="11" width="14" height="9" rx="2" /><path d="M8 11V8a4 4 0 0 1 8 0v3" /></svg>
      <div>
        <strong>{{ 'Contact details are locked' | t }}</strong>
        <p>
          {{
            (audience() === 'brand'
              ? 'Subscribe to see creators’ phone numbers, e-mail addresses and channel names, open full profiles and chat with them.'
              : 'Subscribe to see brands’ phone numbers, e-mail addresses and channel names, open full profiles and chat with them.') | t
          }}
        </p>
      </div>
      <a class="btn btn--primary" routerLink="/billing">{{ 'Subscribe' | t }} · ₹999 + GST {{ 'per month' | t }}</a>
    </section>
  `,
})
export class LockNotice {
  readonly audience = input<'brand' | 'creator'>('brand');
}
