import { Component, inject, signal } from '@angular/core';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { applyServerErrors, messageFrom, passwordsMatch } from '../../core/forms';
import { Api } from '../../core/services/api';
import { FieldError } from '../../shared/components/field-error';
import { AuthShell } from './auth-shell';
import { TPipe } from '../../core/i18n/t.pipe';

@Component({
  selector: 'app-reset-password',
  imports: [TPipe, ReactiveFormsModule, RouterLink, AuthShell, FieldError],
  template: `
    <app-auth-shell [heading]="'Choose a new password' | t">
      @if (!token || !email) {
        <p class="alert alert--error" role="alert">
          {{ 'This reset link is incomplete. Open the link from your email again, or request a new one.' | t }}
        </p>
        <p class="auth__switch"><a routerLink="/forgot-password">{{ 'Request a new link' | t }}</a></p>
      } @else if (done()) {
        <p class="alert alert--success" role="status">{{ 'Password updated. You have been signed out on all devices.' | t }}</p>
        <a class="btn btn--primary btn--block" routerLink="/login">{{ 'Sign in' | t }}</a>
      } @else {
        @if (error()) {
          <p class="alert alert--error" role="alert">{{ error() | t }}</p>
        }
        <form [formGroup]="form" (ngSubmit)="submit()" novalidate>
          <div class="field">
            <label for="password">{{ 'New password' | t }}</label>
            <input id="password" type="password" formControlName="password" autocomplete="new-password" aria-describedby="password-hint" />
            <p class="field-hint" id="password-hint">{{ 'At least 8 characters, with a letter and a number.' | t }}</p>
            <app-field-error [control]="form.controls.password" />
          </div>
          <div class="field">
            <label for="password2">{{ 'Repeat new password' | t }}</label>
            <input id="password2" type="password" formControlName="password_confirmation" autocomplete="new-password" />
            <app-field-error [control]="form.controls.password_confirmation" />
          </div>
          <button class="btn btn--primary btn--block" type="submit" [disabled]="busy()">
            {{ (busy() ? 'Saving…' : 'Save new password') | t }}
          </button>
        </form>
      }
    </app-auth-shell>
  `,
})
export class ResetPassword {
  private readonly api = inject(Api);
  private readonly params = inject(ActivatedRoute).snapshot.queryParamMap;

  protected readonly token = this.params.get('token');
  protected readonly email = this.params.get('email');
  protected readonly busy = signal(false);
  protected readonly done = signal(false);
  protected readonly error = signal('');

  protected readonly form = inject(NonNullableFormBuilder).group(
    {
      password: ['', [Validators.required, Validators.minLength(8)]],
      password_confirmation: ['', Validators.required],
    },
    { validators: passwordsMatch },
  );

  protected submit(): void {
    if (this.form.invalid || !this.token || !this.email) {
      this.form.markAllAsTouched();
      return;
    }
    this.busy.set(true);
    this.error.set('');
    this.api.resetPassword({ token: this.token, email: this.email, ...this.form.getRawValue() }).subscribe({
      next: () => this.done.set(true),
      error: (err: unknown) => {
        if (!applyServerErrors(this.form, err)) {
          this.error.set(messageFrom(err, 'This reset link is invalid or has expired.'));
        }
        this.busy.set(false);
      },
    });
  }
}
