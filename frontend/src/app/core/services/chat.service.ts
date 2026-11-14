import { HttpErrorResponse } from '@angular/common/http';
import { Injectable, inject, signal } from '@angular/core';
import { Title } from '@angular/platform-browser';
import { Router } from '@angular/router';
import { AuthService } from '../auth/auth.service';
import { Api } from './api';

/** Starts chats and keeps the unread count for the sidebar badge. */
@Injectable({ providedIn: 'root' })
export class ChatService {
  private readonly api = inject(Api);
  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);
  private readonly title = inject(Title);
  private timer: ReturnType<typeof setInterval> | null = null;
  private toastTimer: ReturnType<typeof setTimeout> | null = null;
  private baseTitle = '';
  private seen = -1;

  /** Messages waiting for the signed-in person. */
  readonly unread = signal(0);

  /** The newest message, shown as a pop-up like a mail app does. */
  readonly toast = signal<{ id: number; name: string; body: string } | null>(null);

  /** Begins polling once, and only for people who can use chat. Called from the app frame. */
  watch(): void {
    if (this.timer !== null) {
      return;
    }
    this.baseTitle = this.title.getTitle().replace(/^(d+) /, '');
    this.refresh();
    this.timer = setInterval(() => this.refresh(), 20_000);
  }

  stop(): void {
    if (this.timer !== null) {
      clearInterval(this.timer);
      this.timer = null;
    }
    this.unread.set(0);
    this.seen = -1;
    this.dismiss();
    this.showTitle(0);
  }

  dismiss(): void {
    if (this.toastTimer !== null) {
      clearTimeout(this.toastTimer);
      this.toastTimer = null;
    }
    this.toast.set(null);
  }

  private showTitle(count: number): void {
    this.title.setTitle(count > 0 ? `(${count}) ${this.baseTitle}` : this.baseTitle);
  }

  refresh(): void {
    if (!this.canChat()) {
      this.unread.set(0);
      return;
    }
    this.api.conversations().subscribe({
      next: (res) => {
        this.unread.set(res.unread_total);
        this.showTitle(res.unread_total);
        // A pop-up only when something new arrived since the last check, and not on the first load or inside the chat itself.
        if (this.seen >= 0 && res.unread_total > this.seen && !this.router.url.startsWith('/messages')) {
          const latest = res.data
            .filter((c) => c.unread_count > 0)
            .sort((a, b) => (b.last_message_at ?? '').localeCompare(a.last_message_at ?? ''))[0];
          if (latest) {
            this.toast.set({ id: latest.id, name: latest.counterpart.name, body: latest.last_message?.body ?? '' });
            this.toastTimer = setTimeout(() => this.toast.set(null), 8_000);
          }
        }
        this.seen = res.unread_total;
      },
      error: () => this.unread.set(0),
    });
  }

  /** Whether this person has a chat area at all (approved creators, approved brands with request permission). */
  canChat(): boolean {
    const user = this.auth.user();
    if (!user || user.is_super_admin) {
      return false;
    }
    return user.account_type === 'creator' || (this.auth.companyApproved() && this.auth.can('request.manage'));
  }

  /**
   * Opens the chat with someone, then goes to it. A creator is opened from the brand side and a
   * brand from the creator side. Returns an error message when it cannot be opened.
   */
  open(target: { creator_id: number } | { company_id: number }, onError: (message: string, code: string | null) => void): void {
    this.api.startChat(target).subscribe({
      next: (conversation) => void this.router.navigate(['/messages'], { queryParams: { c: conversation.id } }),
      error: (err: unknown) => {
        const body = err instanceof HttpErrorResponse ? (err.error as { message?: string; code?: string } | null) : null;
        onError(body?.message ?? 'The chat could not be opened.', body?.code ?? null);
      },
    });
  }
}
