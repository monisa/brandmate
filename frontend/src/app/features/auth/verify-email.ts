import { Component, OnInit, inject, signal } from '@angular/core';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { AuthService } from '../../core/auth/auth.service';
import { messageFrom } from '../../core/forms';
import { Api } from '../../core/services/api';
import { AuthShell } from './auth-shell';
import { TPipe } from '../../core/i18n/t.pipe';

type State = 'checking' | 'verified' | 'failed' | 'waiting' | 'signed-out';

/**
 * Two jobs: (1) the page people land on from the verification e-mail, and
 * (2) the "check your inbox" page shown after registering.
 */
@Component({
  selector: 'app-verify-email',
  imports: [TPipe, RouterLink, AuthShell],
  template: `
    <app-auth-shell [heading]="'Confirm your email' | t">
      @switch (state()) {
        @case ('checking') {
          <p class="muted" role="status">{{ 'Confirming your email…' | t }}</p>
        }
        @case ('verified') {
          <p class="alert alert--success" role="status">{{ 'Email confirmed.' | t }}</p>
          <button class="btn btn--primary btn--block" type="button" (click)="continue()">{{ 'Continue' | t }}</button>
        }
        @case ('failed') {
          <p class="alert alert--error" role="alert">
            {{ 'This confirmation link is invalid or has expired. Links work for 60 minutes.' | t }}
          </p>
          @if (auth.isSignedIn()) {
            <button class="btn btn--primary btn--block" type="button" (click)="resend()" [disabled]="busy()">{{ 'Send a new link' | t }}</button>
          } @else {
            <a class="btn btn--primary btn--block" routerLink="/login">{{ 'Sign in to get a new link' | t }}</a>
          }
        }
        @case ('waiting') {
          <p>
            {{ 'We sent a confirmation link to {email}. Open it to start using your workspace.' | t: { email: auth.user()?.email } }}
          </p>
          @if (notice()) {
            <p class="alert" [class.alert--success]="!noticeIsError()" [class.alert--error]="noticeIsError()" role="status">{{ notice() | t }}</p>
          }
          <div class="stack">
            <button class="btn btn--primary btn--block" type="button" (click)="recheck()" [disabled]="busy()">{{ 'I have confirmed my email' | t }}</button>
            <button class="btn btn--block" type="button" (click)="resend()" [disabled]="busy()">{{ 'Send the link again' | t }}</button>
            <button class="btn btn--quiet btn--block" type="button" (click)="auth.logout()">{{ 'Sign out' | t }}</button>
          </div>
        }
        @case ('signed-out') {
          <p>{{ 'Sign in to confirm your email address.' | t }}</p>
          <a class="btn btn--primary btn--block" routerLink="/login">{{ 'Sign in' | t }}</a>
        }
      }
    </app-auth-shell>
  `,
})
export class VerifyEmail implements OnInit {
  protected readonly auth = inject(AuthService);
  private readonly api = inject(Api);
  private readonly router = inject(Router);
  private readonly params = inject(ActivatedRoute).snapshot.queryParamMap;

  protected readonly state = signal<State>('checking');
  protected readonly busy = signal(false);
  protected readonly notice = signal('');
  protected readonly noticeIsError = signal(false);

  ngOnInit(): void {
    const id = this.params.get('id');
    const hash = this.params.get('hash');
    const expires = this.params.get('expires');
    const signature = this.params.get('signature');

    if (id && hash && expires && signature) {
      this.api.verifyEmail(id, hash, expires, signature).subscribe({
        next: () => this.state.set('verified'),
        error: () => this.state.set('failed'),
      });
      return;
    }

    if (!this.auth.isSignedIn()) {
      this.state.set('signed-out');
    } else if (this.auth.user()?.email_verified) {
      void this.router.navigate(['/']);
    } else {
      this.state.set('waiting');
    }
  }

  protected continue(): void {
    if (!this.auth.isSignedIn()) {
      void this.router.navigate(['/login']);
      return;
    }
    this.auth.refresh().subscribe({
      next: () => void this.router.navigate(['/']),
      error: () => void this.router.navigate(['/login']),
    });
  }

  protected recheck(): void {
    this.busy.set(true);
    this.auth.refresh().subscribe({
      next: (user) => {
        this.busy.set(false);
        if (user.email_verified) {
          void this.router.navigate(['/']);
        } else {
          this.show('Not confirmed yet. Open the link in the email we sent you.', true);
        }
      },
      error: (err: unknown) => {
        this.busy.set(false);
        this.show(messageFrom(err), true);
      },
    });
  }

  protected resend(): void {
    this.busy.set(true);
    this.api.resendVerification().subscribe({
      next: () => {
        this.busy.set(false);
        this.state.set('waiting');
        this.show('A new link is on its way.', false);
      },
      error: (err: unknown) => {
        this.busy.set(false);
        this.state.set('waiting');
        this.show(messageFrom(err), true);
      },
    });
  }

  private show(text: string, isError: boolean): void {
    this.notice.set(text);
    this.noticeIsError.set(isError);
  }
}
