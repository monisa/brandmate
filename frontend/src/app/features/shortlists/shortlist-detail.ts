import { Component, OnInit, computed, inject, input, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { AuthService } from '../../core/auth/auth.service';
import { messageFrom } from '../../core/forms';
import { Shortlist, ShortlistItem } from '../../core/models';
import { Api } from '../../core/services/api';
import { MetricPipe } from '../../shared/pipes/metric.pipe';
import { COMPARE_LIMIT } from '../influencers/compare-selection';
import { PLATFORM_LABELS } from '../influencers/labels';
import { I18n } from '../../core/i18n/i18n';
import { TPipe } from '../../core/i18n/t.pipe';
import { InfluencerAvatar } from '../../shared/components/influencer-avatar';

@Component({
  selector: 'app-shortlist-detail',
  imports: [TPipe, RouterLink, MetricPipe, InfluencerAvatar],
  template: `
    <p class="crumbs"><a routerLink="/shortlists">{{ 'Shortlists' | t }}</a> / {{ shortlist()?.name ?? ('Shortlist' | t) }}</p>

    @if (loading()) {
      <p class="muted" role="status">{{ 'Loading shortlist…' | t }}</p>
    } @else if (!shortlist()) {
      <div class="card empty">
        <p><strong>{{ 'This shortlist could not be found.' | t }}</strong></p>
        <p class="muted">{{ 'It may have been deleted by someone on your team.' | t }}</p>
        <a class="btn" routerLink="/shortlists">{{ 'Back to shortlists' | t }}</a>
      </div>
    } @else {
      <header class="page-head page-head--row">
        <div>
          <h1>{{ shortlist()!.name }}</h1>
          <p class="muted">{{ items().length }} {{ (items().length === 1 ? 'influencer' : 'influencers') | t }}</p>
        </div>
        @if (items().length >= 2) {
          <a class="btn btn--primary" routerLink="/influencers/compare" [queryParams]="{ ids: compareIds() }">
            {{ (items().length > limit ? 'Compare first {n}' : 'Compare all {n}') | t: { n: items().length > limit ? limit : items().length } }}
          </a>
        }
      </header>

      @if (notice()) {
        <p class="alert alert--success" role="status">{{ notice() | t }}</p>
      }
      @if (error()) {
        <p class="alert alert--error" role="alert">{{ error() | t }}</p>
      }

      <section class="card">
        @if (items().length === 0) {
          <div class="empty">
            <p><strong>{{ 'This shortlist is empty.' | t }}</strong></p>
            <p class="muted">{{ 'Tick influencers in the search results and add them here.' | t }}</p>
            <a class="btn btn--primary" routerLink="/influencers">{{ 'Find influencers' | t }}</a>
          </div>
        } @else {
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th scope="col">{{ 'Influencer' | t }}</th>
                  <th scope="col">{{ 'Platform' | t }}</th>
                  <th scope="col" class="num">{{ 'Followers' | t }}</th>
                  <th scope="col" class="num">{{ 'Engagement' | t }}</th>
                  <th scope="col">{{ 'Notes' | t }}</th>
                  @if (canManage) {
                    <th scope="col"><span class="visually-hidden">{{ 'Actions' | t }}</span></th>
                  }
                </tr>
              </thead>
              <tbody>
                @for (item of items(); track item.influencer.id) {
                  <tr>
                    <td>
                      <a class="person" [routerLink]="['/influencers', item.influencer.id]">
                        <app-influencer-avatar [name]="item.influencer.name" [src]="item.influencer.avatar_url" size="sm" />
                        <span>
                          <span class="person__name">{{ item.influencer.name }}</span>
                          <span class="person__handle">&#64;{{ item.influencer.username }}</span>
                        </span>
                      </a>
                    </td>
                    <td><span class="platform platform--{{ item.influencer.platform }}">{{ platformLabels[item.influencer.platform] }}</span></td>
                    <td class="num" [class.na]="item.influencer.followers === null">{{ item.influencer.followers | metric }}</td>
                    <td class="num" [class.na]="item.influencer.engagement_rate === null">{{ item.influencer.engagement_rate | metric: 'percent' }}</td>
                    <td class="notes">
                      @if (canManage) {
                        <label class="visually-hidden" [for]="'notes-' + item.influencer.id">{{ 'Notes about {name}' | t: { name: item.influencer.name } }}</label>
                        <textarea [id]="'notes-' + item.influencer.id" rows="2" maxlength="2000" #notes
                                  [value]="item.notes ?? ''" [placeholder]="'Add a note for your team' | t"></textarea>
                        <button class="btn" type="button" (click)="saveNotes(item, notes.value)">{{ 'Save note' | t }}</button>
                      } @else {
                        {{ item.notes || '–' }}
                      }
                    </td>
                    @if (canManage) {
                      <td class="actions">
                        <button class="btn btn--quiet" type="button" (click)="remove(item)">{{ 'Remove' | t }}</button>
                      </td>
                    }
                  </tr>
                }
              </tbody>
            </table>
          </div>
        }
      </section>
    }
  `,
})
export class ShortlistDetail implements OnInit {
  private readonly i18n = inject(I18n);
  private readonly api = inject(Api);

  /** Route parameter, bound by the router. */
  readonly id = input.required<string>();

  protected readonly canManage = inject(AuthService).can('shortlist.manage');
  protected readonly limit = COMPARE_LIMIT;
  protected readonly platformLabels = PLATFORM_LABELS;

  protected readonly shortlist = signal<Shortlist | null>(null);
  protected readonly items = computed(() => this.shortlist()?.items ?? []);
  protected readonly compareIds = computed(() => this.items().slice(0, COMPARE_LIMIT).map((item) => item.influencer.id).join(','));
  protected readonly loading = signal(true);
  protected readonly notice = signal('');
  protected readonly error = signal('');

  ngOnInit(): void {
    this.api.shortlist(Number(this.id())).subscribe({
      next: (shortlist) => {
        this.shortlist.set(shortlist);
        this.loading.set(false);
      },
      error: () => this.loading.set(false),
    });
  }

  protected saveNotes(item: ShortlistItem, notes: string): void {
    this.say('', '');
    this.api.saveShortlistNotes(Number(this.id()), item.influencer.id, notes.trim()).subscribe({
      next: () => {
        this.updateItems((items) => items.map((other) => (other === item ? { ...item, notes: notes.trim() || null } : other)));
        this.say(this.i18n.t('Note saved for {name}.', { name: item.influencer.name }), '');
      },
      error: (err: unknown) => this.say('', messageFrom(err, 'The note could not be saved.')),
    });
  }

  protected remove(item: ShortlistItem): void {
    this.say('', '');
    this.api.removeFromShortlist(Number(this.id()), item.influencer.id).subscribe({
      next: () => {
        this.updateItems((items) => items.filter((other) => other !== item));
        this.say(this.i18n.t('{name} removed from this shortlist.', { name: item.influencer.name }), '');
      },
      error: (err: unknown) => this.say('', messageFrom(err, 'The influencer could not be removed.')),
    });
  }

  private updateItems(change: (items: ShortlistItem[]) => ShortlistItem[]): void {
    this.shortlist.update((shortlist) => (shortlist ? { ...shortlist, items: change(shortlist.items ?? []) } : shortlist));
  }

  private say(notice: string, error: string): void {
    this.notice.set(notice);
    this.error.set(error);
  }
}
