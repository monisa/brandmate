import { DatePipe } from '@angular/common';
import { Component, OnInit, inject, signal } from '@angular/core';
import { Api } from '../../core/services/api';
import { TPipe } from '../../core/i18n/t.pipe';
import { SupportTicket } from '../../core/models';

/** The platform team's inbox: every ticket, newest waiting first, with replies and closing. */
@Component({
  selector: 'app-admin-support',
  imports: [TPipe, DatePipe],
  template: `
    <header class="page-head">
      <h1>{{ 'Support tickets' | t }}</h1>
      <p class="muted">{{ openCount() }} {{ 'waiting for a reply' | t }}</p>
    </header>

    @if (error()) { <p class="alert alert--error" role="alert">{{ error() | t }}</p> }

    @if (selected(); as ticket) {
      <button class="btn btn--quiet" type="button" (click)="selected.set(null)">← {{ 'All tickets' | t }}</button>
      <section class="card ticket">
        <h2>#{{ ticket.id }} · {{ ticket.subject }} <span class="badge ticket__status ticket__status--{{ ticket.status }}">{{ ticket.status }}</span></h2>
        <p class="muted">{{ ticket.user?.name }} · {{ ticket.user?.email }} @if (ticket.category) { · {{ ticket.category }} }</p>
        <ol class="ticket__thread">
          @for (reply of ticket.replies ?? []; track reply.id) {
            <li class="ticket__msg" [class.is-staff]="reply.staff">
              <strong>{{ reply.staff ? 'Support' : reply.author }}</strong>
              <time>{{ reply.created_at | date: 'd MMM y, h:mm a' }}</time>
              <p>{{ reply.body }}</p>
            </li>
          }
        </ol>
        <form (submit)="$event.preventDefault(); reply(ticket.id)">
          <div class="field">
            <label for="admin-reply">{{ 'Reply' | t }}</label>
            <textarea id="admin-reply" rows="4" maxlength="4000" [value]="replyBody()" (input)="replyBody.set($any($event.target).value)"></textarea>
            <p class="field-hint">{{ 'The person is told by e-mail.' | t }}</p>
          </div>
          <button class="btn btn--primary" type="submit" [disabled]="busy() || replyBody().trim().length < 2">{{ 'Send reply' | t }}</button>
          <button class="btn" type="button" [disabled]="busy()" (click)="setStatus(ticket.id, ticket.status === 'closed' ? 'open' : 'closed')">
            {{ ticket.status === 'closed' ? ('Reopen' | t) : ('Close ticket' | t) }}
          </button>
        </form>
      </section>
    } @else {
      <div class="ticket__filters" role="group" [attr.aria-label]="'Filter' | t">
        @for (f of filters; track f.value) {
          <button type="button" class="btn" [class.btn--primary]="filter() === f.value" (click)="setFilter(f.value)">{{ f.label | t }}</button>
        }
      </div>
      <section class="card">
        @if (loading()) {
          <p class="muted" role="status">{{ 'Loading…' | t }}</p>
        } @else if (tickets().length === 0) {
          <p class="muted">{{ 'No tickets here.' | t }}</p>
        } @else {
          <ul class="ticket__list">
            @for (t of tickets(); track t.id) {
              <li>
                <button type="button" class="ticket__row" (click)="open(t.id)">
                  <span><strong>#{{ t.id }}</strong> {{ t.subject }}<br><small class="muted">{{ t.user?.name }} · {{ t.last_reply_at | date: 'd MMM, h:mm a' }}</small></span>
                  <span class="badge ticket__status ticket__status--{{ t.status }}">{{ t.status }}</span>
                </button>
              </li>
            }
          </ul>
        }
      </section>
    }
  `,
})
export class AdminSupport implements OnInit {
  private readonly api = inject(Api);

  protected readonly filters = [
    { value: '', label: 'All' },
    { value: 'open', label: 'Waiting for reply' },
    { value: 'answered', label: 'Answered' },
    { value: 'closed', label: 'Closed' },
  ];
  protected readonly filter = signal('open');
  protected readonly tickets = signal<SupportTicket[]>([]);
  protected readonly openCount = signal(0);
  protected readonly selected = signal<SupportTicket | null>(null);
  protected readonly loading = signal(true);
  protected readonly busy = signal(false);
  protected readonly error = signal('');
  protected readonly replyBody = signal('');

  ngOnInit(): void {
    this.load();
  }

  protected setFilter(value: string): void {
    this.filter.set(value);
    this.load();
  }

  protected open(id: number): void {
    this.api.adminTicket(id).subscribe({ next: (t) => this.selected.set(t), error: () => this.error.set('Could not open the ticket.') });
  }

  protected reply(id: number): void {
    this.busy.set(true);
    this.api.adminReplyToTicket(id, this.replyBody().trim()).subscribe({
      next: (t) => {
        this.busy.set(false);
        this.replyBody.set('');
        this.selected.set(t);
        this.load();
      },
      error: () => {
        this.busy.set(false);
        this.error.set('The reply could not be sent.');
      },
    });
  }

  protected setStatus(id: number, status: 'open' | 'closed'): void {
    this.busy.set(true);
    this.api.adminSetTicketStatus(id, status).subscribe({
      next: () => {
        this.busy.set(false);
        this.open(id);
        this.load();
      },
      error: () => {
        this.busy.set(false);
        this.error.set('Could not change the ticket.');
      },
    });
  }

  private load(): void {
    this.loading.set(true);
    this.api.adminTickets(this.filter()).subscribe({
      next: (r) => {
        this.tickets.set(r.data);
        this.openCount.set(r.open_count);
        this.loading.set(false);
      },
      error: () => this.loading.set(false),
    });
  }
}
