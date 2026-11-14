import { HttpErrorResponse } from '@angular/common/http';
import { Injectable, inject, signal } from '@angular/core';
import { Title } from '@angular/platform-browser';
import { SwPush } from '@angular/service-worker';
import { take } from 'rxjs';
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
  private readonly swPush = inject(SwPush);
  private flashTimer: ReturnType<typeof setInterval> | null = null;
  private flashing = false;
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
    this.baseTitle = this.title.getTitle().replace(/^\(\d+\) /, '');
    if (typeof document !== 'undefined') {
      document.addEventListener('visibilitychange', this.onVisible);
      window.addEventListener('focus', this.onVisible);
    }
    this.registerPush(false);
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
    if (typeof document !== 'undefined') {
      document.removeEventListener('visibilitychange', this.onVisible);
      window.removeEventListener('focus', this.onVisible);
    }
    this.forgetPush();
  }

  private readonly onVisible = (): void => {
    if (!document.hidden) {
      this.stopFlash();
      this.showTitle(this.unread());
    }
  };

  /** Registers this browser for push messages, which arrive even when the site is closed. */
  private registerPush(askFirst: boolean): void {
    if (!this.swPush.isEnabled || typeof Notification === 'undefined' || (!askFirst && Notification.permission !== 'granted')) {
      return;
    }
    this.api.pushKey().subscribe({
      next: ({ public_key }) => {
        if (!public_key) {
          return;
        }
        this.swPush
          .requestSubscription({ serverPublicKey: public_key })
          .then((sub) => this.api.pushSubscribe(sub.toJSON()).subscribe({ error: () => undefined }))
          .catch(() => undefined);
      },
      error: () => undefined,
    });
  }

  /** On sign-out this browser must stop receiving that person's messages. */
  private forgetPush(): void {
    if (!this.swPush.isEnabled) {
      return;
    }
    this.swPush.subscription.pipe(take(1)).subscribe((sub) => {
      if (sub) {
        this.api.pushUnsubscribe(sub.endpoint).subscribe({ error: () => undefined });
      }
    });
  }

  private startFlash(count: number): void {
    if (this.flashTimer !== null) {
      return;
    }
    this.flashing = true;
    this.flashTimer = setInterval(() => {
      this.title.setTitle(this.flashing ? `💬 ${count > 1 ? count + ' new messages' : 'New message'}` : `(${count}) ${this.baseTitle}`);
      this.flashing = !this.flashing;
    }, 1_000);
  }

  private stopFlash(): void {
    if (this.flashTimer !== null) {
      clearInterval(this.flashTimer);
      this.flashTimer = null;
    }
  }

  /** 'default' means the browser has not been asked yet; 'unsupported' where there is no Notification API. */
  readonly desktopPermission = signal<NotificationPermission | 'unsupported'>(
    typeof Notification === 'undefined' ? 'unsupported' : Notification.permission,
  );

  /** Must be called from a click: browsers only show the permission prompt after a user gesture. */
  enableDesktop(): void {
    if (typeof Notification === 'undefined') {
      return;
    }
    void Notification.requestPermission().then((p) => {
      this.desktopPermission.set(p);
      if (p === 'granted') {
        this.registerPush(true);
      }
    });
  }

  private desktopNotify(note: { id: number; name: string; body: string }): void {
    if (typeof Notification === 'undefined' || Notification.permission !== 'granted') {
      return;
    }
    try {
      const popup = new Notification(note.name, { body: note.body, icon: '/icon-192.png', tag: 'brandmate-chat' });
      popup.onclick = () => {
        window.focus();
        void this.router.navigate(['/messages'], { queryParams: { c: note.id } });
        popup.close();
      };
    } catch {
      // Some mobile browsers only allow notifications through a service worker; the in-app pop-up still works there.
    }
  }

  dismiss(): void {
    if (this.toastTimer !== null) {
      clearTimeout(this.toastTimer);
      this.toastTimer = null;
    }
    this.toast.set(null);
  }

  private showTitle(count: number): void {
    this.stopFlash();
    this.title.setTitle(count > 0 ? `(${count}) ${this.baseTitle}` : this.baseTitle);
    // A flashing tab title draws the eye while the tab is in the background, like a mail app.
    if (count > 0 && typeof document !== 'undefined' && document.hidden) {
      this.startFlash(count);
    }
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
        if (this.seen >= 0 && res.unread_total > this.seen) {
          const latest = res.data
            .filter((c) => c.unread_count > 0)
            .sort((a, b) => (b.last_message_at ?? '').localeCompare(a.last_message_at ?? ''))[0];
          if (latest) {
            const note = { id: latest.id, name: latest.counterpart.name, body: latest.last_message?.body ?? '' };
            if (typeof document !== 'undefined' && document.hidden) {
              this.desktopNotify(note);
            } else if (!this.router.url.startsWith('/messages')) {
              this.toast.set(note);
              this.toastTimer = setTimeout(() => this.toast.set(null), 8_000);
            }
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
