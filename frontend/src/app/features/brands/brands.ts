import { Component, OnInit, inject, signal } from '@angular/core';
import { FormControl, ReactiveFormsModule } from '@angular/forms';
import { messageFrom } from '../../core/forms';
import { BrandCard, BrandFilterOptions } from '../../core/models';
import { TPipe } from '../../core/i18n/t.pipe';
import { Api } from '../../core/services/api';
import { ChatService } from '../../core/services/chat.service';
import { LockNotice } from '../../shared/components/lock-notice';

/** Brands, as a creator sees them. Contact details are masked until the creator subscribes. */
@Component({
  selector: 'app-brands',
  imports: [TPipe, ReactiveFormsModule, LockNotice],
  template: `
    <header class="page-head">
      <h1>{{ 'Brands' | t }}</h1>
      <p class="muted">{{ 'Find brands that work with creators and start a conversation.' | t }}</p>
    </header>

    <form class="filters card" (submit)="$event.preventDefault(); search()" role="search">
      <label class="field">
        <span>{{ 'Search brands' | t }}</span>
        <input type="search" [formControl]="q" [placeholder]="'Brand name' | t" />
      </label>
      <label class="field">
        <span>{{ 'Industry' | t }}</span>
        <select [formControl]="industry" (change)="search()">
          <option value="">{{ 'All industries' | t }}</option>
          @for (item of options().industries; track item) {
            <option [value]="item">{{ item | t }}</option>
          }
        </select>
      </label>
      <label class="field">
        <span>{{ 'Country' | t }}</span>
        <select [formControl]="country" (change)="search()">
          <option value="">{{ 'All countries' | t }}</option>
          @for (item of options().countries; track item) {
            <option [value]="item">{{ item }}</option>
          }
        </select>
      </label>
      <button class="btn btn--primary" type="submit">{{ 'Search' | t }}</button>
    </form>

    @if (isLocked()) {
      <app-lock-notice audience="creator" />
    }
    @if (error()) {
      <p class="alert alert--error" role="alert">{{ error() | t }}</p>
    }

    @if (loading()) {
      <p class="muted" role="status">{{ 'Loading…' | t }}</p>
    } @else if (brands().length === 0) {
      <div class="card empty"><p><strong>{{ 'No brands found.' | t }}</strong></p></div>
    } @else {
      <ul class="brand-grid">
        @for (brand of brands(); track brand.id) {
          <li class="card brand-card">
            <span class="brand-card__logo" aria-hidden="true">{{ brand.name.charAt(0).toUpperCase() }}</span>
            <h3>{{ brand.name }}</h3>
            <p class="muted">{{ brand.industry ? (brand.industry | t) : '' }}{{ brand.industry && brand.country ? ' · ' : '' }}{{ brand.country }}</p>
            <ul class="creator-card__contact" [class.is-masked]="brand.locked">
              <li>✉ {{ brand.contact.email ?? '–' }}</li>
              <li>☎ {{ brand.contact.phone ?? '–' }}</li>
              <li>{{ 'Channel' | t }}: {{ brand.contact.channel_name ?? '–' }}</li>
            </ul>
            @if (brand.locked) {
              <button class="btn btn--block" type="button" disabled [attr.title]="'Subscribe to message brands' | t">{{ 'Message' | t }}</button>
            } @else {
              <button class="btn btn--primary btn--block" type="button" (click)="message(brand.id)">{{ 'Message' | t }}</button>
            }
          </li>
        }
      </ul>
      @if (lastPage() > 1) {
        <nav class="pager" [attr.aria-label]="'Pages' | t">
          <button class="btn" type="button" [disabled]="page() <= 1" (click)="go(page() - 1)">{{ 'Previous' | t }}</button>
          <span>{{ page() }} / {{ lastPage() }}</span>
          <button class="btn" type="button" [disabled]="page() >= lastPage()" (click)="go(page() + 1)">{{ 'Next' | t }}</button>
        </nav>
      }
    }
  `,
})
export class Brands implements OnInit {
  private readonly api = inject(Api);
  private readonly chat = inject(ChatService);

  protected readonly q = new FormControl('', { nonNullable: true });
  protected readonly industry = new FormControl('', { nonNullable: true });
  protected readonly country = new FormControl('', { nonNullable: true });

  protected readonly brands = signal<BrandCard[]>([]);
  protected readonly options = signal<BrandFilterOptions>({ industries: [], countries: [] });
  protected readonly loading = signal(true);
  protected readonly error = signal('');
  protected readonly page = signal(1);
  protected readonly lastPage = signal(1);

  protected isLocked(): boolean {
    return this.brands().some((b) => b.locked);
  }

  ngOnInit(): void {
    this.api.brandFilters().subscribe({ next: (o) => this.options.set(o), error: () => undefined });
    this.load();
  }

  protected search(): void {
    this.go(1);
  }

  protected go(page: number): void {
    this.page.set(page);
    this.load();
  }

  private load(): void {
    this.loading.set(true);
    this.error.set('');
    this.api
      .brands({ q: this.q.value, industry: this.industry.value, country: this.country.value, page: String(this.page()) })
      .subscribe({
        next: (res) => {
          this.brands.set(res.data);
          this.lastPage.set(res.meta.last_page);
          this.loading.set(false);
        },
        error: (err: unknown) => {
          this.error.set(messageFrom(err, 'Brands could not be loaded.'));
          this.loading.set(false);
        },
      });
  }

  protected message(companyId: number): void {
    this.error.set('');
    this.chat.open({ company_id: companyId }, (message) => this.error.set(message));
  }
}
