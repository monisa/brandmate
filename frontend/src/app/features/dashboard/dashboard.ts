import { Component, OnInit, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { AuthService } from '../../core/auth/auth.service';
import { Influencer } from '../../core/models';
import { Api } from '../../core/services/api';
import { TPipe } from '../../core/i18n/t.pipe';
import { InfluencerAvatar } from '../../shared/components/influencer-avatar';
import { NotificationsCard } from '../../shared/components/notifications-card';
import { MetricPipe } from '../../shared/pipes/metric.pipe';

@Component({
  selector: 'app-dashboard',
  imports: [TPipe, RouterLink, InfluencerAvatar, MetricPipe, NotificationsCard],
  template: `
    <header class="hero">
      <div>
        <p class="hero__eyebrow">{{ user()?.company?.name ? ('{name} workspace' | t: { name: user()!.company!.name }) : (appTagline | t) }}</p>
        <h1>{{ 'Hello, {name}' | t: { name: firstName } }}</h1>
        <p class="hero__lead">{{ 'Discover creators, compare them side by side and keep your favourites in shortlists.' | t }}</p>
      </div>
      @if (user()?.company && auth.can('influencer.search') && auth.companyApproved()) {
        <a class="btn btn--light" routerLink="/influencers">{{ 'Find influencers' | t }}</a>
      }
    </header>

    @if (user()?.company; as company) {
      @if (company.approval_status === 'pending') {
        <section class="card approval approval--pending" role="status">
          <h2>{{ 'Your company is waiting for approval' | t }}</h2>
          <p>{{ 'The BrandMate team reviews every new brand before it can search creators, build shortlists and send requests. You can set up your team and company details in the meantime. Everything unlocks as soon as you are approved.' | t }}</p>
        </section>
      } @else if (company.approval_status === 'rejected') {
        <section class="card approval approval--rejected" role="alert">
          <h2>{{ 'Your company was not approved' | t }}</h2>
          @if (company.review_note) {
            <p>{{ company.review_note }}</p>
          }
          <p class="muted">{{ 'If you think this is a mistake, contact the BrandMate team.' | t }}</p>
        </section>
      }

      <app-notifications-card />

      @if (auth.can('influencer.search') && auth.companyApproved() && creators().length) {
        <section class="card stories-card">
          <div class="card__head">
            <h2>{{ 'Popular creators' | t }}</h2>
            <a routerLink="/influencers">{{ 'See all' | t }}</a>
          </div>
          <ul class="stories">
            @for (c of creators(); track c.id) {
              <li>
                <a class="story" [routerLink]="['/influencers', c.id]">
                  <app-influencer-avatar [name]="c.name" [src]="c.avatar_url" [platform]="c.platform" size="lg" />
                  <span class="story__name">{{ c.name }}</span>
                  <span class="story__count">{{ c.followers | metric }}</span>
                </a>
              </li>
            }
          </ul>
        </section>
      }

      <section class="facts" [attr.aria-label]="'Workspace summary' | t">
        <div class="fact">
          <span class="fact__label">{{ 'Team members' | t }}</span>
          <span class="fact__value">{{ memberCount() ?? '–' }}</span>
        </div>
        <div class="fact">
          <span class="fact__label">{{ 'Your role' | t }}</span>
          <span class="fact__value fact__value--text">{{ user()?.role?.name | t }}</span>
        </div>
      </section>

      <section class="card">
        <h2>{{ 'Set up your workspace' | t }}</h2>
        <ol class="steps">
          <li class="is-done">
            <span>{{ 'Confirm your email' | t }}</span>
            <span class="badge badge--ok">{{ 'Done' | t }}</span>
          </li>
          <li [class.is-done]="(memberCount() ?? 0) > 1">
            <span>{{ 'Invite the people you work with' | t }}</span>
            @if (auth.can('team.manage')) {
              <a routerLink="/team">{{ 'Invite team members' | t }}</a>
            } @else {
              <span class="muted">{{ 'Your company admin does this' | t }}</span>
            }
          </li>
          <li>
            <span>{{ 'Add your company details' | t }}</span>
            <a routerLink="/settings/company">{{ 'Open company settings' | t }}</a>
          </li>
        </ol>
      </section>
    } @else {
      <section class="card">
        <h2>{{ 'Platform administration' | t }}</h2>
        <p>{{ 'Your account is not part of a company workspace. Manage companies and users from the admin area.' | t }}</p>
        <a class="btn btn--primary" routerLink="/admin">{{ 'Open platform admin' | t }}</a>
      </section>
    }
  `,
})
export class Dashboard implements OnInit {
  protected readonly auth = inject(AuthService);
  private readonly api = inject(Api);

  protected readonly user = this.auth.user;
  protected readonly firstName = (this.user()?.name ?? '').split(' ')[0];
  protected readonly appTagline = 'Your workspace';
  protected readonly memberCount = signal<number | null>(null);
  protected readonly creators = signal<Influencer[]>([]);

  ngOnInit(): void {
    if (this.user()?.company) {
      this.api.members().subscribe({
        next: (res) => this.memberCount.set(res.meta.total),
        error: () => this.memberCount.set(null),
      });
      if (this.auth.can('influencer.search') && this.auth.companyApproved()) {
        // Most-followed creators, shown like a stories row. Purely a shortcut: no ranking claim.
        this.api.influencers({}).subscribe({
          next: (page) => this.creators.set(page.data.slice(0, 10)),
          error: () => this.creators.set([]),
        });
      }
    }
  }
}
