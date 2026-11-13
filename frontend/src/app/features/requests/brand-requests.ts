import { CurrencyPipe, DatePipe } from '@angular/common';
import { Component, OnInit, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { messageFrom } from '../../core/forms';
import { BrandRequest, RequestStatus } from '../../core/models';
import { Api } from '../../core/services/api';
import { TPipe } from '../../core/i18n/t.pipe';
import { InfluencerAvatar } from '../../shared/components/influencer-avatar';
import { RequestStatusBadge } from '../../shared/components/request-status-badge';
import { PLATFORM_LABELS } from '../influencers/labels';

type Filter = RequestStatus | '';

/** The requests this company has sent to creators, and how each creator answered. */
@Component({
  selector: 'app-brand-requests',
  imports: [TPipe, RouterLink, CurrencyPipe, DatePipe, InfluencerAvatar, RequestStatusBadge],
  template: `
    <header class="page-head page-head--row">
      <div>
        <h1>{{ 'Requests' | t }}</h1>
        <p class="muted">{{ 'What you have asked creators to do, and their answers.' | t }}</p>
      </div>
      <div class="sort">
        <label for="request-filter">{{ 'Show' | t }}</label>
        <select id="request-filter" (change)="setFilter($any($event.target).value)">
          <option value="" [selected]="filter() === ''">{{ 'All' | t }}</option>
          <option value="pending" [selected]="filter() === 'pending'">{{ 'Waiting for an answer' | t }}</option>
          <option value="accepted" [selected]="filter() === 'accepted'">{{ 'Accepted' | t }}</option>
          <option value="declined" [selected]="filter() === 'declined'">{{ 'Declined' | t }}</option>
          <option value="withdrawn" [selected]="filter() === 'withdrawn'">{{ 'Withdrawn' | t }}</option>
        </select>
      </div>
    </header>

    @if (error()) {
      <p class="alert alert--error" role="alert">{{ error() | t }}</p>
    }

    @if (loading()) {
      <p class="muted" role="status">{{ 'Loading requests…' | t }}</p>
    } @else if (requests().length === 0) {
      <div class="card empty">
        <p><strong>{{ (filter() ? 'No requests match.' : 'You have not sent any requests yet.') | t }}</strong></p>
        <p class="muted">{{ 'Open a creator’s profile and choose Send a request.' | t }}</p>
        <a class="btn btn--primary" routerLink="/influencers">{{ 'Find influencers' | t }}</a>
      </div>
    } @else {
      <ul class="requests">
        @for (request of requests(); track request.id) {
          <li class="card request">
            <div class="request__head">
              <app-influencer-avatar [name]="request.creator.name" [src]="request.creator.avatar_url" [platform]="request.creator.platform" size="lg" />
              <div class="request__who">
                <h2>{{ request.title }}</h2>
                <p class="muted">
                  {{ 'To' | t }}
                  @if (request.creator.profile_id) {
                    <a [routerLink]="['/influencers', request.creator.profile_id]">{{ request.creator.name }}</a>
                  } @else {
                    {{ request.creator.name }}
                  }
                  @if (request.creator.platform && request.creator.username) {
                    · {{ platformLabels[request.creator.platform] }} &#64;{{ request.creator.username }}
                  }
                </p>
              </div>
              <app-request-status-badge [status]="request.status" />
            </div>

            <p class="request__message">{{ request.message }}</p>

            <dl class="request__facts">
              <div><dt>{{ 'Sent' | t }}</dt><dd>{{ request.created_at | date: 'mediumDate' }}@if (request.requested_by) { · {{ request.requested_by }} }</dd></div>
              @if (request.budget !== null) {
                <div><dt>{{ 'Budget' | t }}</dt><dd>{{ request.budget | currency: request.currency : 'symbol' : '1.0-0' }}</dd></div>
              }
              @if (request.due_date) {
                <div><dt>{{ 'Needed by' | t }}</dt><dd>{{ request.due_date | date: 'mediumDate' }}</dd></div>
              }
              @if (request.package) {
                <div><dt>{{ 'Package' | t }}</dt><dd>{{ request.package.title }} · {{ request.package.price | currency: request.package.currency : 'symbol' : '1.0-0' }}</dd></div>
              }
            </dl>

            @if (request.creator_response) {
              <blockquote class="request__reply">
                <strong>{{ request.creator.name }}:</strong> {{ request.creator_response }}
              </blockquote>
            }
            @if (request.status === 'accepted' && request.creator.email) {
              <p class="alert alert--success">
                {{ 'Accepted. Contact {name}:' | t: { name: request.creator.name } }}
                <a [href]="'mailto:' + request.creator.email">{{ request.creator.email }}</a>
              </p>
            }

            @if (request.status === 'pending') {
              <div class="request__actions">
                @if (confirming() === request.id) {
                  <span>{{ 'Withdraw this request?' | t }}</span>
                  <button class="btn btn--danger" type="button" [disabled]="busy()" (click)="withdraw(request)">{{ 'Withdraw' | t }}</button>
                  <button class="btn" type="button" (click)="confirming.set(null)">{{ 'Cancel' | t }}</button>
                } @else {
                  <button class="btn btn--quiet" type="button" (click)="confirming.set(request.id)">{{ 'Withdraw request' | t }}</button>
                }
              </div>
            }
          </li>
        }
      </ul>
    }
  `,
})
export class BrandRequests implements OnInit {
  private readonly api = inject(Api);
  protected readonly platformLabels = PLATFORM_LABELS;

  protected readonly requests = signal<BrandRequest[]>([]);
  protected readonly filter = signal<Filter>('');
  protected readonly loading = signal(true);
  protected readonly busy = signal(false);
  protected readonly error = signal('');
  protected readonly confirming = signal<number | null>(null);

  ngOnInit(): void {
    this.load();
  }

  protected setFilter(value: Filter): void {
    this.filter.set(value);
    this.load();
  }

  protected withdraw(request: BrandRequest): void {
    this.busy.set(true);
    this.error.set('');
    this.api.withdrawRequest(request.id).subscribe({
      next: (updated) => {
        this.requests.update((list) =>
          this.filter() && updated.status !== this.filter() ? list.filter((r) => r.id !== updated.id) : list.map((r) => (r.id === updated.id ? updated : r)),
        );
        this.confirming.set(null);
        this.busy.set(false);
      },
      error: (err: unknown) => {
        this.error.set(messageFrom(err, 'The request could not be withdrawn.'));
        this.confirming.set(null);
        this.busy.set(false);
      },
    });
  }

  private load(): void {
    this.loading.set(true);
    this.error.set('');
    this.api.brandRequests(this.filter()).subscribe({
      next: (res) => {
        this.requests.set(res.data);
        this.loading.set(false);
      },
      error: (err: unknown) => {
        this.error.set(messageFrom(err, 'Requests could not be loaded.'));
        this.loading.set(false);
      },
    });
  }
}
