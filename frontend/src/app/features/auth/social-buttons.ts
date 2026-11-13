import { Component, OnInit, inject, signal } from '@angular/core';
import { AuthService } from '../../core/auth/auth.service';
import { TPipe } from '../../core/i18n/t.pipe';

/**
 * "Continue with Google / Facebook". Plain links: the browser goes to the provider and back.
 * A button only appears once the server has credentials for that provider, so nobody
 * clicks one that cannot work. With none configured, nothing is drawn at all.
 */
@Component({
  selector: 'app-social-buttons',
  imports: [TPipe],
  template: `
    @if (enabled().google || enabled().facebook) {
      <div class="divider"><span>{{ 'or' | t }}</span></div>
      <div class="social">
        @if (enabled().google) {
          <a class="btn btn--block social__btn" [href]="google">
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
              <path fill="#4285F4" d="M23.5 12.3c0-.8-.1-1.6-.2-2.3H12v4.4h6.5a5.6 5.6 0 0 1-2.4 3.6v3h3.9c2.3-2.1 3.5-5.2 3.5-8.7z" />
              <path fill="#34A853" d="M12 24c3.2 0 6-1.1 8-2.9l-3.9-3c-1.1.7-2.5 1.2-4.1 1.2-3.1 0-5.8-2.1-6.7-5H1.3v3.1A12 12 0 0 0 12 24z" />
              <path fill="#FBBC05" d="M5.3 14.3a7.2 7.2 0 0 1 0-4.6V6.6h-4a12 12 0 0 0 0 10.8z" />
              <path fill="#EA4335" d="M12 4.8c1.8 0 3.3.6 4.6 1.8l3.4-3.4A12 12 0 0 0 1.3 6.6l4 3.1c.9-2.9 3.6-4.9 6.7-4.9z" />
            </svg>
            {{ 'Continue with Google' | t }}
          </a>
        }
        @if (enabled().facebook) {
          <a class="btn btn--block social__btn" [href]="facebook">
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
              <circle cx="12" cy="12" r="12" fill="#1877F2" />
              <path fill="#fff" d="M13.4 19.5v-6.1h2.1l.3-2.5h-2.4V9.3c0-.7.2-1.2 1.2-1.2H16V5.9c-.2 0-1-.1-1.9-.1-1.9 0-3.1 1.1-3.1 3.2v1.9H8.9v2.5H11v6.1z" />
            </svg>
            {{ 'Continue with Facebook' | t }}
          </a>
        }
      </div>
    }
  `,
})
export class SocialButtons implements OnInit {
  private readonly auth = inject(AuthService);
  protected readonly google = this.auth.oauthUrl('google');
  protected readonly facebook = this.auth.oauthUrl('facebook');
  protected readonly enabled = signal({ google: false, facebook: false });

  ngOnInit(): void {
    this.auth.oauthProviders().subscribe((enabled) => this.enabled.set(enabled));
  }
}
