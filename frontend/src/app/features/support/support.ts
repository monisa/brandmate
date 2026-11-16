import { DatePipe } from '@angular/common';
import { Component, OnInit, inject, signal } from '@angular/core';
import { Api } from '../../core/services/api';
import { TPipe } from '../../core/i18n/t.pipe';
import { SupportTicket } from '../../core/models';

const CATEGORIES: { value: string; label: string }[] = [
  { value: 'account', label: 'My account' },
  { value: 'payment', label: 'Payment or subscription' },
  { value: 'profile', label: 'My profile or company' },
  { value: 'chat', label: 'Messages and chat' },
  { value: 'other', label: 'Something else' },
];

/** Raise a ticket when the help answers were not enough, and follow the replies. */
@Component({
  selector: 'app-support',
  imports: [TPipe, DatePipe],
  template: `
    <header class="page-head">
      <h1>{{ 'Support' | t }}</h1>
      <p class="muted">{{ 'Tell us what went wrong. A person from the BrandMate team will reply here and by e-mail.' | t }}</p>
    </header>

    @if (error()) { <p class="alert alert--error" role="alert">{{ error() | t }}</p> }
    @if (notice()) { <p class="alert alert--success" role="status">{{ notice() | t }}</p> }

    @if (selected(); as ticket) {
      <button class="btn btn--quiet" type="button" (click)="selected.set(null)">← {{ 'All tickets' | t }}</button>
      <section class="card ticket" aria-live="polite">
        <h2>#{{ ticket.id }} · {{ ticket.subject }} <span class="badge ticket__status ticket__status--{{ ticket.status }}">{{ statusLabel(ticket.status) | t }}</span></h2>
        <ol class="ticket__thread">
          @for (reply of ticket.replies ?? []; track reply.id) {
            <li class="ticket__msg" [class.is-staff]="reply.staff">
              <strong>{{ reply.staff ? ('BrandMate support' | t) : ('You' | t) }}</strong>
              <time>{{ reply.created_at | date: 'd MMM y, h:mm a' }}</time>
              <p>{{ reply.body }}</p>
            </li>
          }
        </ol>
        <form (submit)="$event.preventDefault(); reply(ticket.id)">
          <div class="field">
            <label for="reply-body">{{ ticket.status === 'closed' ? ('Write again to reopen this ticket' | t) : ('Your reply' | t) }}</label>
            <textarea id="reply-body" rows="3" maxlength="4000" [value]="replyBody()" (input)="replyBody.set($any($event.target).value)"></textarea>
          </div>
          <button class="btn btn--primary" type="submit" [disabled]="busy() || replyBody().trim().length < 2">{{ 'Send reply' | t }}</button>
        </form>
      </section>
    } @else {
      <div class="columns">
        <section class="card" aria-labelledby="new-ticket">
          <h2 id="new-ticket">{{ 'Raise a ticket' | t }}</h2>
          <form (submit)="$event.preventDefault(); create()">
            <div class="field">
              <label for="t-category">{{ 'About' | t }}</label>
              <select id="t-category" (change)="category.set($any($event.target).value)">
                @for (c of categories; track c.value) {
                  <option [value]="c.value" [selected]="c.value === category()">{{ c.label | t }}</option>
                }
              </select>
            </div>
            <div class="field">
              <label for="t-subject">{{ 'Subject' | t }}</label>
              <input id="t-subject" maxlength="160" [value]="subject()" (input)="subject.set($any($event.target).value)" />
            </div>
            <div class="field">
              <label for="t-message">{{ 'What happened?' | t }}</label>
              <textarea id="t-message" rows="5" maxlength="4000" [value]="message()" (input)="message.set($any($event.target).value)"></textarea>
              <p class="field-hint">{{ 'Please do not share passwords or card details.' | t }}</p>
            </div>
            <button class="btn btn--primary" type="submit" [disabled]="busy() || subject().trim().length < 3 || message().trim().length < 5">
              {{ busy() ? ('Sending…' | t) : ('Send to support' | t) }}
            </button>
          </form>
        </section>

        <section class="card" aria-labelledby="my-tickets">
          <h2 id="my-tickets">{{ 'Your tickets' | t }}</h2>
          @if (loading()) {
            <p class="muted" role="status">{{ 'Loading…' | t }}</p>
          } @else if (tickets().length === 0) {
            <p class="muted">{{ 'You have not raised any tickets.' | t }}</p>
          } @else {
            <ul class="ticket__list">
              @for (t of tickets(); track t.id) {
                <li>
                  <button type="button" class="ticket__row" (click)="open(t.id)">
                    <span><strong>#{{ t.id }}</strong> {{ t.subject }}</span>
                    <span class="badge ticket__status ticket__status--{{ t.status }}">{{ statusLabel(t.status) | t }}</span>
                  </button>
                </li>
              }
            </ul>
          }
        </section>
      </div>
    }
  `,
})
export class Support implements OnInit {
  private readonly api = inject(Api);

  protected readonly categories = CATEGORIES;
  protected readonly tickets = signal<SupportTicket[]>([]);
  protected readonly selected = signal<SupportTicket | null>(null);
  protected readonly loading = signal(true);
  protected readonly busy = signal(false);
  protected readonly error = signal('');
  protected readonly notice = signal('');
  protected readonly category = signal('other');
  protected readonly subject = signal('');
  protected readonly message = signal('');
  protected readonly replyBody = signal('');

  ngOnInit(): void {
    this.load();
  }

  protected statusLabel(status: string): string {
    return status === 'open' ? 'Waiting for support' : status === 'answered' ? 'Answered' : 'Closed';
  }

  protected open(id: number): void {
    this.error.set('');
    this.api.supportTicket(id).subscribe({
      next: (ticket) => this.selected.set(ticket),
      error: (e) => this.error.set(this.messageOf(e)),
    });
  }

  protected create(): void {
    this.busy.set(true);
    this.error.set('');
    this.api.createTicket({ subject: this.subject().trim(), message: this.message().trim(), category: this.category() }).subscribe({
      next: (ticket) => {
        this.busy.set(false);
        this.subject.set('');
        this.message.set('');
        this.notice.set('Your ticket was sent. We will reply here and by e-mail.');
        this.selected.set(ticket);
        this.load();
      },
      error: (e) => {
        this.busy.set(false);
        this.error.set(this.messageOf(e));
      },
    });
  }

  protected reply(id: number): void {
    this.busy.set(true);
    this.error.set('');
    this.notice.set('');
    this.api.replyToTicket(id, this.replyBody().trim()).subscribe({
      next: (ticket) => {
        this.busy.set(false);
        this.replyBody.set('');
        this.selected.set(ticket);
        this.load();
      },
      error: (e) => {
        this.busy.set(false);
        this.error.set(this.messageOf(e));
      },
    });
  }

  private load(): void {
    this.api.supportTickets().subscribe({
      next: (tickets) => {
        this.tickets.set(tickets);
        this.loading.set(false);
      },
      error: () => this.loading.set(false),
    });
  }

  private messageOf(e: unknown): string {
    const body = (e as { error?: { message?: string; errors?: Record<string, string[]> } }).error;

    return Object.values(body?.errors ?? {})[0]?.[0] ?? body?.message ?? 'Something went wrong. Please try again.';
  }
}
