import { Component, OnInit, inject, signal } from '@angular/core';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { AuthService } from '../../core/auth/auth.service';
import { applyServerErrors, messageFrom, passwordsMatch } from '../../core/forms';
import { InvitationPreview } from '../../core/models';
import { Api } from '../../core/services/api';
import { FieldError } from '../../shared/components/field-error';
import { AuthShell } from './auth-shell';
import { TPipe } from '../../core/i18n/t.pipe';

@Component({
  selector: 'app-accept-invite',
  imports: [TPipe, ReactiveFormsModule, RouterLink, AuthShell, FieldError],
  template: `
    <app-auth-shell [heading]="'Join your team' | t">
      @if (loading()) {
        <p class="muted" role="status">{{ 'Checking your invitation…' | t }}</p>
      } @else if (!invitation()) {
        <p class="alert alert--error" role="alert">
          {{ 'This invitation is no longer valid. It may have expired, been revoked or already been used. Ask your company admin to invite you again.' | t }}
        </p>
        <p class="auth__switch"><a routerLink="/login">{{ 'Go to sign in' | t }}</a></p>
      } @else {
        <p>
          {{ 'You are joining {company} as {role}, using {email}.' | t: {
            company: invitation()!.company, role: (invitation()!.role | t), email: invitation()!.email } }}
        </p>
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
            <label for="password">{{ 'Password' | t }}</label>
            <input id="password" type="password" formControlName="password" autocomplete="new-password" aria-describedby="password-hint" />
            <p class="field-hint" id="password-hint">{{ 'At least 8 characters, with a letter and a number.' | t }}</p>
            <app-field-error [control]="form.controls.password" />
          </div>
          <div class="field">
            <label for="password2">{{ 'Repeat password' | t }}</label>
            <input id="password2" type="password" formControlName="password_confirmation" autocomplete="new-password" />
            <app-field-error [control]="form.controls.password_confirmation" />
          </div>
          <button class="btn btn--primary btn--block" type="submit" [disabled]="busy()">
            {{ (busy() ? 'Joining…' : 'Join team') | t }}
          </button>
        </form>
      }
    </app-auth-shell>
  `,
})
export class AcceptInvite implements OnInit {
  private readonly api = inject(Api);
  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);
  private readonly token = inject(ActivatedRoute).snapshot.queryParamMap.get('token');

  protected readonly loading = signal(true);
  protected readonly invitation = signal<InvitationPreview | null>(null);
  protected readonly busy = signal(false);
  protected readonly error = signal('');

  protected readonly form = inject(NonNullableFormBuilder).group(
    {
      name: ['', Validators.required],
      password: ['', [Validators.required, Validators.minLength(8)]],
      password_confirmation: ['', Validators.required],
    },
    { validators: passwordsMatch },
  );

  ngOnInit(): void {
    if (!this.token) {
      this.loading.set(false);
      return;
    }
    this.api.invitationPreview(this.token).subscribe({
      next: (invitation) => {
        this.invitation.set(invitation);
        this.loading.set(false);
      },
      error: () => this.loading.set(false),
    });
  }

  protected submit(): void {
    if (this.form.invalid || !this.token) {
      this.form.markAllAsTouched();
      return;
    }
    this.busy.set(true);
    this.error.set('');
    // Joining replaces any session already open in this browser.
    this.auth.clear();
    this.auth.acceptInvitation(this.token, this.form.getRawValue()).subscribe({
      next: () => void this.router.navigate(['/']),
      error: (err: unknown) => {
        if (!applyServerErrors(this.form, err)) {
          this.error.set(messageFrom(err, 'You could not be added to the team. Try again.'));
        }
        this.busy.set(false);
      },
    });
  }
}
