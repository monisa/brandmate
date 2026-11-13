import { DatePipe } from '@angular/common';
import { Component, OnInit, inject, signal } from '@angular/core';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { applyServerErrors, messageFrom, passwordsMatch } from '../../core/forms';
import { DeviceSession } from '../../core/models';
import { Api } from '../../core/services/api';
import { FieldError } from '../../shared/components/field-error';
import { TPipe } from '../../core/i18n/t.pipe';

@Component({
  selector: 'app-security',
  imports: [TPipe, ReactiveFormsModule, DatePipe, FieldError],
  template: `
    <section class="card card--form">
      <h2>{{ 'Change password' | t }}</h2>
      @if (notice()) {
        <p class="alert alert--success" role="status">{{ notice() | t }}</p>
      }
      @if (error()) {
        <p class="alert alert--error" role="alert">{{ error() | t }}</p>
      }
      <form [formGroup]="form" (ngSubmit)="save()" novalidate>
        <div class="field">
          <label for="current">{{ 'Current password' | t }}</label>
          <input id="current" type="password" formControlName="current_password" autocomplete="current-password" />
          <app-field-error [control]="form.controls.current_password" />
        </div>
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
        <button class="btn btn--primary" type="submit" [disabled]="busy()">{{ (busy() ? 'Saving…' : 'Change password') | t }}</button>
      </form>
    </section>

    <section class="card">
      <h2>{{ 'Signed-in devices' | t }}</h2>
      @if (sessions().length === 0) {
        <p class="muted">{{ 'No devices to show.' | t }}</p>
      } @else {
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th scope="col">{{ 'Device' | t }}</th>
                <th scope="col">{{ 'Last used' | t }}</th>
                <th scope="col"><span class="visually-hidden">{{ 'Actions' | t }}</span></th>
              </tr>
            </thead>
            <tbody>
              @for (session of sessions(); track session.id) {
                <tr>
                  <td class="truncate">
                    {{ session.device }}
                    @if (session.current) {
                      <span class="badge badge--ok">{{ 'This device' | t }}</span>
                    }
                  </td>
                  <td>{{ session.last_used_at ? (session.last_used_at | date: 'medium') : ('Not used yet' | t) }}</td>
                  <td class="actions">
                    @if (!session.current) {
                      <button class="btn btn--quiet" type="button" (click)="revoke(session)">{{ 'Sign out' | t }}</button>
                    }
                  </td>
                </tr>
              }
            </tbody>
          </table>
        </div>
      }
    </section>
  `,
})
export class Security implements OnInit {
  private readonly api = inject(Api);

  protected readonly busy = signal(false);
  protected readonly notice = signal('');
  protected readonly error = signal('');
  protected readonly sessions = signal<DeviceSession[]>([]);

  protected readonly form = inject(NonNullableFormBuilder).group(
    {
      current_password: ['', Validators.required],
      password: ['', [Validators.required, Validators.minLength(8)]],
      password_confirmation: ['', Validators.required],
    },
    { validators: passwordsMatch },
  );

  ngOnInit(): void {
    this.loadSessions();
  }

  protected save(): void {
    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }
    this.busy.set(true);
    this.notice.set('');
    this.error.set('');
    this.api.changePassword(this.form.getRawValue()).subscribe({
      next: () => {
        this.form.reset();
        this.notice.set('Password changed. Other devices have been signed out.');
        this.busy.set(false);
        this.loadSessions();
      },
      error: (err: unknown) => {
        if (!applyServerErrors(this.form, err)) {
          this.error.set(messageFrom(err, 'The password could not be changed.'));
        }
        this.busy.set(false);
      },
    });
  }

  protected revoke(session: DeviceSession): void {
    this.api.revokeSession(session.id).subscribe({
      next: () => this.sessions.update((list) => list.filter((s) => s.id !== session.id)),
      error: (err: unknown) => this.error.set(messageFrom(err, 'That device could not be signed out.')),
    });
  }

  private loadSessions(): void {
    this.api.sessions().subscribe({
      next: (sessions) => this.sessions.set(sessions),
      error: () => this.sessions.set([]),
    });
  }
}
