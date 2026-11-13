import { DatePipe } from '@angular/common';
import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { AuthService } from '../../core/auth/auth.service';
import { messageFrom } from '../../core/forms';
import { CreatorDashboard, DashboardAction } from '../../core/models';
import { Api } from '../../core/services/api';
import { I18n } from '../../core/i18n/i18n';
import { TPipe } from '../../core/i18n/t.pipe';
import { ApprovalBadge } from '../../shared/components/approval-badge';
import { NotificationsCard } from '../../shared/components/notifications-card';
import { MetricPipe } from '../../shared/pipes/metric.pipe';

/** A creator's home: who looked at their profile, what needs an answer, and what to do next. */
@Component({
  selector: 'app-creator-dashboard',
  imports: [TPipe, RouterLink, DatePipe, MetricPipe, ApprovalBadge, NotificationsCard],
  template: `
    <header class="hero hero--creator">
      <div>
        <p class="hero__eyebrow">{{ 'Creator dashboard' | t }}</p>
        <h1>{{ 'Hello, {name}' | t: { name: firstName } }}</h1>
        @if (data(); as d) {
          <p class="hero__lead">
            @if (d.visible_to_brands) {
              {{ 'Brands can find you right now.' | t }}
            } @else {
              {{ 'Brands cannot see your profile yet. The to-do list below shows why.' | t }}
            }
          </p>
        }
      </div>
      <a class="btn btn--light" routerLink="/creator/profile">{{ 'Edit my profile' | t }}</a>
    </header>

    @if (error()) {
      <p class="alert alert--error" role="alert">{{ error() | t }}</p>
      <button class="btn" type="button" (click)="load()">{{ 'Try again' | t }}</button>
    } @else if (loading()) {
      <p class="muted" role="status">{{ 'Loading your dashboard…' | t }}</p>
    } @else if (data(); as d) {
      <section class="facts" [attr.aria-label]="'Your numbers' | t">
        <div class="fact">
          <span class="fact__label">{{ 'Profile visits, last 7 days' | t }}</span>
          <span class="fact__value">{{ d.views.last_7_days | metric: 'full' }}</span>
        </div>
        <div class="fact">
          <span class="fact__label">{{ 'Profile visits, last 30 days' | t }}</span>
          <span class="fact__value">{{ d.views.last_30_days | metric: 'full' }}</span>
        </div>
        <div class="fact">
          <span class="fact__label">{{ 'Brands that looked, 30 days' | t }}</span>
          <span class="fact__value">{{ d.views.brands_30_days | metric: 'full' }}</span>
        </div>
        <a class="fact fact--link" routerLink="/creator/requests">
          <span class="fact__label">{{ 'Requests waiting' | t }}</span>
          <span class="fact__value">{{ d.requests.pending | metric: 'full' }}</span>
        </a>
      </section>

      <div class="columns">
        <section class="card" aria-labelledby="todo-title">
          <h2 id="todo-title">{{ 'To do' | t }}</h2>
          @if (d.actions.length === 0) {
            <p class="todo-done">{{ 'You are all set. Nothing needs your attention right now.' | t }}</p>
          } @else {
            <ul class="todo">
              @for (action of d.actions; track action.type) {
                <li class="todo__item" [class.todo__item--warn]="action.type === 'approval_rejected' || action.type === 'answer_requests'">
                  <div>
                    <strong>{{ title(action) }}</strong>
                    @if (detail(action); as extra) {
                      <p class="muted">{{ extra }}</p>
                    }
                    @if (action.type === 'complete_profile') {
                      <ul class="todo__list">
                        @for (item of action.items; track item) {
                          <li>{{ item | t }}</li>
                        }
                      </ul>
                    }
                  </div>
                  @if (link(action); as to) {
                    <a class="btn" [class.btn--primary]="action.type === 'answer_requests'" [routerLink]="to">{{ buttonLabel(action) }}</a>
                  }
                </li>
              }
            </ul>
          }
        </section>

        <app-notifications-card />
      </div>

      <div class="columns">
        <section class="card" aria-labelledby="visits-title">
          <div class="card__head">
            <h2 id="visits-title">{{ 'Profile visits' | t }}</h2>
            <span class="muted">{{ 'Last 14 days' | t }}</span>
          </div>
          @if (d.views.last_30_days === 0) {
            <p class="muted">{{ 'No visits yet. Brands can only find you once your profile is complete, listed and approved.' | t }}</p>
          }
          <ol class="bars" [attr.aria-label]="'Profile visits per day for the last 14 days' | t">
            @for (day of d.views.series; track day.date) {
              <li class="bars__col">
                <span class="bars__bar" [style.height.%]="barHeight(day.count)" [class.is-empty]="day.count === 0"
                      [attr.title]="(day.date | date: 'MMM d') + ': ' + day.count"></span>
                <span class="bars__count">{{ day.count }}</span>
                <span class="bars__day">{{ day.date | date: 'd' }}</span>
              </li>
            }
          </ol>
        </section>

        <section class="card" aria-labelledby="visitors-title">
          <h2 id="visitors-title">{{ 'Recent visitors' | t }}</h2>
          @if (d.recent_visitors.length === 0) {
            <p class="muted">{{ 'Nobody has opened your profile in the last 30 days.' | t }}</p>
          } @else {
            <ul class="plain-list">
              @for (visitor of d.recent_visitors; track visitor.at) {
                <li>
                  <strong>{{ visitor.company ?? ('A brand' | t) }}</strong>
                  <span class="muted">{{ visitor.at | date: 'MMM d, h:mm a' }}</span>
                </li>
              }
            </ul>
            <p class="field-hint">{{ 'Only the company is shown, not the person.' | t }}</p>
          }
        </section>
      </div>

      <section class="card card--note">
        <p class="muted">
          {{ 'Profile status' | t }}: <app-approval-badge [status]="d.approval_status" />
          @if (d.review_note) {
            · {{ d.review_note }}
          }
        </p>
      </section>
    }
  `,
})
export class CreatorDashboardPage implements OnInit {
  private readonly api = inject(Api);
  private readonly i18n = inject(I18n);

  protected readonly firstName = (inject(AuthService).user()?.name ?? '').split(' ')[0];
  protected readonly data = signal<CreatorDashboard | null>(null);
  protected readonly loading = signal(true);
  protected readonly error = signal('');

  /** Tallest bar in the chart, so the others scale to it (at least 1, to avoid dividing by zero). */
  private readonly peak = computed(() => Math.max(1, ...(this.data()?.views.series.map((d) => d.count) ?? [1])));

  ngOnInit(): void {
    this.load();
  }

  protected load(): void {
    this.loading.set(true);
    this.error.set('');
    this.api.creatorDashboard().subscribe({
      next: (data) => {
        this.data.set(data);
        this.loading.set(false);
      },
      error: (err: unknown) => {
        this.error.set(messageFrom(err, 'Your dashboard could not be loaded.'));
        this.loading.set(false);
      },
    });
  }

  protected barHeight(count: number): number {
    return count === 0 ? 4 : Math.max(8, Math.round((count / this.peak()) * 100));
  }

  protected title(action: DashboardAction): string {
    switch (action.type) {
      case 'answer_requests':
        return this.i18n.t(action.count === 1 ? '{count} request is waiting for your answer' : '{count} requests are waiting for your answer', { count: action.count });
      case 'awaiting_approval':
        return this.i18n.t('Your profile is waiting for approval');
      case 'approval_rejected':
        return this.i18n.t('Your profile was not approved');
      case 'complete_profile':
        return this.i18n.t('Finish your profile so brands can find you');
      case 'list_profile':
        return this.i18n.t('List your profile');
      case 'add_package':
        return this.i18n.t('Add a package');
      case 'add_bio':
        return this.i18n.t('Add a short bio');
    }
  }

  protected detail(action: DashboardAction): string | null {
    switch (action.type) {
      case 'answer_requests':
        return this.i18n.t('Brands are waiting. Accept or decline with a short note.');
      case 'awaiting_approval':
        return this.i18n.t('The BrandMate team reviews new creators before brands can see them.');
      case 'approval_rejected':
        return action.note;
      case 'list_profile':
        return this.i18n.t('Your profile is complete. Turn on visibility so brands can find you.');
      case 'add_package':
        return this.i18n.t('A package shows brands what you offer and your price.');
      case 'add_bio':
        return this.i18n.t('Tell brands what you make and who watches it.');
      default:
        return null;
    }
  }

  protected link(action: DashboardAction): string | null {
    return action.type === 'answer_requests' ? '/creator/requests' : action.type === 'awaiting_approval' ? null : '/creator/profile';
  }

  protected buttonLabel(action: DashboardAction): string {
    return this.i18n.t(action.type === 'answer_requests' ? 'Answer requests' : 'Open my profile');
  }
}
