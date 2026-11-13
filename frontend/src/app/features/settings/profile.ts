import { Component, inject, signal } from '@angular/core';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { AuthService } from '../../core/auth/auth.service';
import { applyServerErrors, messageFrom } from '../../core/forms';
import { Api } from '../../core/services/api';
import { FieldError } from '../../shared/components/field-error';
import { TPipe } from '../../core/i18n/t.pipe';

@Component({
  selector: 'app-profile',
  imports: [TPipe, ReactiveFormsModule, FieldError],
  template: `
    <section class="card card--form">
      <h2>{{ 'Profile' | t }}</h2>
      @if (notice()) {
        <p class="alert alert--success" role="status">{{ notice() | t }}</p>
      }
      @if (error()) {
        <p class="alert alert--error" role="alert">{{ error() | t }}</p>
      }
      <form [formGroup]="form" (ngSubmit)="save()" novalidate>
        <div class="field">
          <label for="name">{{ 'Name' | t }}</label>
          <input id="name" type="text" formControlName="name" autocomplete="name" />
          <app-field-error [control]="form.controls.name" />
        </div>
        <div class="field">
          <label for="email">{{ 'Email' | t }}</label>
          <input id="email" type="email" [value]="auth.user()?.email" readonly aria-describedby="email-hint" />
          <p class="field-hint" id="email-hint">{{ 'Your sign-in email cannot be changed here.' | t }}</p>
        </div>
        <button class="btn btn--primary" type="submit" [disabled]="busy()">{{ (busy() ? 'Saving…' : 'Save profile') | t }}</button>
      </form>
    </section>
  `,
})
export class Profile {
  protected readonly auth = inject(AuthService);
  private readonly api = inject(Api);

  protected readonly busy = signal(false);
  protected readonly notice = signal('');
  protected readonly error = signal('');
  protected readonly form = inject(NonNullableFormBuilder).group({
    name: [this.auth.user()?.name ?? '', Validators.required],
  });

  protected save(): void {
    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }
    this.busy.set(true);
    this.notice.set('');
    this.error.set('');
    this.api.updateProfile(this.form.getRawValue().name).subscribe({
      next: (user) => {
        this.auth.setUser(user);
        this.notice.set('Profile saved.');
        this.busy.set(false);
      },
      error: (err: unknown) => {
        if (!applyServerErrors(this.form, err)) {
          this.error.set(messageFrom(err, 'The profile could not be saved.'));
        }
        this.busy.set(false);
      },
    });
  }
}
