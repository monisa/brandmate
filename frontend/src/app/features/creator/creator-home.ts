import { CurrencyPipe } from '@angular/common';
import { Component, OnInit, inject, signal } from '@angular/core';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { applyServerErrors, messageFrom } from '../../core/forms';
import { CreatorAccount, CreatorPackage, CreatorProfile, Platform } from '../../core/models';
import { Api } from '../../core/services/api';
import { FieldError } from '../../shared/components/field-error';
import { MetricPipe } from '../../shared/pipes/metric.pipe';
import { PLATFORM_LABELS } from '../influencers/labels';
import { I18n } from '../../core/i18n/i18n';
import { TPipe } from '../../core/i18n/t.pipe';

const PLATFORMS: Platform[] = ['instagram', 'youtube', 'facebook'];

const LINK_EXAMPLES: Record<Platform, string> = {
  instagram: 'https://www.instagram.com/yourname',
  youtube: 'https://www.youtube.com/@yourname',
  facebook: 'https://www.facebook.com/yourname',
};

/** Everything a creator manages: visibility, profile, social accounts, packages. */
@Component({
  selector: 'app-creator-home',
  imports: [TPipe, ReactiveFormsModule, CurrencyPipe, FieldError, MetricPipe],
  template: `
    <header class="page-head">
      <h1>{{ 'My creator profile' | t }}</h1>
      <p class="muted">{{ 'This is what brands see when they search for creators.' | t }}</p>
    </header>

    @if (loading()) {
      <p class="muted" role="status">{{ 'Loading your profile…' | t }}</p>
    } @else if (!profile()) {
      <p class="alert alert--error" role="alert">{{ 'Your profile could not be loaded.' | t }}</p>
      <button class="btn" type="button" (click)="load()">{{ 'Try again' | t }}</button>
    } @else {
      @let p = profile()!;

      @if (notice()) {
        <p class="alert alert--success" role="status">{{ notice() | t }}</p>
      }
      @if (error()) {
        <p class="alert alert--error" role="alert">{{ error() | t }}</p>
      }

      @if (p.approval_status === 'pending') {
        <section class="card approval approval--pending" role="status">
          <h2>{{ 'Your profile is waiting for approval' | t }}</h2>
          <p>{{ 'The BrandMate team reviews every new creator before brands can see them. You can keep editing your profile in the meantime.' | t }}</p>
        </section>
      } @else if (p.approval_status === 'rejected') {
        <section class="card approval approval--rejected" role="alert">
          <h2>{{ 'Your profile was not approved' | t }}</h2>
          @if (p.review_note) {
            <p>{{ p.review_note }}</p>
          }
          <p class="muted">{{ 'Update your profile and contact the BrandMate team to ask for another review.' | t }}</p>
        </section>
      }

      <section class="card visibility" [class.is-on]="p.listed && p.approval_status === 'approved'">
        <div>
          <h2>{{ (p.listed ? (p.approval_status === 'approved' ? 'Brands can find you' : 'You are listed, waiting for approval') : 'Your profile is hidden from brands') | t }}</h2>
          @if (p.listed) {
            <p class="muted">{{ (p.approval_status === 'approved' ? 'Your profile appears in brand searches.' : 'You will appear in brand searches as soon as the BrandMate team approves your profile.') | t }}</p>
          } @else if (p.missing_for_listing.length) {
            <p>{{ 'Before you can be listed:' | t }}</p>
            <ul>
              @for (item of p.missing_for_listing; track item) {
                <li>{{ item | t }}</li>
              }
            </ul>
          } @else {
            <p class="muted">{{ 'Your profile is complete. List it when you are ready.' | t }}</p>
          }
        </div>
        @if (p.listed) {
          <button class="btn" type="button" (click)="setListing(false)" [disabled]="busy()">{{ 'Hide my profile' | t }}</button>
        } @else {
          <button class="btn btn--primary" type="button" (click)="setListing(true)" [disabled]="busy() || p.missing_for_listing.length > 0">
            {{ 'List my profile' | t }}
          </button>
        }
      </section>

      <section class="card card--form">
        <h2>{{ 'About you' | t }}</h2>
        <form [formGroup]="profileForm" (ngSubmit)="saveProfile()" novalidate>
          <div class="field">
            <label for="c-name">{{ 'Name brands will see' | t }}</label>
            <input id="c-name" type="text" formControlName="name" />
            <app-field-error [control]="profileForm.controls.name" />
          </div>
          <div class="field">
            <label for="c-category">{{ 'Category' | t }}</label>
            <select id="c-category" formControlName="category">
              <option value="">{{ 'Choose a category' | t }}</option>
              @for (category of p.category_options; track category) {
                <option [value]="category">{{ category | t }}</option>
              }
            </select>
            <app-field-error [control]="profileForm.controls.category" />
          </div>
          <div class="field">
            <label for="c-bio">{{ 'Bio' | t }}</label>
            <textarea id="c-bio" rows="3" maxlength="1000" formControlName="bio" aria-describedby="c-bio-hint"></textarea>
            <p class="field-hint" id="c-bio-hint">{{ 'What you make and who watches it.' | t }}</p>
            <app-field-error [control]="profileForm.controls.bio" />
          </div>
          <div class="field-pair">
            <div class="field">
              <label for="c-country">{{ 'Country code' | t }}</label>
              <input id="c-country" type="text" maxlength="2" formControlName="country" aria-describedby="c-country-hint" />
              <p class="field-hint" id="c-country-hint">{{ 'Two letters, for example IN.' | t }}</p>
              <app-field-error [control]="profileForm.controls.country" />
            </div>
            <div class="field">
              <label for="c-city">{{ 'City' | t }}</label>
              <input id="c-city" type="text" formControlName="city" />
              <app-field-error [control]="profileForm.controls.city" />
            </div>
          </div>
          <div class="field-pair">
            <div class="field">
              <label for="c-state">{{ 'State' | t }}</label>
              <input id="c-state" type="text" formControlName="state" />
              <app-field-error [control]="profileForm.controls.state" />
            </div>
            <div class="field">
              <label for="c-language">{{ 'Main language' | t }}</label>
              <input id="c-language" type="text" formControlName="language" />
              <app-field-error [control]="profileForm.controls.language" />
            </div>
          </div>
          <button class="btn btn--primary" type="submit" [disabled]="busy()">{{ 'Save profile' | t }}</button>
        </form>
      </section>

      <section class="card">
        <h2>{{ 'Social accounts' | t }}</h2>
        <p class="muted">
          {{ 'Follower counts you enter are shown to brands as self-reported until account verification is available.' | t }}
        </p>
        @if (p.accounts.length) {
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th scope="col">{{ 'Platform' | t }}</th>
                  <th scope="col">{{ 'Username' | t }}</th>
                  <th scope="col" class="num">{{ 'Followers' | t }}</th>
                  <th scope="col"><span class="visually-hidden">{{ 'Actions' | t }}</span></th>
                </tr>
              </thead>
              <tbody>
                @for (account of p.accounts; track account.id) {
                  <tr>
                    <td><span class="platform platform--{{ account.platform }}">{{ labels[account.platform] }}</span></td>
                    <td>&#64;{{ account.username }}</td>
                    <td class="num">{{ account.followers | metric: 'full' }}</td>
                    <td class="actions">
                      <button class="btn btn--quiet" type="button" (click)="editAccount(account)">{{ 'Edit' | t }}</button>
                      <button class="btn btn--quiet" type="button" (click)="removeAccount(account)">{{ 'Remove' | t }}</button>
                    </td>
                  </tr>
                }
              </tbody>
            </table>
          </div>
        }

        <h3>{{ (editingAccount() ? 'Edit account' : 'Add an account') | t }}</h3>
        <form class="grid-form" [formGroup]="accountForm" (ngSubmit)="saveAccount()" novalidate>
          <div class="field">
            <label for="a-platform">{{ 'Platform' | t }}</label>
            <select id="a-platform" formControlName="platform">
              @for (platform of platforms; track platform) {
                <option [value]="platform">{{ labels[platform] }}</option>
              }
            </select>
            <app-field-error [control]="accountForm.controls.platform" />
          </div>
          <div class="field">
            <label for="a-username">{{ 'Username' | t }}</label>
            <input id="a-username" type="text" formControlName="username" autocapitalize="none" />
            <app-field-error [control]="accountForm.controls.username" />
          </div>
          <div class="field">
            <label for="a-url">{{ 'Profile link' | t }}</label>
            <input id="a-url" type="url" formControlName="profile_url" [placeholder]="linkExample()" />
            <app-field-error [control]="accountForm.controls.profile_url" />
          </div>
          <div class="field">
            <label for="a-followers">{{ 'Followers' | t }}</label>
            <input id="a-followers" type="number" min="0" formControlName="followers" />
            <app-field-error [control]="accountForm.controls.followers" />
          </div>
          <div class="grid-form__actions">
            <button class="btn btn--primary" type="submit" [disabled]="busy()">{{ (editingAccount() ? 'Save account' : 'Add account') | t }}</button>
            @if (editingAccount()) {
              <button class="btn" type="button" (click)="resetAccountForm()">{{ 'Cancel' | t }}</button>
            }
          </div>
        </form>
      </section>

      <section class="card">
        <h2>{{ 'Packages and rates' | t }}</h2>
        <p class="muted">{{ 'What a brand can book from you, and your price in {currency}.' | t: { currency: p.currency } }}</p>
        @if (p.packages.length) {
          <ul class="packages">
            @for (pack of p.packages; track pack.id) {
              <li>
                <div>
                  <strong>{{ pack.title }}</strong>
                  <span class="platform platform--{{ pack.platform }}">{{ labels[pack.platform] }}</span>
                  @if (!pack.is_active) {
                    <span class="badge badge--warn">{{ 'Not shown to brands' | t }}</span>
                  }
                  @if (pack.description) {
                    <p class="muted">{{ pack.description }}</p>
                  }
                </div>
                <div class="packages__price">
                  <strong>{{ pack.price | currency: pack.currency : 'symbol' : '1.0-0' }}</strong>
                  <span class="muted">{{ pack.delivery_days }} {{ (pack.delivery_days === 1 ? 'day' : 'days') | t }}</span>
                  <span>
                    <button class="btn btn--quiet" type="button" (click)="editPackage(pack)">{{ 'Edit' | t }}</button>
                    <button class="btn btn--quiet" type="button" (click)="removePackage(pack)">{{ 'Remove' | t }}</button>
                  </span>
                </div>
              </li>
            }
          </ul>
        }

        <h3>{{ (editingPackage() ? 'Edit package' : 'Add a package') | t }}</h3>
        <form class="grid-form" [formGroup]="packageForm" (ngSubmit)="savePackage()" novalidate>
          <div class="field">
            <label for="p-title">{{ 'Title' | t }}</label>
            <input id="p-title" type="text" formControlName="title" [placeholder]="'For example, 1 Instagram Reel' | t" />
            <app-field-error [control]="packageForm.controls.title" />
          </div>
          <div class="field">
            <label for="p-platform">{{ 'Platform' | t }}</label>
            <select id="p-platform" formControlName="platform">
              @for (platform of platforms; track platform) {
                <option [value]="platform">{{ labels[platform] }}</option>
              }
            </select>
            <app-field-error [control]="packageForm.controls.platform" />
          </div>
          <div class="field">
            <label for="p-price">{{ 'Price, {currency}' | t: { currency: p.currency } }}</label>
            <input id="p-price" type="number" min="1" step="1" formControlName="price" />
            <app-field-error [control]="packageForm.controls.price" />
          </div>
          <div class="field">
            <label for="p-days">{{ 'Delivery time, days' | t }}</label>
            <input id="p-days" type="number" min="1" max="365" step="1" formControlName="delivery_days" />
            <app-field-error [control]="packageForm.controls.delivery_days" />
          </div>
          <div class="field grid-form__wide">
            <label for="p-description">{{ 'What is included' | t }}</label>
            <textarea id="p-description" rows="2" maxlength="1000" formControlName="description"></textarea>
            <app-field-error [control]="packageForm.controls.description" />
          </div>
          <label class="checkbox grid-form__wide">
            <input type="checkbox" formControlName="is_active" />
            {{ 'Show this package to brands' | t }}
          </label>
          <div class="grid-form__actions">
            <button class="btn btn--primary" type="submit" [disabled]="busy()">{{ (editingPackage() ? 'Save package' : 'Add package') | t }}</button>
            @if (editingPackage()) {
              <button class="btn" type="button" (click)="resetPackageForm()">{{ 'Cancel' | t }}</button>
            }
          </div>
        </form>
      </section>
    }
  `,
})
export class CreatorHome implements OnInit {
  private readonly i18n = inject(I18n);
  private readonly api = inject(Api);
  private readonly fb = inject(NonNullableFormBuilder);

  protected readonly platforms = PLATFORMS;
  protected readonly labels = PLATFORM_LABELS;

  protected readonly profile = signal<CreatorProfile | null>(null);
  protected readonly loading = signal(true);
  protected readonly busy = signal(false);
  protected readonly notice = signal('');
  protected readonly error = signal('');
  protected readonly editingAccount = signal<number | null>(null);
  protected readonly editingPackage = signal<number | null>(null);
  protected readonly linkExample = signal(LINK_EXAMPLES.instagram);

  protected readonly profileForm = this.fb.group({
    name: ['', Validators.required],
    category: [''],
    bio: [''],
    country: [''],
    state: [''],
    city: [''],
    language: [''],
  });

  protected readonly accountForm = this.fb.group({
    platform: ['instagram' as Platform, Validators.required],
    username: ['', Validators.required],
    profile_url: ['', Validators.required],
    followers: [null as number | null, [Validators.required, Validators.min(0)]],
  });

  protected readonly packageForm = this.fb.group({
    title: ['', Validators.required],
    platform: ['instagram' as Platform, Validators.required],
    price: [null as number | null, [Validators.required, Validators.min(1)]],
    delivery_days: [7 as number | null, [Validators.required, Validators.min(1)]],
    description: [''],
    is_active: [true],
  });

  constructor() {
    this.accountForm.controls.platform.valueChanges.subscribe((platform) => this.linkExample.set(LINK_EXAMPLES[platform]));
  }

  ngOnInit(): void {
    this.load();
  }

  protected load(): void {
    this.loading.set(true);
    this.api.creatorProfile().subscribe({
      next: (profile) => {
        this.apply(profile);
        this.profileForm.patchValue({
          name: profile.name,
          category: profile.category ?? '',
          bio: profile.bio ?? '',
          country: profile.country ?? '',
          state: profile.state ?? '',
          city: profile.city ?? '',
          language: profile.language ?? '',
        });
        this.loading.set(false);
      },
      error: () => this.loading.set(false),
    });
  }

  protected setListing(listed: boolean): void {
    this.run(this.api.setCreatorListing(listed), listed ? 'Your profile is listed. Brands can find you.' : 'Your profile is hidden.');
  }

  protected saveProfile(): void {
    if (this.profileForm.invalid) {
      this.profileForm.markAllAsTouched();
      return;
    }
    const v = this.profileForm.getRawValue();
    this.run(
      this.api.updateCreatorProfile({
        name: v.name,
        category: v.category || null,
        bio: v.bio || null,
        country: v.country ? v.country.toUpperCase() : null,
        state: v.state || null,
        city: v.city || null,
        language: v.language || null,
      }),
      'Profile saved.',
      this.profileForm,
    );
  }

  // ---- Accounts ----
  protected editAccount(account: CreatorAccount): void {
    this.editingAccount.set(account.id);
    this.accountForm.reset({
      platform: account.platform,
      username: account.username,
      profile_url: account.profile_url,
      followers: account.followers,
    });
  }

  protected resetAccountForm(): void {
    this.editingAccount.set(null);
    this.accountForm.reset();
  }

  protected saveAccount(): void {
    if (this.accountForm.invalid) {
      this.accountForm.markAllAsTouched();
      return;
    }
    const v = this.accountForm.getRawValue();
    const id = this.editingAccount() ?? undefined;
    this.run(
      this.api.saveCreatorAccount({ ...v, followers: Number(v.followers) }, id),
      id ? 'Account saved.' : 'Account added.',
      this.accountForm,
      () => this.resetAccountForm(),
    );
  }

  protected removeAccount(account: CreatorAccount): void {
    this.run(this.api.deleteCreatorAccount(account.id), this.i18n.t('{platform} account removed.', { platform: this.labels[account.platform] }));
    if (this.editingAccount() === account.id) {
      this.resetAccountForm();
    }
  }

  // ---- Packages ----
  protected editPackage(pack: CreatorPackage): void {
    this.editingPackage.set(pack.id);
    this.packageForm.reset({
      title: pack.title,
      platform: pack.platform,
      price: pack.price,
      delivery_days: pack.delivery_days,
      description: pack.description ?? '',
      is_active: pack.is_active,
    });
  }

  protected resetPackageForm(): void {
    this.editingPackage.set(null);
    this.packageForm.reset();
  }

  protected savePackage(): void {
    if (this.packageForm.invalid) {
      this.packageForm.markAllAsTouched();
      return;
    }
    const v = this.packageForm.getRawValue();
    const id = this.editingPackage() ?? undefined;
    this.run(
      this.api.saveCreatorPackage(
        {
          title: v.title,
          platform: v.platform,
          price: Math.round(Number(v.price)),
          delivery_days: Math.round(Number(v.delivery_days)),
          description: v.description || null,
          is_active: v.is_active,
        },
        id,
      ),
      id ? 'Package saved.' : 'Package added.',
      this.packageForm,
      () => this.resetPackageForm(),
    );
  }

  protected removePackage(pack: CreatorPackage): void {
    this.run(this.api.deleteCreatorPackage(pack.id), this.i18n.t('{name} removed.', { name: pack.title }));
    if (this.editingPackage() === pack.id) {
      this.resetPackageForm();
    }
  }

  // ---- Helpers ----
  private apply(profile: CreatorProfile): void {
    this.profile.set(profile);
  }

  /** Runs a change, shows the outcome, and puts server validation on the form that caused it. */
  private run(
    request: ReturnType<Api['creatorProfile']>,
    success: string,
    form?: Parameters<typeof applyServerErrors>[0],
    after?: () => void,
  ): void {
    this.busy.set(true);
    this.notice.set('');
    this.error.set('');
    request.subscribe({
      next: (profile) => {
        this.apply(profile);
        this.notice.set(success);
        this.busy.set(false);
        after?.();
      },
      error: (err: unknown) => {
        if (!form || !applyServerErrors(form, err)) {
          this.error.set(messageFrom(err, 'That change could not be saved.'));
        }
        this.busy.set(false);
      },
    });
  }
}
