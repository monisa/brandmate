import { Component, inject, signal } from '@angular/core';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { AuthService } from '../../core/auth/auth.service';
import { messageFrom } from '../../core/forms';
import { FieldError } from '../../shared/components/field-error';
import { AuthShell } from './auth-shell';
import { TPipe } from '../../core/i18n/t.pipe';

@Component({
  selector: 'app-login',
  imports: [TPipe, ReactiveFormsModule, RouterLink, AuthShell, FieldError],
  template: `
    <app-auth-shell [heading]="'Sign in' | t">
      @if (expired) {
        <p class="alert alert--info" role="status">{{ 'Your session ended. Sign in again to continue.' | t }}</p>
      }
      @if (error()) {
        <p class="alert alert--error" role="alert">{{ error() | t }}</p>
      }
      <form [formGroup]="form" (ngSubmit)="submit()" novalidate>
        <div class="field">
          <label for="email">{{ 'Work email' | t }}</label>
          <input id="email" type="email" formControlName="email" autocomplete="email" />
          <app-field-error [control]="form.controls.email" />
        </div>
        <div class="field">
          <div class="field__row">
            <label for="password">{{ 'Password' | t }}</label>
            <a routerLink="/forgot-password">{{ 'Forgot password?' | t }}</a>
          </div>
          <div class="password-input">
            <input id="password" [type]="passwordVisible() ? 'text' : 'password'" formControlName="password" autocomplete="current-password" />
            <button class="password-toggle" type="button" [attr.aria-label]="(passwordVisible() ? 'Hide password' : 'Show password') | t" [attr.title]="(passwordVisible() ? 'Hide password' : 'Show password') | t" [attr.aria-pressed]="passwordVisible()" (click)="passwordVisible.update((visible) => !visible)">
              <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <path d="M2.25 12s3.5-6.5 9.75-6.5 9.75 6.5 9.75 6.5-3.5 6.5-9.75 6.5S2.25 12 2.25 12Z" />
                <circle cx="12" cy="12" r="3" />
                @if (passwordVisible()) {
                  <path d="m3 3 18 18" />
                }
              </svg>
            </button>
          </div>
          <app-field-error [control]="form.controls.password" />
        </div>
        <button class="btn btn--primary btn--block" type="submit" [disabled]="busy()">
          {{ (busy() ? 'Signing in…' : 'Sign in') | t }}
        </button>
      </form>
      <p class="auth__switch">
        {{ 'New here?' | t }} <a routerLink="/register">{{ 'Create a brand account' | t }}</a> {{ 'or' | t }} <a routerLink="/register/creator">{{ 'join as a creator' | t }}</a>
      </p>
    </app-auth-shell>
  `,
})
export class Login {
  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);
  private readonly route = inject(ActivatedRoute);

  protected readonly expired = this.route.snapshot.queryParamMap.has('expired');
  protected readonly busy = signal(false);
  protected readonly error = signal('');
  protected readonly passwordVisible = signal(false);

  protected readonly form = inject(NonNullableFormBuilder).group({
    email: ['', [Validators.required, Validators.email]],
    password: ['', Validators.required],
  });

  protected submit(): void {
    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }
    const { email, password } = this.form.getRawValue();
    this.busy.set(true);
    this.error.set('');

    this.auth.login(email, password).subscribe({
      next: () => {
        const returnUrl = this.route.snapshot.queryParamMap.get('returnUrl');
        // Only follow in-app paths, never an absolute URL from the query string.
        void this.router.navigateByUrl(returnUrl?.startsWith('/') && !returnUrl.startsWith('//') ? returnUrl : '/');
      },
      error: (err: unknown) => {
        this.error.set(messageFrom(err, 'Sign-in failed. Try again.'));
        this.busy.set(false);
      },
    });
  }
}
