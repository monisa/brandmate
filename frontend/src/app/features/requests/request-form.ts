import { CurrencyPipe } from '@angular/common';
import { Component, inject, input, signal } from '@angular/core';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { applyServerErrors, messageFrom } from '../../core/forms';
import { CreatorPackage, BrandRequest } from '../../core/models';
import { Api } from '../../core/services/api';
import { TPipe } from '../../core/i18n/t.pipe';
import { FieldError } from '../../shared/components/field-error';

/** "Send a request" on a creator's profile: what the brand wants, optionally tied to one of their packages. */
@Component({
  selector: 'app-request-form',
  imports: [TPipe, ReactiveFormsModule, RouterLink, CurrencyPipe, FieldError],
  template: `
    <section class="card" id="request">
      <h2>{{ 'Send a request' | t }}</h2>

      @if (sent(); as request) {
        <p class="alert alert--success" role="status">
          {{ 'Your request was sent to {name}. They will see it in their inbox and answer here.' | t: { name: creatorName() } }}
          <a routerLink="/requests">{{ 'View your requests' | t }}</a>
        </p>
        <button class="btn" type="button" (click)="another()">{{ 'Send another request' | t }}</button>
      } @else {
        <p class="muted">{{ 'Tell {name} what you have in mind. They can accept or decline, and contact details are shared once they accept.' | t: { name: creatorName() } }}</p>
        @if (error()) {
          <p class="alert alert--error" role="alert">{{ error() | t }}</p>
        }
        <form [formGroup]="form" (ngSubmit)="submit()" novalidate class="request-form">
          <div class="field">
            <label for="rq-title">{{ 'Title' | t }}</label>
            <input id="rq-title" type="text" formControlName="title" maxlength="160" [placeholder]="'For example: Festive reel for our new range' | t" />
            <app-field-error [control]="form.controls.title" />
          </div>
          <div class="field">
            <label for="rq-message">{{ 'Message' | t }}</label>
            <textarea id="rq-message" rows="4" formControlName="message" maxlength="2000"
                      [placeholder]="'What you need, where it will be shared and anything they should know.' | t"></textarea>
            <app-field-error [control]="form.controls.message" />
          </div>
          <div class="request-form__row">
            @if (packages().length) {
              <div class="field">
                <label for="rq-package">{{ 'Package (optional)' | t }}</label>
                <select id="rq-package" formControlName="package_id" (change)="packageChosen()">
                  <option value="">{{ 'No package, a custom request' | t }}</option>
                  @for (pack of packages(); track pack.id) {
                    <option [value]="pack.id">{{ pack.title }} · {{ pack.price | currency: pack.currency : 'symbol' : '1.0-0' }}</option>
                  }
                </select>
              </div>
            }
            <div class="field">
              <label for="rq-budget">{{ 'Budget (optional)' | t }}</label>
              <input id="rq-budget" type="number" min="0" step="1" formControlName="budget" inputmode="numeric" />
              <app-field-error [control]="form.controls.budget" />
            </div>
            <div class="field">
              <label for="rq-due">{{ 'Needed by (optional)' | t }}</label>
              <input id="rq-due" type="date" formControlName="due_date" [min]="today" />
              <app-field-error [control]="form.controls.due_date" />
            </div>
          </div>
          <button class="btn btn--primary" type="submit" [disabled]="busy()">{{ (busy() ? 'Sending…' : 'Send request') | t }}</button>
        </form>
      }
    </section>
  `,
})
export class RequestForm {
  private readonly api = inject(Api);

  /** The creator (not the social account) the request is for. */
  readonly creatorId = input.required<number>();
  readonly creatorName = input.required<string>();
  readonly packages = input<CreatorPackage[]>([]);

  protected readonly today = new Date().toISOString().slice(0, 10);
  protected readonly busy = signal(false);
  protected readonly error = signal('');
  protected readonly sent = signal<BrandRequest | null>(null);

  protected readonly form = inject(NonNullableFormBuilder).group({
    title: ['', [Validators.required, Validators.maxLength(160)]],
    message: ['', [Validators.required, Validators.maxLength(2000)]],
    package_id: [''],
    budget: [null as number | null, [Validators.min(0)]],
    due_date: [''],
  });

  /** Picking a package suggests its price as the budget, unless a budget was already typed. */
  protected packageChosen(): void {
    const pack = this.packages().find((p) => String(p.id) === this.form.controls.package_id.value);
    if (pack && this.form.controls.budget.value === null) {
      this.form.controls.budget.setValue(pack.price);
    }
  }

  protected another(): void {
    this.sent.set(null);
    this.form.reset({ title: '', message: '', package_id: '', budget: null, due_date: '' });
  }

  protected submit(): void {
    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }
    const v = this.form.getRawValue();
    this.busy.set(true);
    this.error.set('');

    this.api
      .sendRequest({
        creator_id: this.creatorId(),
        package_id: v.package_id ? Number(v.package_id) : null,
        title: v.title.trim(),
        message: v.message.trim(),
        budget: v.budget === null || (v.budget as unknown) === '' ? null : Math.round(Number(v.budget)),
        due_date: v.due_date || null,
      })
      .subscribe({
        next: (request) => {
          this.sent.set(request);
          this.busy.set(false);
        },
        error: (err: unknown) => {
          if (!applyServerErrors(this.form, err)) {
            this.error.set(messageFrom(err, 'The request could not be sent.'));
          }
          this.busy.set(false);
        },
      });
  }
}
