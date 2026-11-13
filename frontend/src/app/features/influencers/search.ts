import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { NonNullableFormBuilder, ReactiveFormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { AuthService } from '../../core/auth/auth.service';
import { messageFrom } from '../../core/forms';
import { FilterOptions, Influencer, InfluencerQuery, Paginated } from '../../core/models';
import { Api } from '../../core/services/api';
import { MetricPipe } from '../../shared/pipes/metric.pipe';
import { ShortlistPicker } from '../shortlists/shortlist-picker';
import { COMPARE_LIMIT, CompareSelection } from './compare-selection';
import { PLATFORM_LABELS, countryName, coverOf, locationOf } from './labels';
import { TPipe } from '../../core/i18n/t.pipe';
import { InfluencerAvatar } from '../../shared/components/influencer-avatar';
import { PlatformIcon } from '../../shared/components/platform-icon';

const VIEW_KEY = 'brandmate.discover.view';
type View = 'grid' | 'list';

function storedView(): View {
  try {
    return localStorage.getItem(VIEW_KEY) === 'list' ? 'list' : 'grid';
  } catch {
    return 'grid';
  }
}

const SORTS = [
  { value: 'followers:desc', label: 'Most followers' },
  { value: 'followers:asc', label: 'Fewest followers' },
  { value: 'engagement_rate:desc', label: 'Highest engagement' },
  { value: 'avg_likes:desc', label: 'Most average likes' },
  { value: 'avg_views:desc', label: 'Most average views' },
  { value: 'name:asc', label: 'Name, A to Z' },
];

const FILTER_KEYS = [
  'q', 'platform', 'category', 'country', 'followers_min', 'followers_max', 'engagement_min', 'engagement_max', 'verified',
] as const;

/** Filters that live behind "More filters". */
const EXTRA_FILTERS = ['country', 'followers_min', 'followers_max', 'engagement_min', 'engagement_max', 'verified'] as const;

@Component({
  selector: 'app-influencer-search',
  imports: [TPipe, ReactiveFormsModule, RouterLink, MetricPipe, ShortlistPicker, InfluencerAvatar, PlatformIcon],
  template: `
    <header class="page-head discover-head">
      <div>
        <h1>{{ 'Find influencers' | t }}</h1>
        <p class="muted">{{ 'Search creators on Instagram, YouTube and Facebook.' | t }}</p>
      </div>
    </header>

    <form class="card discover" [formGroup]="form" (ngSubmit)="apply()">
      <div class="discover__search">
        <label class="visually-hidden" for="q">{{ 'Keyword' | t }}</label>
        <span class="discover__icon" aria-hidden="true">
          <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" /></svg>
        </span>
        <input id="q" type="search" formControlName="q" [placeholder]="'Name, username or topic' | t" />
        <button class="btn btn--primary" type="submit">{{ 'Search' | t }}</button>
      </div>

      <div class="chips" role="group" [attr.aria-label]="'Platform' | t">
        <button type="button" class="chip" [class.is-on]="!form.value.platform" [attr.aria-pressed]="!form.value.platform"
                (click)="pick('platform', '')">{{ 'All platforms' | t }}</button>
        @for (platform of options().platforms; track platform) {
          <button type="button" class="chip chip--{{ platform }}" [class.is-on]="form.value.platform === platform"
                  [attr.aria-pressed]="form.value.platform === platform" (click)="pick('platform', platform)">
            <app-platform-icon [platform]="platform" /> {{ platformLabels[platform] }}
          </button>
        }
      </div>

      @if (options().categories.length) {
        <div class="chips chips--scroll" role="group" [attr.aria-label]="'Category' | t">
          <button type="button" class="chip chip--soft" [class.is-on]="!form.value.category" [attr.aria-pressed]="!form.value.category"
                  (click)="pick('category', '')">{{ 'All categories' | t }}</button>
          @for (category of options().categories; track category) {
            <button type="button" class="chip chip--soft" [class.is-on]="form.value.category === category"
                    [attr.aria-pressed]="form.value.category === category" (click)="pick('category', category)">{{ category | t }}</button>
          }
        </div>
      }

      <button type="button" class="discover__more" (click)="moreFilters.set(!moreFilters())"
              [attr.aria-expanded]="moreFilters()" aria-controls="more-filters">
        {{ (moreFilters() ? 'Hide filters' : 'More filters') | t }}
        @if (activeExtraFilters() > 0) {
          <span class="count-pill">{{ activeExtraFilters() }}</span>
        }
      </button>

      <div class="filters" id="more-filters" [hidden]="!moreFilters()">
        <div class="field">
          <label for="country">{{ 'Country' | t }}</label>
          <select id="country" formControlName="country">
            <option value="">{{ 'Any country' | t }}</option>
            @for (country of options().countries; track country) {
              <option [value]="country">{{ countryName(country) }}</option>
            }
          </select>
        </div>
        <fieldset class="range">
          <legend>{{ 'Followers' | t }}</legend>
          <label class="visually-hidden" for="followers_min">{{ 'Minimum followers' | t }}</label>
          <input id="followers_min" type="number" min="0" step="1000" formControlName="followers_min" [placeholder]="'From' | t" />
          <span aria-hidden="true">{{ 'to' | t }}</span>
          <label class="visually-hidden" for="followers_max">{{ 'Maximum followers' | t }}</label>
          <input id="followers_max" type="number" min="0" step="1000" formControlName="followers_max" [placeholder]="'To' | t" />
        </fieldset>
        <fieldset class="range">
          <legend>{{ 'Engagement rate, %' | t }}</legend>
          <label class="visually-hidden" for="engagement_min">{{ 'Minimum engagement rate' | t }}</label>
          <input id="engagement_min" type="number" min="0" max="100" step="0.1" formControlName="engagement_min" [placeholder]="'From' | t" />
          <span aria-hidden="true">{{ 'to' | t }}</span>
          <label class="visually-hidden" for="engagement_max">{{ 'Maximum engagement rate' | t }}</label>
          <input id="engagement_max" type="number" min="0" max="100" step="0.1" formControlName="engagement_max" [placeholder]="'To' | t" />
        </fieldset>
        <div class="field">
          <label for="verified">{{ 'Verification' | t }}</label>
          <select id="verified" formControlName="verified">
            <option value="">{{ 'Any' | t }}</option>
            <option value="1">{{ 'Verified only' | t }}</option>
          </select>
        </div>
        <div class="filters__actions">
          <button class="btn btn--primary" type="submit">{{ 'Apply filters' | t }}</button>
          <button class="btn btn--quiet" type="button" (click)="reset()">{{ 'Reset filters' | t }}</button>
        </div>
      </div>
    </form>

    @if (compare.count() > 0) {
      <section class="selection" [attr.aria-label]="'Selected influencers' | t">
        <strong>{{ '{count} selected' | t: { count: compare.count() } }}</strong>
        <a class="btn btn--primary" routerLink="/influencers/compare" [queryParams]="{ ids: compare.ids().join(',') }"
           [class.is-disabled]="compare.count() < 2" [attr.aria-disabled]="compare.count() < 2">
          {{ 'Compare' | t }}
        </a>
        @if (canShortlist) {
          <app-shortlist-picker [influencerIds]="compare.ids()" />
        }
        <button class="btn btn--quiet" type="button" (click)="compare.clear()">{{ 'Clear selection' | t }}</button>
        @if (compare.full()) {
          <span class="muted">{{ 'You can compare up to {limit} at a time.' | t: { limit: limit } }}</span>
        }
      </section>
    }

    <section aria-live="polite">
      <div class="results-bar">
        <h2>
          @if (page(); as p) {
            {{ p.meta.total | metric: 'full' }} {{ (p.meta.total === 1 ? 'influencer' : 'influencers') | t }}
          } @else {
            {{ 'Results' | t }}
          }
        </h2>
        <div class="results-bar__tools">
          <div class="sort">
            <label for="sort">{{ 'Sort by' | t }}</label>
            <select id="sort" [value]="sortValue()" (change)="changeSort($any($event.target).value)">
              @for (sort of sorts; track sort.value) {
                <option [value]="sort.value" [selected]="sort.value === sortValue()">{{ sort.label | t }}</option>
              }
            </select>
          </div>
          <div class="segmented" role="group" [attr.aria-label]="'Layout' | t">
            <button type="button" [class.is-on]="view() === 'grid'" [attr.aria-pressed]="view() === 'grid'" (click)="setView('grid')">
              <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1.5" /><rect x="14" y="3" width="7" height="7" rx="1.5" /><rect x="3" y="14" width="7" height="7" rx="1.5" /><rect x="14" y="14" width="7" height="7" rx="1.5" /></svg>
              <span>{{ 'Cards' | t }}</span>
            </button>
            <button type="button" [class.is-on]="view() === 'list'" [attr.aria-pressed]="view() === 'list'" (click)="setView('list')">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 6h13M8 12h13M8 18h13" /><circle cx="4" cy="6" r="1" /><circle cx="4" cy="12" r="1" /><circle cx="4" cy="18" r="1" /></svg>
              <span>{{ 'Table' | t }}</span>
            </button>
          </div>
        </div>
      </div>

      @if (isSample()) {
        <p class="alert alert--info">{{ 'These are sample influencers for trying the product. They are fictional.' | t }}</p>
      }

      @if (loading()) {
        <p class="visually-hidden" role="status">{{ 'Searching…' | t }}</p>
        <div class="creator-grid" aria-hidden="true">
          @for (n of skeletons; track n) {
            <div class="creator-card creator-card--skeleton">
              <div class="creator-card__cover"></div>
              <span class="sk sk--avatar"></span>
              <span class="sk sk--line"></span>
              <span class="sk sk--line sk--short"></span>
              <span class="sk sk--stats"></span>
            </div>
          }
        </div>
      } @else if (error()) {
        <div class="card">
          <p class="alert alert--error" role="alert">{{ error() | t }}</p>
          <button class="btn" type="button" (click)="load()">{{ 'Try again' | t }}</button>
        </div>
      } @else if (results().length === 0) {
        <div class="card empty">
          <span class="empty__art" aria-hidden="true">
            <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5M8.5 11h5" /></svg>
          </span>
          <p><strong>{{ 'No influencers match these filters.' | t }}</strong></p>
          <p class="muted">{{ 'Widen the follower or engagement range, or remove a filter.' | t }}</p>
          <button class="btn" type="button" (click)="reset()">{{ 'Reset filters' | t }}</button>
        </div>
      } @else if (view() === 'grid') {
        <ul class="creator-grid">
          @for (influencer of results(); track influencer.id) {
            <li class="creator-card" [class.is-selected]="compare.has(influencer.id)">
              <div class="creator-card__cover" [style.background]="cover(influencer.category)">
                <span class="creator-card__platform">
                  <app-platform-icon [platform]="influencer.platform" /> {{ platformLabels[influencer.platform] }}
                </span>
                <label class="creator-card__select">
                  <input type="checkbox" [checked]="compare.has(influencer.id)"
                         [disabled]="compare.full() && !compare.has(influencer.id)"
                         (change)="compare.toggle(influencer.id)"
                         [attr.aria-label]="'Select {name} to compare' | t: { name: influencer.name }" />
                  <span aria-hidden="true">{{ (compare.has(influencer.id) ? 'Selected' : 'Select') | t }}</span>
                </label>
              </div>

              <a class="creator-card__photo" [routerLink]="['/influencers', influencer.id]" tabindex="-1" aria-hidden="true">
                <app-influencer-avatar [name]="influencer.name" [src]="influencer.avatar_url" [platform]="influencer.platform" size="xl" />
              </a>

              <div class="creator-card__body">
                <h3 class="creator-card__name">
                  <a [routerLink]="['/influencers', influencer.id]">{{ influencer.name }}</a>
                  @if (influencer.is_verified) {
                    <span class="tick" [title]="'Verified' | t">
                      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2l2.4 1.8 3-.2.9 2.8 2.5 1.7-1 2.9 1 2.9-2.5 1.7-.9 2.8-3-.2L12 22l-2.4-1.8-3 .2-.9-2.8-2.5-1.7 1-2.9-1-2.9 2.5-1.7.9-2.8 3 .2z" /><path d="m8.5 12.2 2.3 2.3 4.7-4.9" class="tick__mark" /></svg>
                      <span class="visually-hidden">{{ 'Verified' | t }}</span>
                    </span>
                  }
                </h3>
                <p class="creator-card__handle">&#64;{{ influencer.username }}</p>
                <p class="creator-card__meta">
                  @if (influencer.category) {
                    <span class="tag">{{ influencer.category | t }}</span>
                  }
                  @if (location(influencer); as place) {
                    <span class="creator-card__place">
                      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 21s-7-6.2-7-11.5A7 7 0 0 1 19 9.5C19 14.8 12 21 12 21z" /><circle cx="12" cy="9.5" r="2.5" /></svg>
                      {{ place }}
                    </span>
                  }
                </p>
                @if (influencer.bio) {
                  <p class="creator-card__bio">{{ influencer.bio }}</p>
                }
                @if (influencer.data_source === 'self_reported') {
                  <span class="badge badge--warn" [title]="'Numbers entered by the creator, not verified' | t">{{ 'Self-reported' | t }}</span>
                }
              </div>

              <dl class="creator-card__stats">
                <div><dt>{{ 'Followers' | t }}</dt><dd [class.na]="influencer.followers === null">{{ influencer.followers | metric }}</dd></div>
                <div><dt>{{ 'Engagement' | t }}</dt><dd [class.na]="influencer.engagement_rate === null">{{ influencer.engagement_rate | metric: 'percent' }}</dd></div>
                <div><dt>{{ 'Avg. likes' | t }}</dt><dd [class.na]="influencer.avg_likes === null">{{ influencer.avg_likes | metric }}</dd></div>
              </dl>

              <a class="btn btn--block creator-card__cta" [routerLink]="['/influencers', influencer.id]">{{ 'View profile' | t }}</a>
            </li>
          }
        </ul>
      } @else {
        <div class="card table-wrap">
          <table>
            <thead>
              <tr>
                <th scope="col"><span class="visually-hidden">{{ 'Select to compare' | t }}</span></th>
                <th scope="col">{{ 'Influencer' | t }}</th>
                <th scope="col">{{ 'Platform' | t }}</th>
                <th scope="col">{{ 'Category' | t }}</th>
                <th scope="col">{{ 'Location' | t }}</th>
                <th scope="col" class="num">{{ 'Followers' | t }}</th>
                <th scope="col" class="num">{{ 'Engagement' | t }}</th>
                <th scope="col" class="num">{{ 'Avg. likes' | t }}</th>
              </tr>
            </thead>
            <tbody>
              @for (influencer of results(); track influencer.id) {
                <tr [class.is-selected]="compare.has(influencer.id)">
                  <td class="check">
                    <input type="checkbox" [checked]="compare.has(influencer.id)"
                           [disabled]="compare.full() && !compare.has(influencer.id)"
                           (change)="compare.toggle(influencer.id)"
                           [attr.aria-label]="'Select {name} to compare' | t: { name: influencer.name }" />
                  </td>
                  <td>
                    <a class="person" [routerLink]="['/influencers', influencer.id]">
                      <app-influencer-avatar [name]="influencer.name" [src]="influencer.avatar_url" [platform]="influencer.platform" size="sm" />
                      <span>
                        <span class="person__name">{{ influencer.name }}</span>
                        <span class="person__handle">&#64;{{ influencer.username }}</span>
                      </span>
                    </a>
                    @if (influencer.is_verified) {
                      <span class="badge">{{ 'Verified' | t }}</span>
                    }
                    @if (influencer.data_source === 'self_reported') {
                      <span class="badge badge--warn" [title]="'Numbers entered by the creator, not verified' | t">{{ 'Self-reported' | t }}</span>
                    }
                  </td>
                  <td><span class="platform platform--{{ influencer.platform }}">{{ platformLabels[influencer.platform] }}</span></td>
                  <td>{{ (influencer.category ?? '–') | t }}</td>
                  <td>{{ location(influencer) || '–' }}</td>
                  <td class="num" [class.na]="influencer.followers === null">{{ influencer.followers | metric }}</td>
                  <td class="num" [class.na]="influencer.engagement_rate === null">{{ influencer.engagement_rate | metric: 'percent' }}</td>
                  <td class="num" [class.na]="influencer.avg_likes === null">{{ influencer.avg_likes | metric }}</td>
                </tr>
              }
            </tbody>
          </table>
        </div>

        @if (page(); as p) {
          @if (p.meta.last_page > 1) {
            <nav class="pager" [attr.aria-label]="'Result pages' | t">
              <button class="btn" type="button" (click)="goTo(p.meta.current_page - 1)" [disabled]="p.meta.current_page <= 1">{{ 'Previous' | t }}</button>
              <span>{{ 'Page {current} of {last}' | t: { current: p.meta.current_page, last: p.meta.last_page } }}</span>
              <button class="btn" type="button" (click)="goTo(p.meta.current_page + 1)" [disabled]="p.meta.current_page >= p.meta.last_page">{{ 'Next' | t }}</button>
            </nav>
          }
        }
      }
    </section>
  `,
})
export class InfluencerSearch implements OnInit {
  private readonly api = inject(Api);
  private readonly router = inject(Router);
  private readonly route = inject(ActivatedRoute);
  protected readonly compare = inject(CompareSelection);

  protected readonly canShortlist = inject(AuthService).can('shortlist.manage');
  protected readonly limit = COMPARE_LIMIT;
  protected readonly sorts = SORTS;
  protected readonly platformLabels = PLATFORM_LABELS;
  protected readonly countryName = countryName;
  protected readonly location = locationOf;
  protected readonly cover = coverOf;
  protected readonly skeletons = [1, 2, 3, 4, 5, 6];
  protected readonly view = signal<View>(storedView());
  protected readonly moreFilters = signal(false);
  /** In-use filters hidden behind "More filters", counted so they stay visible when it is closed. */
  protected readonly activeExtraFilters = signal(0);

  protected readonly options = signal<FilterOptions>({ platforms: ['instagram', 'youtube', 'facebook'], categories: [], countries: [] });
  protected readonly page = signal<Paginated<Influencer> | null>(null);
  protected readonly results = computed(() => this.page()?.data ?? []);
  protected readonly isSample = computed(() => this.results().some((i) => i.data_source === 'sample'));
  protected readonly loading = signal(true);
  protected readonly error = signal('');
  protected readonly sortValue = signal(SORTS[0].value);

  private query: InfluencerQuery = {};

  protected readonly form = inject(NonNullableFormBuilder).group({
    q: [''],
    platform: [''],
    category: [''],
    country: [''],
    followers_min: [''],
    followers_max: [''],
    engagement_min: [''],
    engagement_max: [''],
    verified: [''],
  });

  constructor() {
    // The URL is the source of truth, so Back, reload and shared links all work.
    this.route.queryParamMap.pipe(takeUntilDestroyed()).subscribe((params) => {
      const query: InfluencerQuery = {};
      for (const key of [...FILTER_KEYS, 'sort', 'direction', 'page'] as const) {
        const value = params.get(key);
        if (value) {
          query[key] = value;
        }
      }
      this.query = query;
      this.form.reset();
      this.form.patchValue(query);
      this.sortValue.set(query.sort ? `${query.sort}:${query.direction ?? 'desc'}` : SORTS[0].value);
      this.activeExtraFilters.set(EXTRA_FILTERS.filter((key) => query[key]).length);
      if (this.activeExtraFilters() > 0 && this.page() === null) {
        this.moreFilters.set(true); // opened from a shared link: show what is filtering the results
      }
      this.load();
    });
  }

  ngOnInit(): void {
    this.api.influencerFilters().subscribe({ next: (options) => this.options.set(options) });
  }

  protected load(): void {
    this.loading.set(true);
    this.error.set('');
    this.api.influencers(this.query).subscribe({
      next: (page) => {
        this.page.set(page);
        this.loading.set(false);
      },
      error: (err: unknown) => {
        this.error.set(messageFrom(err, 'The search could not be completed.'));
        this.loading.set(false);
      },
    });
  }

  protected apply(): void {
    this.navigate({ ...this.filtersFromForm(), ...this.sortParams() });
  }

  /** Platform and category chips search straight away, like tabs in a social feed. */
  protected pick(control: 'platform' | 'category', value: string): void {
    this.form.controls[control].setValue(value);
    this.apply();
  }

  protected setView(view: View): void {
    this.view.set(view);
    try {
      localStorage.setItem(VIEW_KEY, view);
    } catch {
      // Storage unavailable: the choice lasts for this visit only.
    }
  }

  protected reset(): void {
    this.navigate({});
  }

  protected changeSort(value: string): void {
    this.sortValue.set(value);
    this.navigate({ ...this.filtersFromForm(), ...this.sortParams() });
  }

  protected goTo(page: number): void {
    this.navigate({ ...this.query, page: String(page) });
  }

  private filtersFromForm(): InfluencerQuery {
    const query: InfluencerQuery = {};
    const value = this.form.getRawValue();
    for (const key of FILTER_KEYS) {
      const text = String(value[key] ?? '').trim();
      if (text) {
        query[key] = text;
      }
    }
    return query;
  }

  private sortParams(): InfluencerQuery {
    if (this.sortValue() === SORTS[0].value) {
      return {};
    }
    const [sort, direction] = this.sortValue().split(':');
    return { sort, direction };
  }

  private navigate(queryParams: InfluencerQuery): void {
    void this.router.navigate([], { relativeTo: this.route, queryParams });
  }
}
