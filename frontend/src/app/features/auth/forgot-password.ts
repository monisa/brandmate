import { Component, inject, signal } from '@angular/core';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { messageFrom } from '../../core/forms';
import { Api } from '../../core/services/api';
import { FieldError } from '../../shared/components/field-error';
import { AuthShell } from './auth-shell';
import { TPipe } from '../../core/i18n/t.pipe';

@Component({
  selector: 'app-forgot-password',
  imports: [TPipe, ReactiveFormsModule, RouterLink, AuthShell, FieldError],
  template: `
    <app-auth-shell [heading]="'Reset your password' | t" [intro]="'Enter your email and we will send you a link to choose a new password.' | t">
      @if (sent()) {
        <p class="alert alert--success" role="status">
          {{ 'If that email is registered, a reset link is on its way. The link works for 60 minutes.' | t }}
        </p>
      } @else {
        @if (error()) {
          <p class="alert alert--error" role="alert">{{ error() | t }}</p>
        }
        <form [formGroup]="form" (ngSubmit)="submit()" novalidate>
          <div class="field">
            <label for="email">{{ 'Work email' | t }}</label>
            <input id="email" type="email" formControlName="email" autocomplete="email" />
            <app-field-error [control]="form.controls.email" />
          </div>
          <button class="btn btn--primary btn--block" type="submit" [disabled]="busy()">
            {{ (busy() ? 'Sending…' : 'Send reset link') | t }}
          </button>
        </form>
      }
      <p class="auth__switch"><a routerLink="/login">{{ 'Back to sign in' | t }}</a></p>
    </app-auth-shell>
  `,
})
export class ForgotPassword {
  private readonly api = inject(Api);

  protected readonly busy = signal(false);
  protected readonly sent = signal(false);
  protected readonly error = signal('');
  protected readonly form = inject(NonNullableFormBuilder).group({
    email: ['', [Validators.required, Validators.email]],
  });

  protected submit(): void {
    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }
    this.busy.set(true);
    this.error.set('');
    this.api.forgotPassword(this.form.getRawValue().email).subscribe({
      next: () => this.sent.set(true),
      error: (err: unknown) => {
        this.error.set(messageFrom(err));
        this.busy.set(false);
      },
    });
  }
}
