import { Component, computed, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { messageFrom } from '../../core/forms';
import { Influencer } from '../../core/models';
import { Api } from '../../core/services/api';
import { MetricPipe } from '../../shared/pipes/metric.pipe';
import { COMPARE_LIMIT, CompareSelection } from './compare-selection';
import { PLATFORM_LABELS, locationOf } from './labels';
import { TPipe } from '../../core/i18n/t.pipe';
import { InfluencerAvatar } from '../../shared/components/influencer-avatar';

type NumericKey = 'followers' | 'engagement_rate' | 'avg_likes' | 'avg_comments' | 'avg_views';

const NUMERIC_ROWS: { key: NumericKey; label: string; kind: 'compact' | 'percent' }[] = [
  { key: 'followers', label: 'Followers', kind: 'compact' },
  { key: 'engagement_rate', label: 'Engagement rate', kind: 'percent' },
  { key: 'avg_likes', label: 'Average likes', kind: 'compact' },
  { key: 'avg_comments', label: 'Average comments', kind: 'compact' },
  { key: 'avg_views', label: 'Average views', kind: 'compact' },
];

@Component({
  selector: 'app-influencer-compare',
  imports: [TPipe, RouterLink, MetricPipe, InfluencerAvatar],
  template: `
    <p class="crumbs"><a routerLink="/influencers">{{ 'Find influencers' | t }}</a> / {{ 'Compare' | t }}</p>
    <header class="page-head">
      <h1>{{ 'Compare influencers' | t }}</h1>
      <p class="muted">{{ 'Side by side, up to {limit}. Bars show each value relative to the largest in its row.' | t: { limit: limit } }}</p>
    </header>

    @if (loading()) {
      <p class="muted" role="status">{{ 'Loading comparison…' | t }}</p>
    } @else if (error()) {
      <p class="alert alert--error" role="alert">{{ error() | t }}</p>
    } @else if (influencers().length === 0) {
      <div class="card empty">
        <p><strong>{{ 'Nothing to compare yet.' | t }}</strong></p>
        <p class="muted">{{ 'Tick two or more influencers in the search results, then choose Compare.' | t }}</p>
        <a class="btn btn--primary" routerLink="/influencers">{{ 'Find influencers' | t }}</a>
      </div>
    } @else {
      <section class="card">
        <div class="table-wrap">
          <table class="compare">
            <thead>
              <tr>
                <td></td>
                @for (i of influencers(); track i.id) {
                  <th scope="col">
                    <app-influencer-avatar class="compare__photo" [name]="i.name" [src]="i.avatar_url" [platform]="i.platform" size="lg" />
                    <a [routerLink]="['/influencers', i.id]">{{ i.name }}</a>
                    <span class="person__handle">&#64;{{ i.username }}</span>
                    <button class="btn btn--quiet compare__remove" type="button" (click)="remove(i.id)"
                            [attr.aria-label]="'Remove {name} from comparison' | t: { name: i.name }">{{ 'Remove' | t }}</button>
                  </th>
                }
              </tr>
            </thead>
            <tbody>
              <tr>
                <th scope="row">{{ 'Platform' | t }}</th>
                @for (i of influencers(); track i.id) {
                  <td><span class="platform platform--{{ i.platform }}">{{ platformLabels[i.platform] }}</span></td>
                }
              </tr>
              @for (row of rows; track row.key) {
                <tr>
                  <th scope="row">{{ row.label | t }}</th>
                  @for (i of influencers(); track i.id) {
                    <td [class.na]="i[row.key] === null">
                      {{ i[row.key] | metric: row.kind }}
                      @if (i[row.key] !== null) {
                        <span class="bar" aria-hidden="true"><span [style.width.%]="share(row.key, i[row.key])"></span></span>
                      }
                    </td>
                  }
                </tr>
              }
              <tr>
                <th scope="row">{{ 'Category' | t }}</th>
                @for (i of influencers(); track i.id) {
                  <td [class.na]="!i.category">{{ (i.category ?? unavailable) | t }}</td>
                }
              </tr>
              <tr>
                <th scope="row">{{ 'Location' | t }}</th>
                @for (i of influencers(); track i.id) {
                  <td [class.na]="!location(i)">{{ (location(i) || unavailable) | t }}</td>
                }
              </tr>
              <tr>
                <th scope="row">{{ 'Verified' | t }}</th>
                @for (i of influencers(); track i.id) {
                  <td [class.na]="i.is_verified === null">{{ (i.is_verified === null ? unavailable : i.is_verified ? 'Yes' : 'No') | t }}</td>
                }
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    }
  `,
})
export class InfluencerCompare {
  private readonly api = inject(Api);
  private readonly router = inject(Router);
  private readonly route = inject(ActivatedRoute);
  private readonly selection = inject(CompareSelection);

  protected readonly limit = COMPARE_LIMIT;
  protected readonly rows = NUMERIC_ROWS;
  protected readonly platformLabels = PLATFORM_LABELS;
  protected readonly location = locationOf;
  protected readonly unavailable = 'Data unavailable';

  protected readonly influencers = signal<Influencer[]>([]);
  protected readonly loading = signal(true);
  protected readonly error = signal('');

  /** Largest known value per metric, for the relative bars. */
  private readonly maxima = computed(() => {
    const result = {} as Record<NumericKey, number>;
    for (const row of NUMERIC_ROWS) {
      result[row.key] = Math.max(0, ...this.influencers().map((i) => i[row.key] ?? 0));
    }
    return result;
  });

  constructor() {
    this.route.queryParamMap.pipe(takeUntilDestroyed()).subscribe((params) => {
      const ids = [...new Set((params.get('ids') ?? '').split(',').map(Number).filter((n) => Number.isInteger(n) && n > 0))].slice(0, COMPARE_LIMIT);
      this.selection.set(ids);
      this.load(ids);
    });
  }

  protected share(key: NumericKey, value: number | null): number {
    const max = this.maxima()[key];
    return max > 0 && value !== null ? Math.max(2, (value / max) * 100) : 0;
  }

  protected remove(id: number): void {
    const ids = this.influencers().map((i) => i.id).filter((other) => other !== id);
    void this.router.navigate([], { relativeTo: this.route, queryParams: ids.length ? { ids: ids.join(',') } : {} });
  }

  private load(ids: number[]): void {
    this.error.set('');
    if (ids.length === 0) {
      this.influencers.set([]);
      this.loading.set(false);
      return;
    }
    this.loading.set(true);
    this.api.compare(ids).subscribe({
      next: (influencers) => {
        this.influencers.set(influencers);
        this.loading.set(false);
      },
      error: (err: unknown) => {
        this.error.set(messageFrom(err, 'The comparison could not be loaded.'));
        this.loading.set(false);
      },
    });
  }
}
