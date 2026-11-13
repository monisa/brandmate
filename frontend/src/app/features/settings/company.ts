import { Component, OnInit, inject, signal } from '@angular/core';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { AuthService } from '../../core/auth/auth.service';
import { applyServerErrors, messageFrom } from '../../core/forms';
import { Api } from '../../core/services/api';
import { FieldError } from '../../shared/components/field-error';
import { TPipe } from '../../core/i18n/t.pipe';

@Component({
  selector: 'app-company-settings',
  imports: [TPipe, ReactiveFormsModule, FieldError],
  template: `
    <section class="card card--form">
      <h2>{{ 'Company' | t }}</h2>
      @if (!canEdit) {
        <p class="alert alert--info" role="status">{{ 'Only a Company Admin can change these details.' | t }}</p>
      }
      @if (notice()) {
        <p class="alert alert--success" role="status">{{ notice() | t }}</p>
      }
      @if (error()) {
        <p class="alert alert--error" role="alert">{{ error() | t }}</p>
      }
      @if (loading()) {
        <p class="muted" role="status">{{ 'Loading company details…' | t }}</p>
      } @else {
        <form [formGroup]="form" (ngSubmit)="save()" novalidate>
          <div class="field">
            <label for="name">{{ 'Company name' | t }}</label>
            <input id="name" type="text" formControlName="name" autocomplete="organization" />
            <app-field-error [control]="form.controls.name" />
          </div>
          <div class="field">
            <label for="website">{{ 'Website' | t }}</label>
            <input id="website" type="url" formControlName="website" placeholder="https://example.com" />
            <app-field-error [control]="form.controls.website" />
          </div>
          <div class="field">
            <label for="phone">{{ 'Phone' | t }}</label>
            <input id="phone" type="tel" formControlName="phone" autocomplete="tel" />
            <app-field-error [control]="form.controls.phone" />
          </div>
          <div class="field">
            <label for="channel_name">{{ 'Channel name' | t }}</label>
            <input id="channel_name" type="text" formControlName="channel_name" maxlength="120" />
            <app-field-error [control]="form.controls.channel_name" />
          </div>
          <div class="field">
            <label for="industry">{{ 'Industry' | t }}</label>
            <select id="industry" formControlName="industry">
              <option value="">{{ 'Not set' | t }}</option>
              @for (item of industries; track item) {
                <option [value]="item">{{ item | t }}</option>
              }
            </select>
          </div>
          <div class="field">
            <label for="country">{{ 'Country code' | t }}</label>
            <input id="country" type="text" formControlName="country" maxlength="2" aria-describedby="country-hint" />
            <p class="field-hint" id="country-hint">{{ 'Two letters, for example IN, US or GB.' | t }}</p>
            <app-field-error [control]="form.controls.country" />
          </div>
          <div class="field">
            <label for="timezone">{{ 'Time zone' | t }}</label>
            <select id="timezone" formControlName="timezone">
              @for (zone of zones; track zone) {
                <option [value]="zone">{{ zone }}</option>
              }
            </select>
            <app-field-error [control]="form.controls.timezone" />
          </div>
          @if (canEdit) {
            <button class="btn btn--primary" type="submit" [disabled]="busy()">{{ (busy() ? 'Saving…' : 'Save company') | t }}</button>
          }
        </form>
      }
    </section>
  `,
})
export class CompanySettings implements OnInit {
  private readonly auth = inject(AuthService);
  private readonly api = inject(Api);

  protected readonly canEdit = this.auth.can('company.manage');
  protected readonly zones = timeZones();
  protected readonly loading = signal(true);
  protected readonly busy = signal(false);
  protected readonly notice = signal('');
  protected readonly error = signal('');

  protected readonly industries = [
    'Beauty & personal care', 'Education', 'Entertainment', 'Fashion & apparel', 'Finance', 'Food & beverage',
    'Health & fitness', 'Home & lifestyle', 'Retail & e-commerce', 'Technology', 'Travel & hospitality', 'Other',
  ];
  protected readonly form = inject(NonNullableFormBuilder).group({
    name: ['', Validators.required],
    website: [''],
    country: [''],
    phone: [''],
    channel_name: [''],
    industry: [''],
    timezone: ['UTC', Validators.required],
  });

  ngOnInit(): void {
    if (!this.canEdit) {
      this.form.disable();
    }
    this.api.company().subscribe({
      next: (company) => {
        this.form.patchValue({
          name: company.name,
          website: company.website ?? '',
          country: company.country ?? '',
          phone: company.phone ?? '',
          channel_name: company.channel_name ?? '',
          industry: company.industry ?? '',
          timezone: company.timezone,
        });
        this.loading.set(false);
      },
      error: (err: unknown) => {
        this.error.set(messageFrom(err, 'Company details could not be loaded.'));
        this.loading.set(false);
      },
    });
  }

  protected save(): void {
    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }
    const value = this.form.getRawValue();
    this.busy.set(true);
    this.notice.set('');
    this.error.set('');

    this.api
      .updateCompany({
        name: value.name,
        website: value.website || null,
        country: value.country ? value.country.toUpperCase() : null,
        timezone: value.timezone,
        phone: value.phone || null,
        channel_name: value.channel_name || null,
        industry: value.industry || null,
      })
      .subscribe({
        next: () => {
          this.notice.set('Company saved.');
          this.busy.set(false);
          this.auth.refresh().subscribe(); // the sidebar shows the company name
        },
        error: (err: unknown) => {
          if (!applyServerErrors(this.form, err)) {
            this.error.set(messageFrom(err, 'The company could not be saved.'));
          }
          this.busy.set(false);
        },
      });
  }
}

function timeZones(): string[] {
  const intl = Intl as unknown as { supportedValuesOf?: (key: string) => string[] };
  const zones = intl.supportedValuesOf?.('timeZone') ?? ['Asia/Kolkata', 'Europe/London', 'America/New_York'];
  return ['UTC', ...zones.filter((zone) => zone !== 'UTC')];
}
