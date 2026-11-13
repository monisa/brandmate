import { Component, OnInit, inject, signal } from '@angular/core';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { AuthService } from '../../core/auth/auth.service';
import { messageFrom } from '../../core/forms';
import { TPipe } from '../../core/i18n/t.pipe';
import { AuthShell } from './auth-shell';

/** Where Google / Facebook sign-in lands: swaps the one-time code for a session. */
@Component({
  selector: 'app-oauth-callback',
  imports: [TPipe, RouterLink, AuthShell],
  template: `
    <app-auth-shell [heading]="'Signing you in' | t">
      @if (error()) {
        <p class="alert alert--error" role="alert">{{ error() | t }}</p>
        <a class="btn btn--primary btn--block" routerLink="/login">{{ 'Back to sign in' | t }}</a>
      } @else {
        <p class="muted" role="status">{{ 'One moment…' | t }}</p>
      }
    </app-auth-shell>
  `,
})
export class OAuthCallback implements OnInit {
  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);
  private readonly route = inject(ActivatedRoute);
  protected readonly error = signal('');

  ngOnInit(): void {
    const code = this.route.snapshot.queryParamMap.get('code');
    if (!code) {
      this.error.set('This sign-in link has expired. Please try again.');
      return;
    }
    // Drop the code from the address bar and history right away.
    history.replaceState(null, '', '/oauth/callback');
    this.auth.exchangeOAuthCode(code).subscribe({
      next: () => void this.router.navigateByUrl('/'),
      error: (err: unknown) => this.error.set(messageFrom(err, 'Sign-in failed. Try again.')),
    });
  }
}
