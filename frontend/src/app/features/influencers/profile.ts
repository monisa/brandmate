import { CurrencyPipe, DatePipe } from '@angular/common';
import { Component, effect, inject, input, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { AuthService } from '../../core/auth/auth.service';
import { Influencer } from '../../core/models';
import { Api } from '../../core/services/api';
import { MetricPipe } from '../../shared/pipes/metric.pipe';
import { ShortlistPicker } from '../shortlists/shortlist-picker';
import { CompareSelection } from './compare-selection';
import { PLATFORM_LABELS, coverOf, locationOf, sourceLabel } from './labels';
import { InfluencerAvatar } from '../../shared/components/influencer-avatar';
import { PlatformIcon } from '../../shared/components/platform-icon';
import { TPipe } from '../../core/i18n/t.pipe';

@Component({
  selector: 'app-influencer-profile',
  imports: [TPipe, RouterLink, CurrencyPipe, DatePipe, MetricPipe, ShortlistPicker, InfluencerAvatar, PlatformIcon],
  template: `
    <p class="crumbs"><a routerLink="/influencers">{{ 'Find influencers' | t }}</a> / {{ influencer()?.name ?? ('Profile' | t) }}</p>

    @if (loading()) {
      <p class="muted" role="status">{{ 'Loading profile…' | t }}</p>
    } @else if (!influencer()) {
      <div class="card empty">
        <p><strong>{{ 'This influencer could not be found.' | t }}</strong></p>
        <p class="muted">{{ 'The profile may have been removed.' | t }}</p>
        <a class="btn" routerLink="/influencers">{{ 'Back to search' | t }}</a>
      </div>
    } @else {
      @let i = influencer()!;
      <header class="profile-hero">
        <div class="profile-hero__cover" [style.background]="cover(i.category)"></div>
        <div class="profile-hero__body">
          <app-influencer-avatar class="profile-hero__photo" [name]="i.name" [src]="i.avatar_url" [platform]="i.platform" size="xl" />
          <div class="profile-hero__main">
            <h1>
              {{ i.name }}
              @if (i.is_verified === true) {
                <span class="tick tick--lg" [title]="'Verified' | t">
                  <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2l2.4 1.8 3-.2.9 2.8 2.5 1.7-1 2.9 1 2.9-2.5 1.7-.9 2.8-3-.2L12 22l-2.4-1.8-3 .2-.9-2.8-2.5-1.7 1-2.9-1-2.9 2.5-1.7.9-2.8 3 .2z" /><path d="m8.5 12.2 2.3 2.3 4.7-4.9" class="tick__mark" /></svg>
                  <span class="visually-hidden">{{ 'Verified' | t }}</span>
                </span>
              }
            </h1>
            <p class="profile-head__meta">
              <span class="chip chip--static chip--{{ i.platform }}"><app-platform-icon [platform]="i.platform" /> {{ platformLabels[i.platform] }}</span>
              <span class="muted">&#64;{{ i.username }}</span>
              @if (i.category) {
                <span class="tag">{{ i.category | t }}</span>
              }
              @if (location(i); as place) {
                <span class="muted">{{ place }}</span>
              }
              @if (i.is_verified === null) {
                <span class="muted">{{ 'Verification status unavailable' | t }}</span>
              }
            </p>
            <ul class="profile-hero__counts">
              <li><strong [class.na]="i.followers === null">{{ i.followers | metric }}</strong> {{ 'followers' | t }}</li>
              @if (i.platform !== 'youtube') {
                <li><strong [class.na]="i.following === null">{{ i.following | metric }}</strong> {{ 'following' | t }}</li>
              }
              <li><strong [class.na]="i.engagement_rate === null">{{ i.engagement_rate | metric: 'percent' }}</strong> {{ 'engagement' | t }}</li>
            </ul>
            @if (i.bio) {
              <p class="profile-hero__bio">{{ i.bio }}</p>
            }
          </div>
        </div>
        <div class="profile-head__actions">
          <button class="btn" type="button" (click)="compare.toggle(i.id)" [disabled]="compare.full() && !compare.has(i.id)"
                  [attr.aria-pressed]="compare.has(i.id)">
            {{ (compare.has(i.id) ? 'Remove from comparison' : 'Add to comparison') | t }}
          </button>
          @if (compare.count() >= 2) {
            <a class="btn btn--primary" routerLink="/influencers/compare" [queryParams]="{ ids: compare.ids().join(',') }">
              {{ 'Compare {count}' | t: { count: compare.count() } }}
            </a>
          }
          @if (i.profile_url) {
            <a class="btn" [href]="i.profile_url" target="_blank" rel="noopener noreferrer">
              {{ i.data_source === 'sample' ? ('Open sample link' | t) : ('View on {platform}' | t: { platform: platformLabels[i.platform] }) }}
            </a>
          }
        </div>
      </header>

      @if (i.data_source === 'sample') {
        <p class="alert alert--info">{{ 'This is a sample influencer for trying the product. The person and numbers are fictional.' | t }}</p>
      }

      <section class="facts" [attr.aria-label]="'Audience and engagement' | t">
        <div class="fact"><span class="fact__label">{{ 'Followers' | t }}</span><span class="fact__value" [class.na]="i.followers === null">{{ i.followers | metric }}</span></div>
        <div class="fact"><span class="fact__label">{{ 'Engagement rate' | t }}</span><span class="fact__value" [class.na]="i.engagement_rate === null">{{ i.engagement_rate | metric: 'percent' }}</span></div>
        <div class="fact"><span class="fact__label">{{ 'Avg. likes' | t }}</span><span class="fact__value" [class.na]="i.avg_likes === null">{{ i.avg_likes | metric }}</span></div>
        <div class="fact"><span class="fact__label">{{ 'Avg. comments' | t }}</span><span class="fact__value" [class.na]="i.avg_comments === null">{{ i.avg_comments | metric }}</span></div>
        <div class="fact"><span class="fact__label">{{ 'Avg. views' | t }}</span><span class="fact__value" [class.na]="i.avg_views === null">{{ i.avg_views | metric }}</span></div>
      </section>

      <div class="columns">
        <section class="card">
          <h2>{{ 'Details' | t }}</h2>
          <dl class="details">
            <dt>{{ 'Category' | t }}</dt><dd>{{ (i.category ?? unavailable) | t }}</dd>
            <dt>{{ 'Location' | t }}</dt><dd>{{ (location(i) || unavailable) | t }}</dd>
            <dt>{{ 'Language' | t }}</dt><dd>{{ (i.language ?? unavailable) | t }}</dd>
            <dt>{{ 'Following' | t }}</dt><dd>{{ i.following | metric: 'full' }}</dd>
            <dt>{{ 'Exact followers' | t }}</dt><dd>{{ i.followers | metric: 'full' }}</dd>
          </dl>
        </section>

        <section class="card">
          <h2>{{ 'About this data' | t }}</h2>
          <dl class="details">
            <dt>{{ 'Source' | t }}</dt><dd>{{ source(i.data_source) | t }}</dd>
            <dt>{{ 'Provider' | t }}</dt><dd>{{ i.provider | t }}</dd>
            <dt>{{ 'Last updated' | t }}</dt><dd>{{ i.last_updated_at ? (i.last_updated_at | date: 'medium') : (unavailable | t) }}</dd>
            <dt>{{ 'Sync status' | t }}</dt><dd>{{ i.sync_status | t }}</dd>
          </dl>
        </section>
      </div>

      @if (i.data_source === 'self_reported') {
        <p class="alert alert--info">
          {{ 'This creator entered these numbers themselves. They have not been verified against {platform}.' | t: { platform: platformLabels[i.platform] } }}
        </p>
      }

      @if (i.packages?.length) {
        <section class="card">
          <h2>{{ 'Packages and rates' | t }}</h2>
          <ul class="packages">
            @for (pack of i.packages; track pack.id) {
              <li>
                <div>
                  <strong>{{ pack.title }}</strong>
                  <span class="platform platform--{{ pack.platform }}">{{ platformLabels[pack.platform] }}</span>
                  @if (pack.description) {
                    <p class="muted">{{ pack.description }}</p>
                  }
                </div>
                <div class="packages__price">
                  <strong>{{ pack.price | currency: pack.currency : 'symbol' : '1.0-0' }}</strong>
                  <span class="muted">{{ (pack.delivery_days === 1 ? 'Delivered in {n} day' : 'Delivered in {n} days') | t: { n: pack.delivery_days } }}</span>
                </div>
              </li>
            }
          </ul>
          <p class="field-hint">{{ 'Booking and payment through the platform are not available yet.' | t }}</p>
        </section>
      }

      @if (i.other_platforms?.length) {
        <section class="card">
          <h2>{{ 'Also on' | t }}</h2>
          <ul class="plain-list">
            @for (other of i.other_platforms; track other.id) {
              <li>
                <a [routerLink]="['/influencers', other.id]">
                  <span class="platform platform--{{ other.platform }}">{{ platformLabels[other.platform] }}</span>
                  &#64;{{ other.username }}
                </a>
                <span class="muted">{{ '{count} followers' | t: { count: (other.followers | metric) } }}</span>
              </li>
            }
          </ul>
        </section>
      }

      @if (canShortlist) {
        <section class="card">
          <h2>{{ 'Save to a shortlist' | t }}</h2>
          <app-shortlist-picker [influencerIds]="[i.id]" />
        </section>
      }
    }
  `,
})
export class InfluencerProfile {
  private readonly api = inject(Api);
  protected readonly compare = inject(CompareSelection);

  /** Route parameter, bound by the router. */
  readonly id = input.required<string>();

  protected readonly canShortlist = inject(AuthService).can('shortlist.manage');
  protected readonly platformLabels = PLATFORM_LABELS;
  protected readonly cover = coverOf;
  protected readonly location = locationOf;
  protected readonly source = sourceLabel;
  protected readonly unavailable = 'Data unavailable';

  protected readonly influencer = signal<Influencer | null>(null);
  protected readonly loading = signal(true);

  constructor() {
    // Reloads when moving between a creator's platforms (same component, new id).
    effect((onCleanup) => {
      const id = Number(this.id());
      this.loading.set(true);
      const request = this.api.influencer(id).subscribe({
        next: (influencer) => {
          this.influencer.set(influencer);
          this.loading.set(false);
        },
        error: () => {
          this.influencer.set(null);
          this.loading.set(false);
        },
      });
      onCleanup(() => request.unsubscribe());
    });
  }
}
