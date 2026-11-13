import { CurrencyPipe, DatePipe } from '@angular/common';
import { Component, OnInit, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { messageFrom } from '../../core/forms';
import { CreatorRequest, RequestStatus } from '../../core/models';
import { Api } from '../../core/services/api';
import { TPipe } from '../../core/i18n/t.pipe';
import { RequestStatusBadge } from '../../shared/components/request-status-badge';

type Filter = RequestStatus | '';

/** Everything brands have asked this creator, with Accept and Decline. */
@Component({
  selector: 'app-creator-requests',
  imports: [TPipe, RouterLink, CurrencyPipe, DatePipe, RequestStatusBadge],
  template: `
    <header class="page-head page-head--row">
      <div>
        <h1>{{ 'Requests from brands' | t }}</h1>
        <p class="muted">
          @if (pending() > 0) {
            {{ '{count} waiting for your answer.' | t: { count: pending() } }}
          } @else {
            {{ 'Brands that want to work with you appear here.' | t }}
          }
        </p>
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
        <p><strong>{{ (filter() ? 'No requests match.' : 'No requests yet.') | t }}</strong></p>
        <p class="muted">{{ 'Keep your profile complete and listed so brands can find you.' | t }}</p>
        <a class="btn" routerLink="/creator/profile">{{ 'My creator profile' | t }}</a>
      </div>
    } @else {
      <ul class="requests">
        @for (request of requests(); track request.id) {
          <li class="card request">
            <div class="request__head">
              <span class="avatar avatar--light" aria-hidden="true">{{ initials(request.brand.company) }}</span>
              <div class="request__who">
                <h2>{{ request.title }}</h2>
                <p class="muted">
                  {{ 'From' | t }} <strong>{{ request.brand.company ?? '–' }}</strong>
                  @if (request.brand.requested_by) {
                    · {{ request.brand.requested_by }}
                  }
                </p>
              </div>
              <app-request-status-badge [status]="request.status" />
            </div>

            <p class="request__message">{{ request.message }}</p>

            <dl class="request__facts">
              <div><dt>{{ 'Received' | t }}</dt><dd>{{ request.created_at | date: 'mediumDate' }}</dd></div>
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

            @if (request.status === 'pending') {
              @if (answering() === request.id) {
                <div class="field">
                  <label [for]="'reply-' + request.id">{{ 'Note to the brand (optional)' | t }}</label>
                  <textarea [id]="'reply-' + request.id" rows="3" maxlength="1000" #reply
                            [placeholder]="'For example: rates, timing or what you need from them.' | t"></textarea>
                </div>
                <div class="request__actions">
                  <button class="btn btn--primary" type="button" [disabled]="busy()" (click)="respond(request, 'accepted', reply.value)">{{ 'Accept' | t }}</button>
                  <button class="btn btn--danger" type="button" [disabled]="busy()" (click)="respond(request, 'declined', reply.value)">{{ 'Decline' | t }}</button>
                  <button class="btn btn--quiet" type="button" (click)="answering.set(null)">{{ 'Cancel' | t }}</button>
                </div>
              } @else {
                <div class="request__actions">
                  <button class="btn btn--primary" type="button" (click)="answering.set(request.id)">{{ 'Answer this request' | t }}</button>
                </div>
              }
            } @else {
              @if (request.creator_response) {
                <blockquote class="request__reply"><strong>{{ 'Your note' | t }}:</strong> {{ request.creator_response }}</blockquote>
              }
              @if (request.status === 'accepted' && request.brand.email) {
                <p class="alert alert--success">
                  {{ 'You accepted. Contact {name}:' | t: { name: request.brand.requested_by ?? request.brand.company ?? '' } }}
                  <a [href]="'mailto:' + request.brand.email">{{ request.brand.email }}</a>
                </p>
              }
            }
          </li>
        }
      </ul>
    }
  `,
})
export class CreatorRequests implements OnInit {
  private readonly api = inject(Api);

  protected readonly requests = signal<CreatorRequest[]>([]);
  protected readonly pending = signal(0);
  protected readonly filter = signal<Filter>('');
  protected readonly loading = signal(true);
  protected readonly busy = signal(false);
  protected readonly error = signal('');
  protected readonly answering = signal<number | null>(null);

  ngOnInit(): void {
    this.load();
  }

  protected setFilter(value: Filter): void {
    this.filter.set(value);
    this.load();
  }

  protected initials(name: string | null): string {
    return (name ?? '?').split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part[0]!.toUpperCase()).join('');
  }

  protected respond(request: CreatorRequest, decision: 'accepted' | 'declined', note: string): void {
    this.busy.set(true);
    this.error.set('');
    this.api.respondToRequest(request.id, decision, note.trim()).subscribe({
      next: (updated) => {
        this.requests.update((list) =>
          this.filter() && updated.status !== this.filter() ? list.filter((r) => r.id !== updated.id) : list.map((r) => (r.id === updated.id ? updated : r)),
        );
        this.pending.update((n) => Math.max(0, n - 1));
        this.answering.set(null);
        this.busy.set(false);
      },
      error: (err: unknown) => {
        this.error.set(messageFrom(err, 'Your answer could not be saved.'));
        this.busy.set(false);
      },
    });
  }

  private load(): void {
    this.loading.set(true);
    this.error.set('');
    this.api.creatorRequests(this.filter()).subscribe({
      next: (res) => {
        this.requests.set(res.data);
        this.pending.set(res.pending_count);
        this.loading.set(false);
      },
      error: (err: unknown) => {
        this.error.set(messageFrom(err, 'Requests could not be loaded.'));
        this.loading.set(false);
      },
    });
  }
}
