import { HttpErrorResponse } from '@angular/common/http';
import { Injectable, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import { AuthService } from '../auth/auth.service';
import { Api } from './api';

/** Starts chats and keeps the unread count for the sidebar badge. */
@Injectable({ providedIn: 'root' })
export class ChatService {
  private readonly api = inject(Api);
  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);
  private timer: ReturnType<typeof setInterval> | null = null;

  /** Messages waiting for the signed-in person. */
  readonly unread = signal(0);

  /** Begins polling once, and only for people who can use chat. Called from the app frame. */
  watch(): void {
    if (this.timer !== null) {
      return;
    }
    this.refresh();
    this.timer = setInterval(() => this.refresh(), 60_000);
  }

  stop(): void {
    if (this.timer !== null) {
      clearInterval(this.timer);
      this.timer = null;
    }
    this.unread.set(0);
  }

  refresh(): void {
    if (!this.canChat()) {
      this.unread.set(0);
      return;
    }
    this.api.chatUnread().subscribe({
      next: (res) => this.unread.set(res.unread_total),
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
