import { DatePipe } from '@angular/common';
import { Component, ElementRef, OnDestroy, OnInit, effect, inject, signal, viewChild } from '@angular/core';
import { FormControl, ReactiveFormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { messageFrom } from '../../core/forms';
import { ChatMessage, ChatMessages, Conversation } from '../../core/models';
import { TPipe } from '../../core/i18n/t.pipe';
import { Api } from '../../core/services/api';
import { ChatService } from '../../core/services/chat.service';

/** Conversations between subscribed brands and creators. New messages are fetched every few seconds. */
@Component({
  selector: 'app-messages',
  imports: [TPipe, DatePipe, ReactiveFormsModule, RouterLink],
  template: `
    <header class="page-head">
      <h1>{{ 'Messages' | t }}</h1>
    </header>

    @if (chat.desktopPermission() === 'default') {
      <p class="alert alert--info">
        {{ 'Get a desktop notification when a new message arrives.' | t }}
        <button class="btn" type="button" (click)="chat.enableDesktop()">{{ 'Turn on notifications' | t }}</button>
      </p>
    } @else if (chat.desktopPermission() === 'denied') {
      <p class="muted">{{ 'Desktop notifications are blocked. Allow them in the browser’s site settings to get them.' | t }}</p>
    }

    @if (error()) {
      <p class="alert alert--error" role="alert">{{ error() | t }}</p>
    }

    <div class="chat">
      <aside class="chat__list card" [attr.aria-label]="'Conversations' | t">
        @if (conversations().length === 0) {
          <p class="muted">{{ 'No conversations yet. Open a profile and choose Message.' | t }}</p>
        }
        <ul>
          @for (c of conversations(); track c.id) {
            <li>
              <button type="button" class="chat__item" [class.is-active]="c.id === activeId()" [class.is-unread]="c.unread_count > 0" (click)="select(c.id)">
                <span class="avatar" aria-hidden="true">{{ c.counterpart.name.charAt(0).toUpperCase() }}</span>
                <span class="chat__who">
                  <strong>{{ c.counterpart.name }}</strong>
                  <span class="muted">{{ c.last_message?.from_me ? ('You: ' | t) : '' }}{{ c.last_message?.body ?? '' }}</span>
                </span>
                @if (c.last_message_at) { <time class="chat__time">{{ c.last_message_at | date: 'shortTime' }}</time> }
                @if (c.unread_count > 0) {
                  <span class="badge badge--count">{{ c.unread_count }}</span>
                }
              </button>
            </li>
          }
        </ul>
      </aside>

      <section class="chat__thread card" [attr.aria-label]="'Chat' | t">
        @if (activeId() === null) {
          <p class="muted">{{ 'Choose a conversation.' | t }}</p>
        } @else {
          <div #scroller class="chat__messages" role="log" aria-live="polite">
            @for (m of messages(); track m.id) {
              <div class="bubble" [class.bubble--me]="m.from_me">
                <p>{{ m.body }}</p>
                <time class="muted">{{ m.created_at | date: 'short' }}</time>
              </div>
            }
          </div>

          @if (canSend()) {
            <form class="chat__composer" (submit)="$event.preventDefault(); send()">
              <label class="visually-hidden" for="chat-body">{{ 'Message' | t }}</label>
              <textarea id="chat-body" rows="2" maxlength="2000" [formControl]="body" [placeholder]="'Write a message' | t"
                        (keydown.enter)="enter($event)"></textarea>
              <button class="btn btn--primary" type="submit" [disabled]="sending() || !body.value.trim()">{{ 'Send' | t }}</button>
            </form>
          } @else {
            <p class="alert alert--info">
              {{ reasonText() | t }}
              <a routerLink="/billing">{{ 'Subscription' | t }}</a>
            </p>
          }
        }
      </section>
    </div>
  `,
})
export class Messages implements OnInit, OnDestroy {
  private readonly api = inject(Api);
  protected readonly chat = inject(ChatService);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private timer: ReturnType<typeof setInterval> | null = null;
  private readonly scroller = viewChild<ElementRef<HTMLElement>>('scroller');

  protected readonly conversations = signal<Conversation[]>([]);
  protected readonly activeId = signal<number | null>(null);
  protected readonly messages = signal<ChatMessage[]>([]);
  protected readonly meta = signal<ChatMessages['meta']>({ can_send: true, reason: null });
  protected readonly error = signal('');
  protected readonly sending = signal(false);
  protected readonly body = new FormControl('', { nonNullable: true });

  constructor() {
    // Keeps the newest message in view.
    effect(() => {
      this.messages();
      queueMicrotask(() => {
        const el = this.scroller()?.nativeElement;
        if (el) {
          el.scrollTop = el.scrollHeight;
        }
      });
    });
  }

  protected canSend(): boolean {
    return this.meta().can_send;
  }

  protected reasonText(): string {
    return this.meta().reason === 'counterpart_not_subscribed'
      ? 'The other person has not subscribed yet, so messages are paused.'
      : 'Subscribe to send messages.';
  }

  ngOnInit(): void {
    this.loadList();
    this.route.queryParamMap.subscribe((params) => {
      const id = Number(params.get('c'));
      if (id && id !== this.activeId()) {
        this.open(id);
      }
    });
    this.timer = setInterval(() => this.poll(), 5000);
  }

  ngOnDestroy(): void {
    if (this.timer !== null) {
      clearInterval(this.timer);
    }
  }

  private loadList(): void {
    this.api.conversations().subscribe({
      next: (res) => this.conversations.set(res.data),
      error: (err: unknown) => this.error.set(messageFrom(err, 'Messages could not be loaded.')),
    });
  }

  protected select(id: number): void {
    void this.router.navigate([], { queryParams: { c: id }, queryParamsHandling: 'merge' });
    this.open(id);
  }

  private open(id: number): void {
    this.activeId.set(id);
    this.messages.set([]);
    this.error.set('');
    this.api.chatMessages(id).subscribe({
      next: (res) => {
        this.messages.set(res.data);
        this.meta.set(res.meta);
        this.chat.refresh();
        this.loadList();
      },
      error: (err: unknown) => this.error.set(messageFrom(err, 'The conversation could not be opened.')),
    });
  }

  /** Fetches only what arrived after the last message we hold. */
  private poll(): void {
    const id = this.activeId();
    if (id === null || document.hidden) {
      return;
    }
    const last = this.messages().at(-1)?.id ?? 0;
    this.api.chatMessages(id, last).subscribe({
      next: (res) => {
        this.meta.set(res.meta);
        if (res.data.length > 0) {
          this.messages.update((list) => [...list, ...res.data.filter((m) => !list.some((x) => x.id === m.id))]);
          this.chat.refresh();
          this.loadList();
        }
      },
      error: () => undefined,
    });
  }

  protected enter(event: Event): void {
    if (!(event as KeyboardEvent).shiftKey) {
      event.preventDefault();
      this.send();
    }
  }

  protected send(): void {
    const id = this.activeId();
    const text = this.body.value.trim();
    if (id === null || !text || this.sending()) {
      return;
    }
    this.sending.set(true);
    this.api.sendChatMessage(id, text).subscribe({
      next: (message) => {
        this.messages.update((list) => (list.some((m) => m.id === message.id) ? list : [...list, message]));
        this.body.setValue('');
        this.sending.set(false);
        this.loadList();
      },
      error: (err: unknown) => {
        this.error.set(messageFrom(err, 'The message could not be sent.'));
        this.sending.set(false);
        this.open(id);
      },
    });
  }
}
