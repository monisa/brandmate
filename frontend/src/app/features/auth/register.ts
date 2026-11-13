import { Component, inject, signal } from '@angular/core';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { AuthService } from '../../core/auth/auth.service';
import { applyServerErrors, messageFrom, passwordsMatch } from '../../core/forms';
import { FieldError } from '../../shared/components/field-error';
import { AuthShell } from './auth-shell';
import { TPipe } from '../../core/i18n/t.pipe';

@Component({
  selector: 'app-register',
  imports: [TPipe, ReactiveFormsModule, RouterLink, AuthShell, FieldError],
  template: `
    <app-auth-shell [heading]="'Create a brand account' | t" [intro]="'For brands and agencies looking for creators. You become the admin of a new workspace and can invite your team next.' | t">
      @if (error()) {
        <p class="alert alert--error" role="alert">{{ error() | t }}</p>
      }
      <form [formGroup]="form" (ngSubmit)="submit()" novalidate>
        <div class="field">
          <label for="name">{{ 'Your name' | t }}</label>
          <input id="name" type="text" formControlName="name" autocomplete="name" />
          <app-field-error [control]="form.controls.name" />
        </div>
        <div class="field">
          <label for="company">{{ 'Brand or agency name' | t }}</label>
          <input id="company" type="text" formControlName="company_name" autocomplete="organization" />
          <app-field-error [control]="form.controls.company_name" />
        </div>
        <div class="field">
          <label for="email">{{ 'Work email' | t }}</label>
          <input id="email" type="email" formControlName="email" autocomplete="email" />
          <app-field-error [control]="form.controls.email" />
        </div>
        <div class="field">
          <label for="password">{{ 'Password' | t }}</label>
          <div class="password-input">
            <input id="password" [type]="passwordVisible() ? 'text' : 'password'" formControlName="password" autocomplete="new-password" aria-describedby="password-hint" />
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
          <p class="field-hint" id="password-hint">{{ 'At least 8 characters, with a letter and a number.' | t }}</p>
          <app-field-error [control]="form.controls.password" />
        </div>
        <div class="field">
          <label for="password2">{{ 'Repeat password' | t }}</label>
          <div class="password-input">
            <input id="password2" [type]="confirmationVisible() ? 'text' : 'password'" formControlName="password_confirmation" autocomplete="new-password" />
            <button class="password-toggle" type="button" [attr.aria-label]="(confirmationVisible() ? 'Hide repeated password' : 'Show repeated password') | t" [attr.title]="(confirmationVisible() ? 'Hide repeated password' : 'Show repeated password') | t" [attr.aria-pressed]="confirmationVisible()" (click)="confirmationVisible.update((visible) => !visible)">
              <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <path d="M2.25 12s3.5-6.5 9.75-6.5 9.75 6.5 9.75 6.5-3.5 6.5-9.75 6.5S2.25 12 2.25 12Z" />
                <circle cx="12" cy="12" r="3" />
                @if (confirmationVisible()) {
                  <path d="m3 3 18 18" />
                }
              </svg>
            </button>
          </div>
          <app-field-error [control]="form.controls.password_confirmation" />
        </div>
        <button class="btn btn--primary btn--block" type="submit" [disabled]="busy()">
          {{ (busy() ? 'Creating account…' : 'Create account') | t }}
        </button>
      </form>
      <p class="auth__switch">{{ 'Are you a creator?' | t }} <a routerLink="/register/creator">{{ 'Join as a creator' | t }}</a></p>
      <p class="auth__switch auth__switch--tight">{{ 'Already registered?' | t }} <a routerLink="/login">{{ 'Sign in' | t }}</a></p>
    </app-auth-shell>
  `,
})
export class Register {
  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);

  protected readonly busy = signal(false);
  protected readonly error = signal('');
  protected readonly passwordVisible = signal(false);
  protected readonly confirmationVisible = signal(false);

  protected readonly form = inject(NonNullableFormBuilder).group(
    {
      name: ['', Validators.required],
      company_name: ['', Validators.required],
      email: ['', [Validators.required, Validators.email]],
      password: ['', [Validators.required, Validators.minLength(8)]],
      password_confirmation: ['', Validators.required],
    },
    { validators: passwordsMatch },
  );

  protected submit(): void {
    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }
    this.busy.set(true);
    this.error.set('');

    this.auth.register(this.form.getRawValue()).subscribe({
      next: () => void this.router.navigate(['/verify-email']),
      error: (err: unknown) => {
        if (!applyServerErrors(this.form, err)) {
          this.error.set(messageFrom(err, 'The account could not be created. Try again.'));
        }
        this.busy.set(false);
      },
    });
  }
}
