import { DatePipe } from '@angular/common';
import { Component, OnInit, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import { AppNotification } from '../../core/models';
import { I18n } from '../../core/i18n/i18n';
import { TPipe } from '../../core/i18n/t.pipe';
import { Api } from '../../core/services/api';

/**
 * The signed-in person's latest notifications. The API sends a kind and a few values; the
 * wording lives here so it follows the chosen language.
 */
@Component({
  selector: 'app-notifications-card',
  imports: [TPipe, DatePipe],
  template: `
    <section class="card notifications" [attr.aria-label]="'Notifications' | t">
      <div class="card__head">
        <h2>
          {{ 'Notifications' | t }}
          @if (unread() > 0) {
            <span class="count-pill" [attr.aria-label]="'{count} unread' | t: { count: unread() }">{{ unread() }}</span>
          }
        </h2>
        @if (unread() > 0) {
          <button class="btn btn--quiet" type="button" (click)="readAll()">{{ 'Mark all as read' | t }}</button>
        }
      </div>

      @if (loading()) {
        <p class="muted" role="status">{{ 'Loading…' | t }}</p>
      } @else if (items().length === 0) {
        <p class="muted">{{ 'Nothing new. We will tell you here when something happens.' | t }}</p>
      } @else {
        <ul class="notifications__list">
          @for (item of items(); track item.id) {
            <li>
              <button type="button" class="notification" [class.is-unread]="!item.read" (click)="open(item)">
                <span class="notification__dot" aria-hidden="true"></span>
                <span class="notification__body">
                  <span class="notification__text">{{ text(item) }}</span>
                  @if (note(item); as extra) {
                    <span class="notification__note">{{ extra }}</span>
                  }
                  <span class="notification__time">{{ item.created_at | date: 'MMM d, h:mm a' }}</span>
                </span>
                @if (!item.read) {
                  <span class="visually-hidden">{{ 'Unread' | t }}</span>
                }
              </button>
            </li>
          }
        </ul>
      }
    </section>
  `,
})
export class NotificationsCard implements OnInit {
  private readonly api = inject(Api);
  private readonly router = inject(Router);
  private readonly i18n = inject(I18n);

  protected readonly items = signal<AppNotification[]>([]);
  protected readonly unread = signal(0);
  protected readonly loading = signal(true);

  ngOnInit(): void {
    this.api.notifications().subscribe({
      next: (res) => {
        this.items.set(res.data.slice(0, 8));
        this.unread.set(res.unread_count);
        this.loading.set(false);
      },
      error: () => this.loading.set(false),
    });
  }

  protected text(item: AppNotification): string {
    const m = item.meta;
    const p = {
      company: String(m['company'] ?? ''),
      creator: String(m['creator'] ?? ''),
      title: String(m['title'] ?? ''),
    };
    switch (item.kind) {
      case 'request.received':
        return this.i18n.t('{company} sent you a request: {title}', p);
      case 'request.answered':
        return this.i18n.t(m['decision'] === 'accepted' ? '{creator} accepted your request: {title}' : '{creator} declined your request: {title}', p);
      case 'request.withdrawn':
        return this.i18n.t('{company} withdrew their request: {title}', p);
      case 'creator.approved':
        return this.i18n.t('Your profile was approved. Brands can now find you.');
      case 'creator.rejected':
        return this.i18n.t('Your profile was not approved.');
      case 'company.approved':
        return this.i18n.t('Your company was approved. You can now search creators and send requests.');
      case 'company.rejected':
        return this.i18n.t('Your company was not approved.');
      default:
        return this.i18n.t('You have a new notification.');
    }
  }

  /** The reviewer's note, shown under a decision. */
  protected note(item: AppNotification): string | null {
    const note = item.meta['note'];
    return typeof note === 'string' && note ? note : null;
  }

  protected open(item: AppNotification): void {
    if (!item.read) {
      this.api.markNotificationRead(item.id).subscribe({ next: (res) => this.unread.set(res.unread_count) });
      this.items.update((list) => list.map((n) => (n.id === item.id ? { ...n, read: true } : n)));
    }
    if (item.link) {
      void this.router.navigateByUrl(item.link);
    }
  }

  protected readAll(): void {
    this.api.markAllNotificationsRead().subscribe({
      next: () => {
        this.unread.set(0);
        this.items.update((list) => list.map((n) => ({ ...n, read: true })));
      },
    });
  }
}
